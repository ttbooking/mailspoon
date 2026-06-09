# Mailspoon

**Простое реле IMAP → HTTP-вебхук, совместимое с Mailgun.**

Mailspoon подключается к обычному IMAP-ящику, следит за появлением новых писем и
пересылает каждое входящее письмо на HTTP-эндпоинт, используя **тот же формат
данных и схему подписи, что и входящие вебхуки Mailgun**. Это позволяет
продолжать обрабатывать почту привычным Mailgun-эндпоинтом (например,
[`laravel-mailbox`](https://github.com/beyondcode/laravel-mailbox)), даже когда
письма приходят по обычному IMAP, а не через Mailgun.

Построено на [Laravel 13](https://laravel.com) и
[ImapEngine](https://github.com/DirectoryTree/ImapEngine)
(`directorytree/imapengine-laravel`).

## Как это работает

Mailspoon работает по схеме **store-and-forward**: чтение ящика отделено от
доставки вебхука, поэтому медленный или недоступный эндпоинт не блокирует
однопоточное чтение почты.

```
IMAP-ящик ──(imap:pull / imap:sentry)──▶ событие MessageReceived
       │
       └─▶ StoreIncomingMessage: архивирует сырой MIME + создаёт запись (pending)
                   │
                   └─▶ письмо сразу помечается прочитанным (\Seen)

spoon:deliver (отдельно, по cron/в цикле)
       │
       └─▶ берёт pending из хранилища ──POST (body-mime + подпись Mailgun)──▶ ваш эндпоинт
                   │
                   └─▶ статус delivered, либо failed (повтор на следующем запуске)
```

1. Команда забирает **непрочитанные** письма из папки ящика (по умолчанию INBOX)
   и на каждое диспатчит событие `MessageReceived` из `ImapEngine`.
2. Слушатель `App\Listeners\StoreIncomingMessage` сохраняет сырой MIME в
   хранилище, создаёт запись о письме со статусом `pending` и **сразу** помечает
   письмо прочитанным — приём надёжно зафиксирован локально.
3. Команда `spoon:deliver` независимо разбирает `pending`-записи и шлёт POST на
   эндпоинт. Успех → `delivered`; ошибка → `attempts++` и `failed`, письмо
   переотправится на следующем запуске (до `SPOON_MAX_ATTEMPTS`).

Дедупликация по `Message-Id` (или хешу письма, если заголовка нет) исключает
повторную обработку одного и того же сообщения.

### Содержимое вебхука

Запрос отправляется как `application/x-www-form-urlencoded` и содержит
следующие поля, повторяющие входящий MIME-вебхук Mailgun:

| Поле         | Описание                                                                     |
| ------------ | ---------------------------------------------------------------------------- |
| `body-mime`  | Полный исходный MIME-текст письма.                                            |
| `timestamp`  | Unix-метка момента отправки вебхука.                                          |
| `token`      | Случайный hex-токен длиной 50 символов, уникальный для каждого запроса.       |
| `signature`  | `HMAC-SHA256(timestamp + token, SPOON_KEY)` — проверяется на стороне получателя. |

Проверяйте подпись на своей стороне так же, как для Mailgun:
`hash_hmac('sha256', $timestamp . $token, $signingKey)`.

## Требования

- PHP 8.3+
- IMAP-ящик
- HTTP-эндпоинт для приёма пересылаемых писем
- База данных (по умолчанию SQLite) — хранит записи о письмах и статус доставки
- Диск хранилища (`config/filesystems.php`) — для архива сырого MIME

## Установка

```bash
git clone <repo-url> mailspoon
cd mailspoon

composer install
cp .env.example .env
php artisan key:generate

# создать БД (для SQLite) и применить миграции
touch database/database.sqlite
php artisan migrate
```

Затем настройте IMAP-подключение и адрес для пересылки в `.env` (см. ниже).

## Конфигурация

Все настройки задаются через переменные окружения.

### IMAP-подключение (`config/imap.php`)

```dotenv
IMAP_HOST=imap.example.com
IMAP_PORT=993
IMAP_USERNAME=your-username
IMAP_PASSWORD=your-password
IMAP_ENCRYPTION=ssl          # ssl | tls | starttls | false
```

Дополнительные необязательные переменные: `IMAP_TIMEOUT`, `IMAP_DEBUG`,
`IMAP_VALIDATE_CERT`, `IMAP_AUTHENTICATION`, а также настройки прокси
(`IMAP_PROXY_SOCKET`, `IMAP_PROXY_USERNAME`, `IMAP_PROXY_PASSWORD`,
`IMAP_PROXY_REQUEST_FULLURI`).

В `config/imap.php` под ключом `mailboxes` можно описать несколько ящиков;
встроенный называется `default`.

### Адрес пересылки (`config/spoon.php`)

```dotenv
SPOON_ENDPOINT=https://example.com/laravel-mailbox/mailgun/mime
SPOON_KEY=key-55c5c5c5c55f55ca5cd5f55d5c555c55
```

- `SPOON_ENDPOINT` — URL, который принимает пересылаемые письма.
- `SPOON_KEY` — общий секрет для подписи каждого запроса.

### Хранилище и доставка (`config/spoon.php`)

```dotenv
SPOON_ARCHIVE_DISK=local      # диск из config/filesystems.php для сырого MIME
SPOON_ARCHIVE_PATH=mailspoon  # префикс пути внутри диска
SPOON_MAX_ATTEMPTS=10         # сколько раз пытаться доставить, прежде чем сдаться
```

- `SPOON_ARCHIVE_DISK` / `SPOON_ARCHIVE_PATH` — куда складывается архив `.eml`.
- `SPOON_MAX_ATTEMPTS` — после стольких неудачных попыток письмо перестаёт
  переотправляться и остаётся в статусе `failed` для ручного разбора.

## Использование

Mailspoon предоставляет команды чтения (`imap:*`) и команду доставки
(`spoon:deliver`). Аргумент `mailbox` — это имя ящика из `config/imap.php` (для
встроенного используйте `default`). Необязательный аргумент `folder` выбирает
папку, отличную от INBOX.

### `imap:pull` — разовая проверка

Забирает все текущие непрочитанные письма, пересылает их и завершается.

```bash
php artisan imap:pull default
php artisan imap:pull default "INBOX/Archive"
```

Опции:

- `--with=` — список через запятую дополнительных частей письма для подгрузки.

Подходит для запуска по расписанию (cron), когда долгоживущий процесс не нужен.

### `imap:sentry` — забрать накопившееся и следить дальше

Сначала один раз выполняет `imap:pull`, чтобы переслать накопившиеся письма,
затем начинает следить за ящиком в реальном времени (через IMAP IDLE) и
пересылает письма по мере поступления. Это рекомендуемый способ запускать
Mailspoon как постоянный воркер.

```bash
php artisan imap:sentry default
```

Опции:

- `--method=idle` — метод слежения (по умолчанию `idle`).
- `--with=` — дополнительные части письма для подгрузки.
- `--timeout=30` — таймаут IDLE в секундах.
- `--attempts=5` — число попыток переподключения.
- `--debug=false` — включить отладочный вывод.

Запускайте под супервизором процессов (systemd, Supervisor и т. п.), чтобы он
перезапускался автоматически:

```ini
[program:mailspoon]
command=php /path/to/mailspoon/artisan imap:sentry default
autostart=true
autorestart=true
```

### `imap:watch` — только слежение

Предоставляется ImapEngine; следит за новыми письмами без предварительного
разбора накопившегося. `imap:sentry` — это удобная обёртка над
`imap:pull` + `imap:watch`.

> Команды `imap:*` теперь только **сохраняют** письма (архив + запись `pending`)
> и помечают их прочитанными. Сама доставка на эндпоинт выполняется отдельно —
> командой `spoon:deliver`.

### `spoon:deliver` — доставка сохранённых писем

Разбирает `pending`-записи (и ранее проваленные, пока не исчерпан лимит попыток),
читает сырой MIME из архива и шлёт подписанный POST на эндпоинт. За один запуск —
одна попытка на письмо; провалившиеся переотправятся на следующем запуске, так
что зависший эндпоинт никогда не тормозит чтение ящика.

```bash
php artisan spoon:deliver
php artisan spoon:deliver --limit=100 --max-attempts=5
```

Опции:

- `--limit=50` — максимум писем за один запуск.
- `--max-attempts=` — переопределить `SPOON_MAX_ATTEMPTS`.

Запускайте периодически — через системный cron или планировщик Laravel:

```cron
* * * * * cd /path/to/mailspoon && php artisan spoon:deliver >> /dev/null 2>&1
```

## Связка с Laravel Mailbox

Mailspoon отлично сочетается с
[`beyondcode/laravel-mailbox`](https://github.com/beyondcode/laravel-mailbox).
Поскольку Mailspoon шлёт запрос в точности так же, как входящий MIME-вебхук
Mailgun, ваше приложение может принимать пересылаемые письма штатным
mailgun-драйвером Laravel Mailbox — никакого кастомного кода для приёма не
требуется.

В приложении-получателе с установленным Laravel Mailbox:

```dotenv
MAILBOX_DRIVER=mailgun
MAILBOX_MAILGUN_KEY=key-55c5c5c5c55f55ca5cd5f55d5c555c55
```

а в Mailspoon направьте реле на его эндпоинт и используйте **тот же ключ**, чтобы
подписи совпадали:

```dotenv
SPOON_ENDPOINT=https://your-app.com/laravel-mailbox/mailgun/mime
# SPOON_KEY должен совпадать с MAILBOX_MAILGUN_KEY
SPOON_KEY=key-55c5c5c5c55f55ca5cd5f55d5c555c55
```

Дальше обрабатывайте письма как обычно через маршруты Laravel Mailbox:

```php
use BeyondCode\Mailbox\Facades\Mailbox;
use BeyondCode\Mailbox\InboundEmail;

Mailbox::from('sender@example.com', function (InboundEmail $email) {
    $subject = $email->subject();
    // ...
});
```

Итоговый поток: `IMAP-ящик → Mailspoon → вебхук Mailgun → Laravel Mailbox → ваши обработчики`.

## Лицензия

Mailspoon распространяется по лицензии
[MIT](https://opensource.org/licenses/MIT).
