# Changelog

Все значимые изменения проекта документируются в этом файле.

Формат основан на [Keep a Changelog](https://keepachangelog.com/ru/1.1.0/),
проект придерживается [семантического версионирования](https://semver.org/lang/ru/).

## [Unreleased]

### Added

- **Маршрутизация ящиков** (фича #03, фаза 1): карта `routes` в
  `config/mailspoon.php` привязывает ящик (имя из `config/imap.php`) к
  собственному `endpoint` и `key`. Эндпоинт фиксируется в записи при захвате,
  ключ подписи выбирается при доставке (ротация действует на pending-письма).
  Ящик без маршрута использует глобальные `endpoint`/`key`, как раньше.
- Колонки `relayed_messages.target` (задел под fan-out, пока всегда `default`)
  и `relayed_messages.account` (IMAP-логин источника); дедупликация — по
  тройке `(mailbox, target, fingerprint)` вместо глобального `fingerprint`,
  поэтому письмо с копией на два ящика доставляется на оба эндпоинта.

### Changed

- Колонка `relayed_messages.mailbox` теперь хранит имя ящика из
  `config/imap.php` (ключ маршрута), а не IMAP-логин — логин переехал в
  `account`. Имя приходит в событии `MessageReceived` — для этого в
  imapengine-laravel отправлен и принят PR, требование поднято до `^1.3`.
- Путь архива включает имя ящика (`{path}/{mailbox}/{Y/m/d}/...`), чтобы копии
  одного письма из разных ящиков не перезаписывали друг друга.

## [3.0.0] - 2026-06-10

Mailspoon стал **composer-пакетом для Laravel** (`ttbooking/mailspoon`) вместо
standalone-приложения. Содержит **ломающие изменения**; пошаговая инструкция —
в [UPGRADE.md](UPGRADE.md).

### Changed

- **Распространение**: composer-пакет с авто-discovery провайдера
  `TTBooking\Mailspoon\MailspoonServiceProvider`; код переехал в неймспейс
  `TTBooking\Mailspoon\`. Зависимости сужены до точечных `illuminate/*`.
- **Команды переименованы** в собственный неймспейс: `imap:pull` →
  `mailspoon:pull`, `imap:sentry` → `mailspoon:sentry`, `spoon:deliver` →
  `mailspoon:deliver` (vendor-команда `imap:watch` из ImapEngine не менялась).
- **Конфиг переименован**: публикуемый `config/mailspoon.php`
  (`vendor:publish --tag=mailspoon-config`), ключ `mailspoon.*`.
- **Env-переменные переименованы**: `SPOON_*` → `MAILSPOON_*` (один к одному).
- **Расписание cron-poll** задаётся картой `schedule.pull` в опубликованном
  конфиге обычным PHP-массивом; расписание регистрируется провайдером пакета
  в планировщике хост-приложения.
- Тесты переведены на `orchestra/testbench`.

### Removed

- Скелет standalone-приложения (`bootstrap/`, `artisan`, app-конфиги,
  `.env.example`).
- Переменная `SPOON_PULL_SCHEDULE` (JSON в env) — заменена картой в
  опубликованном конфиге.

## [2.1.1] - 2026-06-09

### Fixed

- Пустое значение `SPOON_PULL_SCHEDULE` в `.env` (например, `SPOON_PULL_SCHEDULE=`
  без `'{}'`) больше не роняет загрузку конфигурации — теперь оно трактуется как
  пустое расписание. Некорректный JSON по-прежнему вызывает ошибку (fail-fast).

## [2.1.0] - 2026-06-09

### Added

- Retention-очистка на базе Eloquent `Prunable`: успешно доставленные записи
  `relayed_messages` удаляются вместе со связанными архивами `.eml`; записи
  `pending` и `failed` автоматически не удаляются.
  По умолчанию срок хранения равен 3 дня; очистка запускается планировщиком по
  `SPOON_PRUNE_CRON`. Для отключения задайте `SPOON_RETENTION_DAYS=0`.
- Архивный filesystem disk теперь обязан использовать `'throw' => true`;
  встроенные диски `local` и `s3` настроены соответственно.
- Расписание cron-poll для `imap:pull` настраивается через
  `SPOON_PULL_SCHEDULE` в `.env`, без изменения отслеживаемых config-файлов.

### Fixed

- Добавлена отсутствовавшая конфигурация Blade views и каталог compiled views,
  поэтому `artisan optimize` и рендеринг страниц больше не падают с ошибкой
  `View path not found`.

## [2.0.1] - 2026-06-09

### Fixed

- `imap:pull` и обе фазы `imap:sentry` теперь по умолчанию загружают
  `flags,headers,body`, поэтому архив содержит полный сырой MIME даже без
  явного `--with`.
- Пустой raw MIME больше не сохраняется и не помечается прочитанным, а пустой
  архивный файл не отправляется командой `spoon:deliver`.

## [2.0.0] - 2026-06-09

Переход на архитектуру **store-and-forward**. Содержит **ломающие изменения**;
пошаговая инструкция по обновлению — в [UPGRADE.md](UPGRADE.md).

### Added

- **Store-and-forward.** Входящее письмо сохраняется в хранилище (архив сырого
  MIME) и фиксируется в таблице `relayed_messages` со статусом `pending`, после
  чего сразу помечается прочитанным. Чтение ящика развязано с доставкой, поэтому
  медленный или недоступный эндпоинт больше не блокирует однопоточное чтение.
- **Дедупликация** по `Message-Id` (или хешу письма, если заголовка нет).
- **Команда `spoon:deliver`** — внеполосная доставка сохранённых писем на
  эндпоинт с обновлением статуса (`delivered` / `failed`).
- **Двухуровневый ретрай:** короткие in-process повторы на одну попытку для
  транзиентных сбоев (сеть, 5xx, 429) с лимитами таймаутов, плюс backoff между
  запусками через колонку `next_attempt_at` (без блокирующих пауз в воркере).
- **Отдельный таймаут на TCP-соединение** (`SPOON_CONNECT_TIMEOUT`), чтобы
  зависший handshake не подвешивал воркер.
- **Встроенный планировщик** (`withoutOverlapping`): `spoon:deliver` по
  умолчанию + опционально `imap:pull` по ящикам (режим cron-poll без демона).
- **Новые настройки:** `spoon.archive.*`, `spoon.delivery.*`, `spoon.schedule.*`;
  переменные `SPOON_ARCHIVE_DISK`, `SPOON_ARCHIVE_PATH`, `SPOON_TIMEOUT`,
  `SPOON_CONNECT_TIMEOUT`, `SPOON_TRIES`, `SPOON_BACKOFF`, `SPOON_MAX_ATTEMPTS`,
  `SPOON_DELIVER_CRON`.
- **Тесты** (Pest) на захват письма, доставку, ретрай/backoff и планирование.

### Changed

- **Чтение больше не доставляет.** `imap:pull` / `imap:sentry` теперь только
  сохраняют письма; фактическую отправку выполняет `spoon:deliver`. Латентность
  доставки определяется периодом запуска планировщика.
- **`timestamp` в подписи** — теперь момент отправки вебхука (свежий), а не дата
  письма. Это корректнее для получателей, проверяющих свежесть запроса.
- **Требуется база данных** (раньше `DB_CONNECTION=null`); по умолчанию SQLite.

### Removed

- Слушатель `App\Listeners\NotifyNewMessage` (синхронная отправка) — заменён на
  `App\Listeners\StoreIncomingMessage` + команду `spoon:deliver`.

## [1.0.0] - 2026-06-09

Первый помеченный релиз — baseline, соответствующий версии в production.

### Added

- IMAP → HTTP реле, совместимое с входящим MIME-вебхуком Mailgun
  (`body-mime` + `HMAC-SHA256` подпись).
- Команды `imap:pull` (разовая выборка непрочитанных), `imap:sentry`
  (выборка + слежение) и `imap:watch` (IMAP IDLE) на базе ImapEngine.
- Слушатель `NotifyNewMessage`: синхронный POST на эндпоинт и пометка письма
  прочитанным после отправки.
- Настройки IMAP-подключения (`config/imap.php`) и реле (`config/spoon.php`).
