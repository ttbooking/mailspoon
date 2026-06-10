# Upgrade Guide

## 2.x → 3.0

В 3.0 Mailspoon перестаёт быть standalone-приложением и становится
**composer-пакетом** `ttbooking/mailspoon`, который устанавливается в любое
приложение Laravel 13. Схема БД не меняется — перенос данных не требуется,
но меняются установка, имена команд, env-переменные и способ задания
структурных настроек.

### Сводка ломающих изменений

| Было (2.x) | Стало (3.0) |
| ---------- | ----------- |
| Standalone-приложение (git clone) | Пакет `composer require ttbooking/mailspoon` |
| `config/spoon.php`, ключ `spoon.*` | Публикуемый `config/mailspoon.php`, ключ `mailspoon.*` |
| `php artisan imap:pull` | `php artisan mailspoon:pull` |
| `php artisan imap:sentry` | `php artisan mailspoon:sentry` |
| `php artisan spoon:deliver` | `php artisan mailspoon:deliver` |
| Переменные `SPOON_*` | Переменные `MAILSPOON_*` (тот же суффикс) |
| `SPOON_PULL_SCHEDULE` (JSON в env) | Карта `schedule.pull` в опубликованном конфиге |
| Неймспейс `App\` | `TTBooking\Mailspoon\` |

Соответствие env-переменных — один к одному, меняется только префикс:
`SPOON_ENDPOINT` → `MAILSPOON_ENDPOINT`, `SPOON_KEY` → `MAILSPOON_KEY`,
`SPOON_ARCHIVE_DISK` → `MAILSPOON_ARCHIVE_DISK` и так далее для
`ARCHIVE_PATH`, `RETENTION_DAYS`, `PRUNE_CRON`, `TIMEOUT`, `CONNECT_TIMEOUT`,
`TRIES`, `BACKOFF`, `MAX_ATTEMPTS`, `DELIVER_CRON`. Исключение —
`SPOON_PULL_SCHEDULE`: env-переменной больше нет, карта переезжает в конфиг.

### Шаги обновления

**1. Подготовьте хост-приложение**

Подойдёт любое приложение Laravel 13 — существующее (например, то самое, что
принимает вебхук через Laravel Mailbox) или свежее (`laravel new mailspoon-host`)
для standalone-эксплуатации.

```bash
composer require ttbooking/mailspoon
php artisan vendor:publish --tag=mailspoon-config
php artisan vendor:publish --provider="DirectoryTree\ImapEngine\Laravel\ImapServiceProvider"
```

**2. Перенесите настройки из старого `.env`**

Скопируйте значения `IMAP_*` как есть; значения `SPOON_*` — под новыми именами
`MAILSPOON_*` (таблица выше). Если использовался `SPOON_PULL_SCHEDULE`,
перенесите карту в `config/mailspoon.php`:

```php
'schedule' => [
    // ...
    'pull' => ['default' => '*/5 * * * *'],
],
```

**3. Включите `'throw' => true` на диске архива**

В свежем Laravel диски по умолчанию имеют `'throw' => false`; Mailspoon
отказывается работать с таким диском. В `config/filesystems.php` хоста задайте
для диска из `MAILSPOON_ARCHIVE_DISK`:

```php
'throw' => true,
```

**4. Подключите существующие данные**

Схема `relayed_messages` не изменилась:

- укажите в `.env` хоста подключение к существующей БД (или перенесите файл
  SQLite в `database/` хоста);
- перенесите каталог архива (по умолчанию `storage/app/private/mailspoon`)
  в storage хоста — или настройте `MAILSPOON_ARCHIVE_DISK`/`PATH` на старое
  место.

`php artisan migrate` на существующей БД ничего не сломает: миграция уже
применена и будет пропущена.

**5. Обновите supervisor и cron**

В программе supervisor поменяйте и путь artisan, и имя команды:

```ini
[program:mailspoon]
command=php /path/to/app/artisan mailspoon:sentry default
autostart=true
autorestart=true
```

Строка cron остаётся той же идеи, меняется только путь:

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

**6. Снесите старый checkout**

После проверки (см. ниже) каталог standalone-приложения 2.x больше не нужен.

### Проверка

```bash
php artisan schedule:list   # присутствуют mailspoon:deliver (и model:prune при retention > 0)
php artisan migrate:status  # миграция relayed_messages числится применённой
php artisan mailspoon:pull default
```

После прихода письма убедитесь, что появилась запись в `relayed_messages` и её
статус меняется на `delivered`.

## 1.0 → 2.0

В 2.0 Mailspoon переходит на архитектуру **store-and-forward**: чтение ящика
отделено от доставки. Это даёт надёжную доставку и снимает блокировку чтения, но
**меняет порядок эксплуатации** — обновление требует ручных шагов.

> ⚠️ Главное: после обновления письма **сохраняются, но не доставляются**, пока
> не запущена доставка (`spoon:deliver` через планировщик). Не пропустите шаг 4.

### Что изменилось

- Появилась БД с таблицей `relayed_messages` (раньше БД не использовалась).
- `imap:pull` / `imap:sentry` теперь только **сохраняют** письма; отправку
  выполняет новая команда `spoon:deliver`.
- Доставка стала асинхронной — латентность равна периоду планировщика.
- Слушатель `NotifyNewMessage` удалён (заменён на `StoreIncomingMessage`).
- `timestamp` в подписи вебхука теперь = момент отправки, а не дата письма.

### Шаги обновления

**1. Обновите код и зависимости**

```bash
git pull
composer install --no-dev --optimize-autoloader
```

**2. Настройте базу данных**

В `.env` укажите подключение (по умолчанию подойдёт SQLite):

```dotenv
DB_CONNECTION=sqlite
```

Для SQLite создайте файл БД:

```bash
touch database/database.sqlite
```

**3. Примените миграции**

```bash
php artisan migrate
```

Создастся таблица `relayed_messages`.

**4. Включите доставку (обязательно)**

Доставку выполняет `spoon:deliver`, запускаемый планировщиком. Добавьте одну
строку в системный cron:

```cron
* * * * * cd /path/to/mailspoon && php artisan schedule:run >> /dev/null 2>&1
```

По умолчанию `spoon:deliver` запланирован каждую минуту. Без этого шага письма
будут копиться в статусе `pending` и никуда не уйдут.

**5. Проверьте новые настройки**

Добавьте при необходимости в `.env` (у всех есть разумные значения по умолчанию):

```dotenv
SPOON_ARCHIVE_DISK=local
SPOON_ARCHIVE_PATH=mailspoon
SPOON_RETENTION_DAYS=3
SPOON_PRUNE_CRON="0 3 * * *"
SPOON_PULL_SCHEDULE='{"default":"*/5 * * * *"}'
SPOON_TIMEOUT=15
SPOON_CONNECT_TIMEOUT=3
SPOON_TRIES=3
SPOON_BACKOFF=60,300,900,3600
SPOON_MAX_ATTEMPTS=10
SPOON_DELIVER_CRON="* * * * *"
```

`SPOON_RETENTION_DAYS` по умолчанию равен `3`: успешно доставленные письма
старше трёх дней удаляются вместе с архивом `.eml`. Записи `pending` и `failed`
автоматически не удаляются.
Чтобы сохранить данные бессрочно, явно задайте `SPOON_RETENTION_DAYS=0`.

**6. Выберите режим чтения**

- **Cron-poll** (без долгоживущих процессов): задайте в `.env`
  `SPOON_PULL_SCHEDULE` как JSON-объект `имя ящика => cron`. Тот же
  `schedule:run` будет периодически вызывать `imap:pull`.
- **Realtime**: продолжайте держать `imap:sentry` под супервизором — он будет
  сохранять письма по мере поступления, а `spoon:deliver` (из шага 4) их
  доставит.

### Проверка

```bash
php artisan schedule:list   # должен присутствовать spoon:deliver
php artisan migrate:status  # миграция relayed_messages применена
```

После прихода письма убедитесь, что появилась запись в `relayed_messages` и её
статус меняется на `delivered`.

### Откат

Если нужно вернуться на 1.0: переключитесь на тег `v1.0.0`. Письма, уже
помеченные прочитанными в 2.0, повторно прочитаны не будут — при необходимости
снимите флаг `\Seen` в почтовом клиенте или дождитесь, пока они уйдут из очереди
`relayed_messages` в 2.0 перед откатом.
