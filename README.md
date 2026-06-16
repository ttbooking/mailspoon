# Mailspoon

[![tests](https://github.com/ttbooking/mailspoon/actions/workflows/tests.yml/badge.svg)](https://github.com/ttbooking/mailspoon/actions/workflows/tests.yml)
[![Latest Stable Version](https://img.shields.io/packagist/v/ttbooking/mailspoon)](https://packagist.org/packages/ttbooking/mailspoon)
[![License](https://img.shields.io/packagist/l/ttbooking/mailspoon)](LICENSE)

**Простое реле IMAP → HTTP-вебхук, совместимое с Mailgun. Пакет для Laravel.**

Mailspoon подключается к обычному IMAP-ящику, следит за появлением новых писем и
пересылает каждое входящее письмо на HTTP-эндпоинт, используя **тот же формат
данных и схему подписи, что и входящие вебхуки Mailgun**. Это позволяет
продолжать обрабатывать почту привычным Mailgun-эндпоинтом (например,
[`laravel-mailbox`](https://github.com/beyondcode/laravel-mailbox)), даже когда
письма приходят по обычному IMAP, а не через Mailgun.

Устанавливается composer-пакетом в любое приложение
[Laravel 13](https://laravel.com); чтение почты — на базе
[ImapEngine](https://github.com/DirectoryTree/ImapEngine)
(`directorytree/imapengine-laravel`).

## Как это работает

Mailspoon работает по схеме **store-and-forward**: чтение ящика отделено от
доставки вебхука, поэтому медленный или недоступный эндпоинт не блокирует
однопоточное чтение почты.

```
IMAP-ящик ──(mailspoon:pull / mailspoon:sentry)──▶ событие MessageReceived
       │
       └─▶ StoreIncomingMessage: архивирует сырой MIME + создаёт запись (pending)
                   │
                   └─▶ письмо сразу помечается прочитанным (\Seen)

mailspoon:deliver (отдельно, по планировщику)
       │
       └─▶ берёт pending из хранилища ──POST (body-mime + подпись Mailgun)──▶ ваш эндпоинт
                   │
                   └─▶ статус delivered, либо failed (повтор на следующем запуске)
```

1. Команда забирает **непрочитанные** письма из папки ящика (по умолчанию INBOX)
   и на каждое диспатчит событие `MessageReceived` из `ImapEngine`.
2. Слушатель `StoreIncomingMessage` сохраняет сырой MIME в хранилище, создаёт
   запись о письме со статусом `pending` и **сразу** помечает письмо
   прочитанным — приём надёжно зафиксирован локально.
3. Команда `mailspoon:deliver` независимо разбирает `pending`-записи и шлёт POST
   на эндпоинт. Успех → `delivered`; ошибка → `attempts++` и `failed`, письмо
   переотправится на следующем запуске (до `MAILSPOON_MAX_ATTEMPTS`).

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
| `signature`  | `HMAC-SHA256(timestamp + token, MAILSPOON_KEY)` — проверяется на стороне получателя. |

Проверяйте подпись на своей стороне так же, как для Mailgun:
`hash_hmac('sha256', $timestamp . $token, $signingKey)`.

Помимо полей формы запрос несёт служебные HTTP-заголовки:

| Заголовок                | Описание                                                                  |
| ------------------------ | ------------------------------------------------------------------------- |
| `X-Mailspoon-Message-Id` | `Message-Id` письма (отсутствует, если у письма нет этого заголовка).      |
| `X-Mailspoon-Attempt`    | Номер попытки доставки этой записи, начиная с 1.                           |

**Доставка — at-least-once.** Успехом считается полученный ответ 2xx: если
эндпоинт обработал запрос, но ответ потерялся (таймаут, обрыв), письмо будет
отправлено повторно. Обработчики на принимающей стороне должны быть
идемпотентными — заголовок `X-Mailspoon-Message-Id` позволяет отбросить
дубликат ещё до разбора MIME.

## Требования

- PHP 8.3+
- Приложение Laravel 13 (хост)
- IMAP-ящик
- HTTP-эндпоинт для приёма пересылаемых писем
- База данных — хранит записи о письмах и статус доставки
- Диск хранилища (`config/filesystems.php`) с `'throw' => true` — для архива
  сырого MIME

## Установка

```bash
composer require ttbooking/mailspoon

# конфиг Mailspoon → config/mailspoon.php
php artisan vendor:publish --tag=mailspoon-config

# конфиг IMAP-подключений → config/imap.php
php artisan vendor:publish --provider="DirectoryTree\ImapEngine\Laravel\ImapServiceProvider"

php artisan migrate
```

Миграции пакета применяются автоматически; при желании их можно скопировать в
приложение: `php artisan vendor:publish --tag=mailspoon-migrations`.

### Диск архива: обязателен `'throw' => true`

Архив `.eml` — единственная копия письма после пометки прочитанным, поэтому
ошибки записи/чтения/удаления не должны подавляться Flysystem. Mailspoon
**отказывается работать** с диском, у которого `'throw' => false` (значение по
умолчанию в свежем Laravel). Включите его для выбранного диска в
`config/filesystems.php`:

```php
'local' => [
    'driver' => 'local',
    'root' => storage_path('app/private'),
    'serve' => true,
    'throw' => true,
],
```

## Конфигурация

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

### Адрес пересылки (`config/mailspoon.php`)

```dotenv
MAILSPOON_ENDPOINT=https://example.com/laravel-mailbox/mailgun/mime
MAILSPOON_KEY=key-55c5c5c5c55f55ca5cd5f55d5c555c55
```

- `MAILSPOON_ENDPOINT` — URL, который принимает пересылаемые письма.
- `MAILSPOON_KEY` — общий секрет для подписи каждого запроса.

### Маршрутизация ящиков (`config/mailspoon.php`)

Каждому ящику можно назначить собственный эндпоинт и ключ подписи — карта
`routes` в опубликованном конфиге, ключ — имя ящика из `config/imap.php`:

```php
'routes' => [
    'support' => [
        'endpoint' => 'https://support.example.com/api/mailgun/mime',
        'key' => 'key-support',

        // Необязательно: маркер просмотра и фильтры конкретно для этого
        // ящика — переопределяют глобальные `mark` и `filters` (см. ниже).
        'mark' => 'keyword:Mailspoon',
        'filters' => ['allow' => ['subject' => ['/invoice/i']]],
    ],
    'billing' => [
        'endpoint' => 'https://billing.example.com/api/mailgun/mime',
        'key' => 'key-billing',
    ],
    'archive-mailbox' => [
        // Приостановить ящик, не удаляя его настройки.
        'enabled' => false,
    ],
],
```

Опции маршрута: `endpoint` и `key` (откат на глобальные), `mark` (маркер
просмотра, см. «Ящики-люди»), `filters` (заменяют глобальные целиком, см.
«Фильтрация писем») и `enabled` (см. «Приостановка ящика»).

Ящик без маршрута (или с частичным маршрутом) использует глобальные
`MAILSPOON_ENDPOINT`/`MAILSPOON_KEY`; если маршруты заданы для всех ящиков,
глобальные значения можно не задавать вовсе — `mailspoon:doctor` проверяет,
что каждый ящик резолвится хоть во что-то. Эндпоинт фиксируется в записи в момент
захвата письма, а ключ подписи выбирается в момент доставки — поэтому ротация
ключа действует и на ещё не доставленные письма, а смена эндпоинта — только на
новые.

### Приостановка ящика (`enabled`)

Чтобы временно остановить или отложить чтение конкретного ящика, не удаляя его
настройки, задайте в маршруте `'enabled' => false`:

```php
'routes' => [
    'legacy-support' => [
        'enabled' => false,
    ],
],
```

Это касается только захвата: `mailspoon:pull` сразу завершается, не подключаясь
к IMAP и не двигая UID-курсор; `mailspoon:sentry` не запускает ни pull, ни
IDLE-наблюдение; а cron-poll из `schedule.pull` для этого ящика не регистрируется
в планировщике. Письма, уже сохранённые в архиве, продолжают доставляться
`mailspoon:deliver` как обычно. По умолчанию ящик включён (флаг можно опустить).
Прямой запуск `imap:watch` пакета imapengine-laravel флаг не проверяет.

### Фильтрация писем (`config/mailspoon.php`)

Правила include/exclude применяются до захвата: отфильтрованное письмо
помечается просмотренным, но не попадает ни в журнал, ни в архив, ни на
эндпоинт. Карта `filters` — глобально или на маршруте (маршрутная заменяет
глобальную целиком):

```php
'filters' => [
    'allow' => [
        'subject' => ['/⚡/u'],              // регэксп (с разделителями)
        'from' => ['*@trusted.com'],        // или wildcard без учёта регистра
    ],
    'deny' => [
        'from' => ['no-reply@*', 'mailer-daemon@*'],
        'header' => ['Auto-Submitted' => 'auto-*'],
        'has_attachment' => false,
    ],
],
```

`deny` приоритетнее `allow`; пустой `allow` пропускает всё. Поля: `from`,
`subject`, `header`, `has_attachment`. Кривое правило (битый регэксп,
неизвестное поле) ловится на старте и в `mailspoon:doctor`, а не молча
пропускает письма. Проверить правила на конкретном письме до запуска чтения —
[`mailspoon:filter-test`](#mailspoonfilter-test--сухой-прогон-фильтров).

Каждое отфильтрованное письмо оставляет след: запись в логе Laravel и событие
[`MessageFiltered`](#события) — слишком строгое `allow`-правило видно по логу,
а не по тишине в журнале.

Пример: пропускать только подтверждения о прочтении (MDN) — формат
`multipart/report` с `report-type=disposition-notification`:

```php
'filters' => [
    'allow' => [
        'header' => ['Content-Type' => '/report-type=disposition-notification/i'],
    ],
],
```

### Ящики-люди: маркер просмотренного (`mark`)

По умолчанию mailspoon помечает обработанные письма прочитанными (`\Seen` —
его курсор), что годится для ящика-робота. Если ящик читают люди (общий ящик
операторов), прочитанность трогать нельзя — настройка `mark` глобально или на
маршруте:

```php
'routes' => [
    'operators' => [
        'endpoint' => 'https://crm.example.com/api/mailgun/mime',
        'key' => 'key-operators',
        'mark' => 'keyword:Mailspoon',
        'filters' => ['allow' => ['subject' => ['/⚡/u']]],
    ],
],
```

- `seen` (дефолт) — текущее поведение;
- `keyword:<имя>` — кастомный IMAP-кейворд: невидим в почтовых клиентах,
  курсор живёт на сервере; сервер должен разрешать кастомные кейворды
  (`PERMANENTFLAGS \*` — Dovecot, Gmail, Exchange умеют);
- `none` — письмо не трогается вовсе; позиция отслеживается UID-курсором в БД
  (таблица `relay_cursors`, сбрасывается при смене `UIDVALIDITY`). Курсор
  продвигает только `mailspoon:pull`; IDLE-режим (`mailspoon:sentry`) видит
  лишь новые поступления и захватывает их в журнал, но курсор не двигает —
  после рестарта pre-fetch перечитает диапазон с последнего pull, дедуп
  отбросит уже захваченное (повторной доставки не будет, только повторное
  скачивание). Для cron-poll эта оговорка не действует: там каждый запуск —
  pull.

Тонкость стыка `none` × retention: дедуп-записи журнала живут
`MAILSPOON_RETENTION_DAYS` дней. Если сервер сбросит `UIDVALIDITY` (переезд,
пересоздание папки) **после** того, как записи о старых письмах уже вычищены,
UID-курсор обнулится и эти письма будут захвачены и доставлены повторно —
дедупу не с чем их сравнить. Ситуация редкая (нужны оба события сразу), но на
ящике с `mark: none` и короткой retention стоит про неё помнить; защита на
принимающей стороне — те же идемпотентные обработчики.

Маркер ставится **всем** просмотренным письмам, включая отфильтрованные —
иначе они перечитывались бы каждым запуском; на эндпоинт уходят только
прошедшие фильтр. Первый запуск на ящике с `keyword:`/`none` просмотрит весь
ящик (а не только непрочитанное) — это сознательно: обрабатывается вся
история, совпавшая с фильтром.

### Хранилище и доставка (`config/mailspoon.php`)

```dotenv
MAILSPOON_ARCHIVE_DISK=local       # диск из config/filesystems.php для сырого MIME
MAILSPOON_ARCHIVE_PATH=mailspoon   # префикс пути внутри диска
MAILSPOON_RETENTION_DAYS=3         # срок хранения записей и MIME; 0 отключает очистку
MAILSPOON_PRUNE_CRON="0 3 * * *"   # расписание очистки при включённом retention

MAILSPOON_TIMEOUT=15               # общий таймаут запроса доставки, сек
MAILSPOON_CONNECT_TIMEOUT=3        # таймаут на TCP-handshake, сек
MAILSPOON_TRIES=3                  # быстрых in-process повторов на одну попытку
MAILSPOON_BACKOFF=60,300,900,3600  # пауза между запусками, сек, по номеру попытки
MAILSPOON_MAX_ATTEMPTS=10          # сколько попыток доставки, прежде чем сдаться
```

- `MAILSPOON_ARCHIVE_DISK` / `MAILSPOON_ARCHIVE_PATH` — куда складывается архив
  `.eml`; диск обязан иметь `'throw' => true` (см. выше).
- `MAILSPOON_RETENTION_DAYS` — сколько дней хранить завершённые записи вместе с
  `.eml`; по умолчанию `3`, значение `0` отключает автоматическую очистку.
- `MAILSPOON_PRUNE_CRON` — расписание штатной команды Laravel `model:prune`.
- `MAILSPOON_TIMEOUT` / `MAILSPOON_CONNECT_TIMEOUT` — общий таймаут запроса и
  отдельный лимит на установление TCP-соединения, чтобы зависший handshake не
  подвешивал воркер.
- `MAILSPOON_TRIES` — короткие повторы внутри одной попытки для мгновенных
  блипов (сеть, 5xx, 429); постоянные 4xx не повторяются.
- `MAILSPOON_BACKOFF` — растущая пауза между запусками `mailspoon:deliver`:
  упавшее письмо берётся повторно только после задержки, соответствующей номеру
  попытки (последнее значение применяется для всех дальнейших).
- `MAILSPOON_MAX_ATTEMPTS` — после стольких неудачных попыток письмо перестаёт
  переотправляться и остаётся в статусе `failed` для ручного разбора.

### Карты — в опубликованном конфиге

Структурные настройки (например, расписание cron-poll по ящикам) задаются
обычным PHP в `config/mailspoon.php` — без сериализации в env:

```php
'schedule' => [
    // ...
    'pull' => [
        'default' => '*/5 * * * *',
        'secondary' => '0 * * * *',
    ],
],
```

Опубликованный конфиг должен сохранять полную структуру секций: merge с
дефолтами пакета выполняется только по верхнему уровню.

### Маршруты из хранилища (`Mailspoon::register()`)

Если набор ящиков не статичен — например, приложение использует Mailspoon как
читца почты и каждый клиент регистрирует свой ящик и вебхук через UI, — маршруты
можно задавать в рантайме, не редактируя `config/mailspoon.php`. Фасад
`Mailspoon` повторяет `Imap` из imapengine: метод `register()` добавляет или
переопределяет маршрут для ящика.

```php
use TTBooking\Mailspoon\Facades\Mailspoon;

Mailspoon::register('tenant-42', [
    'endpoint' => 'https://tenant-42.example.com/api/mailgun/mime',
    'key' => 'key-42',
    'schedule' => '*/5 * * * *',   // cron-poll этого ящика (как запись schedule.pull)
    'enabled' => true,             // та же семантика, что у маршрута в конфиге
    'mark' => 'keyword:Mailspoon',
    'filters' => ['allow' => ['subject' => ['/invoice/i']]],
]);
```

Зарегистрированный маршрут имеет приоритет над одноимённым в конфиге и **заменяет
его целиком** (не сливается). Опции — те же, что в карте `routes`, плюс
необязательный `schedule`: ящик с расписанием попадает в cron-poll наравне с
записями `schedule.pull`, так что новый ящик начинает опрашиваться без правки
конфига. Семантика времени прежняя: `endpoint` фиксируется при захвате, `key`
выбирается при доставке, `mark`/`filters` — при захвате.

### Динамические IMAP-подключения (`Imap::register()`)

Маршрут описывает доставку. Чтобы pull-режим тоже работал из хранилища, ящику
нужно само IMAP-подключение: `mailspoon:pull`/`:sentry` соединяются через
`Imap::mailbox($name)`, который резолвит `config/imap.php`. Своей абстракции для
этого не нужно — у imapengine есть публичный `Imap::register($name, $config)`,
кладущий подключение в его менеджер на время процесса. Регистрируйте подключение
и маршрут рядом, в `boot()` своего сервис-провайдера:

```php
use DirectoryTree\ImapEngine\Laravel\Facades\Imap;
use TTBooking\Mailspoon\Facades\Mailspoon;

public function boot(): void
{
    // Выполняется в каждом процессе, включая фоновый mailspoon:pull из
    // планировщика, — поэтому подключение доступно и там.
    foreach (Mailbox::all() as $box) {            // ваша Eloquent-модель
        Imap::register($box->mailbox, $box->imap_config);    // подключение
        Mailspoon::register($box->mailbox, [                 // доставка + опрос
            'endpoint' => $box->endpoint,
            'key' => $box->key,
            'schedule' => $box->poll_cron,
            'enabled' => $box->enabled,
        ]);
    }
}
```

Так покрыты все точки входа: `mailspoon:pull` (в т.ч. фоновый), `mailspoon:sentry`
(регистрация в одном процессе переживает вложенные `pull` и vendor `imap:watch`)
и `mailspoon:deliver` (к IMAP не обращается).

Что учесть:

- **`boot()` выполняется на каждый процесс** — кэшируйте выборку из БД либо
  регистрируйте только под `runningInConsole()`/нужные команды, чтобы не делать
  запрос на каждый веб-реквест.
- **IMAP-пароли в БД** храните через encrypted-cast.
- **Прямой запуск vendor `imap:watch`** мимо команд Mailspoon подключение из БД
  не получит — регистрируйте его сами или держите ящик в `config/imap.php`.

## Использование

Mailspoon предоставляет команды чтения (`mailspoon:pull`, `mailspoon:sentry`)
и команду доставки (`mailspoon:deliver`). Аргумент `mailbox` — это имя ящика из
`config/imap.php` (для встроенного используйте `default`). Необязательный
аргумент `folder` выбирает папку, отличную от INBOX.

### `mailspoon:pull` — разовая проверка

Забирает все текущие непрочитанные письма, сохраняет их и завершается.

```bash
php artisan mailspoon:pull default
php artisan mailspoon:pull default "INBOX/Archive"
```

Опции:

- `--with=` — список через запятую частей письма для подгрузки. Если опция не
  задана или пуста, используются `flags,headers,body`, необходимые для
  сохранения полного сырого MIME.
- `--chunk=` — сколько писем забирать одной IMAP-командой (по умолчанию
  `MAILSPOON_PULL_CHUNK`, 100). Письма выбираются пачками от старых к новым:
  один `FETCH` с тысячами UID (большой бэклог, первый прогон с маркером
  `keyword:`/`none`) превышает лимит длины команды сервера — Dovecot отвечает
  `BAD ... Too long argument`. Для `none`-маркера UID-курсор сохраняется после
  каждой пачки, так что прерванный прогон бэклога продолжится с места
  остановки.

Подходит для запуска по расписанию (cron), когда долгоживущий процесс не нужен.

### `mailspoon:sentry` — забрать накопившееся и следить дальше

Сначала один раз выполняет `mailspoon:pull`, чтобы сохранить накопившиеся
письма, затем начинает следить за ящиком в реальном времени (через IMAP IDLE) и
сохраняет письма по мере поступления. Это рекомендуемый способ запускать
Mailspoon как постоянный воркер.

```bash
php artisan mailspoon:sentry default
```

Опции:

- `--method=idle` — метод слежения (по умолчанию `idle`).
- `--with=` — части письма для подгрузки (по умолчанию `flags,headers,body`).
- `--timeout=30` — таймаут IDLE в секундах.
- `--attempts=5` — число попыток переподключения.
- `--debug=false` — включить отладочный вывод.

Запускайте под супервизором процессов (systemd, Supervisor и т. п.), чтобы он
перезапускался автоматически:

```ini
[program:mailspoon]
command=php /path/to/app/artisan mailspoon:sentry default
autostart=true
autorestart=true
```

> Команда `imap:watch` (только слежение, без предварительного разбора)
> предоставляется самим ImapEngine; `mailspoon:sentry` — это обёртка над
> `mailspoon:pull` + `imap:watch`.

> Команды чтения только **сохраняют** письма (архив + запись `pending`) и
> помечают их прочитанными. Сама доставка на эндпоинт выполняется отдельно —
> командой `mailspoon:deliver`.

### `mailspoon:deliver` — доставка сохранённых писем

Разбирает `pending`-записи (и ранее проваленные, у которых прошёл backoff и не
исчерпан лимит попыток), читает сырой MIME из архива и шлёт подписанный POST на
эндпоинт. Ретрай двухуровневый:

- **внутри попытки** — короткие повторы (`MAILSPOON_TRIES`) для мгновенных
  сетевых блипов и ответов 5xx/429, с ограничением таймаутов
  (`MAILSPOON_TIMEOUT`, `MAILSPOON_CONNECT_TIMEOUT`);
- **между запусками** — упавшее письмо переносится на потом через
  `next_attempt_at` по расписанию `MAILSPOON_BACKOFF`, без блокирующих пауз в
  воркере.

Так зависший или медленный эндпоинт никогда не тормозит чтение ящика.

```bash
php artisan mailspoon:deliver
php artisan mailspoon:deliver --limit=100 --max-attempts=5
php artisan mailspoon:deliver --dry-run
```

Опции:

- `--limit=50` — максимум писем за один запуск.
- `--max-attempts=` — переопределить `MAILSPOON_MAX_ATTEMPTS`.
- `--dry-run` — показать таблицей, что и куда ушло бы (эндпоинт, источник
  ключа, состояние архива), не отправляя запросов и не меняя записи.

Команда — разовая (one-shot); запускать её периодически проще всего
планировщиком (см. ниже), который уже вызывает `mailspoon:deliver` с
`withoutOverlapping()`.

### `mailspoon:replay` — переотправка писем

Сбрасывает записи журнала обратно в `pending` — фактическую отправку выполнит
ближайший запуск `mailspoon:deliver` (сырой MIME читается из архива, лезть в
ящик заново не нужно). Счётчик попыток обнуляется, так что переотправляются и
письма с исчерпанным лимитом.

```bash
php artisan mailspoon:replay "<message-id@example.com>"   # конкретные письма
php artisan mailspoon:replay --failed                     # все проваленные
php artisan mailspoon:replay --failed --mailbox=support   # только один ящик
```

Replay — явное действие оператора: дедупликация сознательно обходится, можно
переотправить и уже доставленное письмо (например, после потери данных на
стороне получателя).

### `mailspoon:doctor` — диагностика конфигурации

Проверяет всю цепочку до запуска воркера: наличие таблицы журнала, запись и
чтение на диске архива (включая обязательный `'throw' => true`), эндпоинт и
ключ каждого ящика (маршрут или глобальные), реальный IMAP-логин и доступность
эндпоинта. Печатает образец подписи для сверки ключа с получателем
(`MAILBOX_MAILGUN_KEY` у laravel-mailbox). Завершается ненулевым кодом при
любой провальной проверке — удобно как preflight в деплое.

Отдельная проверка `schedule` сообщает cron-расписание ящика. Её отсутствие —
не ошибка (ящик может читаться `mailspoon:sentry` или ручным `mailspoon:pull`),
поэтому это **предупреждение** (строка `!`), а не провал: код возврата остаётся
нулевым, в JSON-отчёте статус проверки — `warn`. Расписание проверяется и для
паузнутого маршрута (`enabled => false`): doctor — preflight, его часто гоняют
во время настройки, пока ящик ещё выключен, поэтому пауза без расписания тоже
даёт предупреждение, а не прячет недонастройку.

```bash
php artisan mailspoon:doctor                  # все ящики из config/imap.php
php artisan mailspoon:doctor support          # только указанные
php artisan mailspoon:doctor --send           # + подписанное тестовое письмо
```

По умолчанию эндпоинт только пробуется OPTIONS-запросом (без тестовой почты в
принимающее приложение); `--send` отправляет полноценное подписанное письмо с
заголовком `X-Mailspoon-Doctor: true` и требует ответа 2xx.

### `mailspoon:filter-test` — сухой прогон фильтров

Прогоняет письмо через `filters` ящика **без захвата**: не ходит в журнал, не
архивирует, не помечает письмо и ничего не доставляет. Печатает вердикт и
**правило, которое его решило** — какой `deny` отбросил письмо или какой `allow`
его пропустил. Удобно при настройке правил, до запуска чтения.

```bash
php artisan mailspoon:filter-test support --file=sample.eml   # сырой MIME из файла
php artisan mailspoon:filter-test support --uid=42            # письмо по UID из ящика
```

```text
✗ FILTERED — message would be dropped (denied by from rule [no-reply@*])
```

Работает и на **выключенном** ящике (`enabled => false`): вопрос «отфильтруется
ли это письмо» одинаково валиден для маршрута, который ещё только настраивают.
Команда — тонкая обёртка над сервисом `FilterTester` (см. ниже), так что тот же
вердикт можно получить из дашборда. Матчер берётся живой — из конфига или
рантайм-`Mailspoon::register()`, — поэтому результат точно совпадает с тем, что
решит захват. `--file` ничего не требует от IMAP; `--uid` тянет письмо из ящика.

## Вызов операций из приложения (сервисы)

За командами `mailspoon:doctor`/`:replay`/`:deliver` стоят сервисы, которые
можно вызвать из своего кода (контроллера, Job'а) и получить **структурный**
результат вместо текста консоли — удобно, когда ящики и маршруты заводятся
динамически (см. «Маршруты из хранилища») и обслуживаются из веб-интерфейса.
Mailspoon HTTP-слой не шипит: контроллеры и UI строит хост, ниже — примеры.

Каждый результат реализует `Arrayable`/`JsonSerializable`, поэтому годится прямо
для `response()->json()`.

```php
use DirectoryTree\ImapEngine\FileMessage;
use TTBooking\Mailspoon\Services\Doctor;
use TTBooking\Mailspoon\Services\Replay;
use TTBooking\Mailspoon\Services\ReplayCriteria;
use TTBooking\Mailspoon\Services\Deliverer;
use TTBooking\Mailspoon\Services\FilterTester;

// Диагностика — DoctorReport со списком проверок (mailbox/name/status/message).
$report = app(Doctor::class)->run(['support']);   // пусто = все ящики; ->run([], send: true) — с подписанным письмом
return response()->json($report);                  // { "ok": false, "checks": [ ... ] }

// Переотправка — ReplayResult { count, messages: [...] }.
$result = app(Replay::class)->run(new ReplayCriteria(failed: true, mailbox: 'support'));
// Пустой критерий (ни id, ни failed) бросает InvalidArgumentException — мапьте в 422.

// Принудительный флаш — DeliverySummary { delivered, failed, total }.
$summary = app(Deliverer::class)->run(limit: 50);

// Сухой прогон фильтров — FilterTestResult { passes, decision, field, pattern, reason }.
// Принимает любой MessageInterface; для загруженного .eml — FileMessage, без IMAP.
$verdict = app(FilterTester::class)->test('support', new FileMessage($request->getContent()));
return response()->json($verdict);                 // { "passes": false, "decision": "denied_by_rule", ... }
// Работает и для выключенного ящика; малформленное правило бросает InvalidArgumentException.
```

На что обратить внимание:

- **`Doctor` и `Deliverer` делают сетевые операции** (IMAP-логин, HTTP-проба или
  доставка) синхронно и с таймаутами — в вебе запускайте их через очередь/Job, а
  не в реквест-цикле.
- **`Replay` и `Deliverer` меняют данные** (сбрасывают/доставляют записи) —
  авторизацию таких действий обеспечивает хост.
- **`FilterTester` ничего не делает** — чистая оценка без IMAP, БД и сети, без
  побочных эффектов, — поэтому его безопасно звать прямо в реквест-цикле.

## События

Реле остаётся «тупой трубой»: оно не шлёт уведомлений и не строит метрик, но
объявляет хост-приложению о двух ситуациях, которые иначе остались бы
незамеченными. Оба события дублируются записью в лог Laravel, так что минимум
наблюдаемости есть и без слушателей.

- **`TTBooking\Mailspoon\Events\MessageFiltered`** — письмо отклонено
  правилами `filters` (свойства: `message`, `mailbox`). Отфильтрованное письмо
  помечается просмотренным, но не попадает ни в журнал, ни в архив — событие
  и лог-запись (`info`) — его единственный след.
- **`TTBooking\Mailspoon\Events\DeliveryPermanentlyFailed`** — письмо
  исчерпало `MAILSPOON_MAX_ATTEMPTS` и больше не будет переотправляться
  (свойство: `message` — модель `RelayedMessage`). Запись остаётся в журнале
  со статусом `failed` до ручного `mailspoon:replay`; лог-запись — `error`.

Подписка — штатными средствами Laravel, например уведомление о застрявшем
письме:

```php
use Illuminate\Support\Facades\Event;
use TTBooking\Mailspoon\Events\DeliveryPermanentlyFailed;

Event::listen(function (DeliveryPermanentlyFailed $event) {
    Notification::route('slack', config('services.slack.ops'))
        ->notify(new RelayStuckNotification($event->message));
});
```

## Запуск и расписание

Mailspoon регистрирует свои задачи в планировщике хост-приложения. Если
системный cron для `schedule:run` ещё не настроен, добавьте одну строку:

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

Что именно планируется, задаётся в `config/mailspoon.php` → `schedule`
(все задачи — с `withoutOverlapping()`):

- **`mailspoon:deliver`** — включён по умолчанию (`MAILSPOON_DELIVER_CRON`,
  по умолчанию каждую минуту). Нужен в любом режиме, поскольку чтение только
  **сохраняет** письма. Чтобы отключить — задайте `MAILSPOON_DELIVER_CRON`
  пустым.
- **`mailspoon:pull` по ящикам** — карта `имя ящика => cron` в опубликованном
  конфиге (ключ `schedule.pull`), по умолчанию пуста.
- **Очистка журнала и архива** — по умолчанию включена с retention 3 дня.
  При `MAILSPOON_RETENTION_DAYS > 0` запускается `model:prune` по расписанию
  `MAILSPOON_PRUNE_CRON` (по умолчанию ежедневно в 03:00). Запись
  `relayed_messages` удаляется только вместе со связанным `.eml`. Очищаются
  только успешно доставленные письма; записи `pending` и `failed` сохраняются
  для повторной доставки и ручного разбора.

Отсюда два режима эксплуатации:

| Режим | Чтение | Демон / supervisor | Латентность |
| ----- | ------ | ------------------ | ----------- |
| **Cron-poll** | `mailspoon:pull` по карте `schedule.pull` | не нужен | = интервал cron |
| **Realtime** | `mailspoon:sentry` (IMAP IDLE) под supervisor | нужен для watcher | секунды |

В обоих режимах доставку выполняет запланированный `mailspoon:deliver` —
отдельный демон или очередь для неё не требуются.

## Связка с Laravel Mailbox

Mailspoon отлично сочетается с
[`beyondcode/laravel-mailbox`](https://github.com/beyondcode/laravel-mailbox).
Поскольку Mailspoon шлёт запрос в точности так же, как входящий MIME-вебхук
Mailgun, приложение может принимать пересылаемые письма штатным
mailgun-драйвером Laravel Mailbox — никакого кастомного кода для приёма не
требуется. Mailspoon можно установить как в отдельное приложение-реле, так и
**прямо в приложение с Laravel Mailbox** — тогда оно само читает свой ящик и
шлёт вебхук на собственный эндпоинт.

В приложении-получателе с установленным Laravel Mailbox:

```dotenv
MAILBOX_DRIVER=mailgun
MAILBOX_MAILGUN_KEY=key-55c5c5c5c55f55ca5cd5f55d5c555c55
```

а в Mailspoon направьте реле на его эндпоинт и используйте **тот же ключ**,
чтобы подписи совпадали:

```dotenv
MAILSPOON_ENDPOINT=https://your-app.com/laravel-mailbox/mailgun/mime
# MAILSPOON_KEY должен совпадать с MAILBOX_MAILGUN_KEY
MAILSPOON_KEY=key-55c5c5c5c55f55ca5cd5f55d5c555c55
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

Mailspoon распространяется по лицензии [MIT](LICENSE).
