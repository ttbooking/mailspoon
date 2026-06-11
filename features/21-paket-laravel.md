# 21. Конверсия в Laravel-пакет

**Приоритет:** 🔴 высокий · **Трудоёмкость:** M–L

> ✅ **Реализовано в 3.0.0** (тег и релиз 2026-06-10): пакет, тесты на
> testbench, README/UPGRADE. #03 (маршрутизация) разблокирована. Остались
> хвосты: публикация на Packagist и миграция прода wb2 на тонкое
> хост-приложение (этапы 4–5 ниже).

## Проблема

Mailspoon — standalone-приложение, и его конфиги отслеживаются git'ом, а деплой
делается через `git pull`. Поэтому per-deployment настройки нельзя положить в
`config/spoon.php` напрямую — любая карта/структура в конфиге превращается в
сериализацию через env:

```php
// config/spoon.php — уже сейчас
'pull' => json_decode(
    trim((string) env('SPOON_PULL_SCHEDULE')) ?: '{}',
    flags: JSON_OBJECT_AS_ARRAY | JSON_THROW_ON_ERROR,
),
```

JSON в `.env` — рабочий, но хрупкий костыль (пустое значение уже однажды
роняло загрузку конфигурации, фикс v2.1.1). С фичей #03 станет хуже: карта
маршрутов «ящик → эндпоинт + ключ (+ таргеты)» в env-JSON нечитаема и
неподдерживаема.

## Цель

Превратить репозиторий в composer-пакет `ttbooking/mailspoon`, устанавливаемый
в любое Laravel-приложение. Пакет шипит дефолтный конфиг, хост-приложение
публикует и **владеет** своим `config/mailspoon.php` как обычным PHP — карты,
расписания, замыкания, без сериализации в env.

## Что это даёт

- **Конфиги первого класса.** `vendor:publish` → `config/mailspoon.php` принадлежит
  деплою; `SPOON_PULL_SCHEDULE`-JSON и подобные костыли удаляются.
- **Версионирование начинает работать на полную.** Теги, CHANGELOG и SemVer уже
  ведутся — composer constraint'ы (`^3.0`) вместо «задеплоили master».
- **Хост-приложение бесплатно даёт инфраструктуру:** БД, планировщик, кэш для
  `withoutOverlapping`, логи, мониторинг.
- **Естественная связка с laravel-mailbox:** принимающее приложение может
  поставить mailspoon к себе же.

## Предлагаемое решение

### Структура пакета

```
mailspoon/
├── composer.json            # ttbooking/mailspoon, type: library
├── config/mailspoon.php     # дефолты
├── database/migrations/
├── src/
│   ├── MailspoonServiceProvider.php
│   ├── Commands/            # ImapPull, ImapSentry, DeliverMessages
│   ├── Listeners/           # StoreIncomingMessage
│   ├── Models/              # RelayedMessage
│   └── Support/             # ArchiveStorage
└── tests/                   # Pest + orchestra/testbench
```

Неймспейс `App\` → `TTBooking\Mailspoon\`.

### MailspoonServiceProvider

Две вещи, которые в приложении работали «сами», в пакете делаются явно:

```php
public function register(): void
{
    $this->mergeConfigFrom(__DIR__.'/../config/mailspoon.php', 'mailspoon');
}

public function boot(): void
{
    $this->publishes([__DIR__.'/../config/mailspoon.php' => config_path('mailspoon.php')], 'mailspoon-config');
    $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

    // авто-discovery листенеров на пакеты не действует
    Event::listen(MessageReceived::class, StoreIncomingMessage::class);

    if ($this->app->runningInConsole()) {
        $this->commands([ImapPullCommand::class, ImapSentryCommand::class, DeliverMessagesCommand::class]);

        // расписание переезжает из bootstrap/app.php
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // spoon:deliver, model:prune, imap:pull — как сейчас,
            // но карта pull читается из обычного PHP-конфига хоста
        });
    }
}
```

### Зависимости

`composer.json`: `php ^8.3`, illuminate-компоненты `^13.0` (console, database,
events, http, support), `directorytree/imapengine-laravel` (его `config/imap.php`
публикуется его же провайдером).

### Тесты

Перевод на `orchestra/testbench`: `TestCase::getPackageProviders()` +
`defineEnvironment()` вместо полного приложения. Существующие 27 Pest-тестов —
Feature-уровня, переезжают почти механически. Самая нудная часть конверсии.

### Standalone-деплой (текущий прод)

Текущая инсталляция (supervisor + cron) превращается в тонкое хост-приложение:
свежий `laravel new` + `composer require ttbooking/mailspoon` + опубликованный
конфиг. Конфиги хоста — собственность деплоя, проблема «не трогать
отслеживаемые файлы» исчезает по построению.

## План реализации

Объём переносимой логики невелик — 6 классов (3 команды, listener, модель,
`ArchiveStorage`), 1 миграция, 1 конфиг, 27 тестов. Основная работа — каркас
пакета и перевод тестов.

### Решения, принятые заранее

- **Конвертируем этот же репозиторий**, а не заводим новый: сохраняется
  история, теги v1–v2.1.1, релизы, и имя `ttbooking/mailspoon` в
  `composer.json` уже совпадает с будущим именем пакета. Для прода заводится
  отдельное тонкое хост-приложение (см. этап 6).
- **Ветка поддержки `2.x`** создаётся от master перед началом работ — хотфиксы
  прода до его миграции выпускаются из неё (2.1.x).
- **Зависимости — точечные `illuminate/*`** (console, database, events,
  http, support, contracts), а не весь `laravel/framework`: пакету не нужны
  views/session/mail, а хосту это развязывает руки по версиям.

### Этап 1 — каркас пакета

- [x] `composer.json`: `type` `project` → `library`; `require` →
      `php ^8.3` + `illuminate/* ^13.0` + `directorytree/imapengine-laravel ^1.0`;
      убрать app-only пакеты (`laravel/tinker`, `laravel/sail`, `laravel/pail`,
      `fakerphp/faker`); `require-dev` + `orchestra/testbench` (версия под
      Laravel 13); убрать `post-autoload-dump`/`post-update-cmd` скрипты
      приложения; добавить авто-discovery:
      `extra.laravel.providers = [TTBooking\Mailspoon\MailspoonServiceProvider::class]`.
- [x] Перенос кода в `src/` со сменой неймспейса `App\` → `TTBooking\Mailspoon\`:
      `app/Console/Commands/*` → `src/Commands/`, `app/Listeners/*` →
      `src/Listeners/`, `app/Models/*` → `src/Models/`, `app/Support/*` →
      `src/Support/`. PSR-4: `"TTBooking\\Mailspoon\\": "src/"`.
- [x] `config/spoon.php` остаётся в корне пакета (позже переименован в
      `config/mailspoon.php`); миграция остаётся в `database/migrations/`.
- [x] Удалить скелет приложения: `bootstrap/`, `routes/`, `public/`,
      `resources/`, `storage/`, `artisan`, `app/Http`, `app/Providers`,
      остальные `config/*` (включая `config/view.php` — боль хоста, не пакета),
      `.env.example` (содержимое переезжает в README хост-приложения).

### Этап 2 — MailspoonServiceProvider

- [x] `register()`: `mergeConfigFrom(config/mailspoon.php, 'mailspoon')`.
      Помнить: merge верхнеуровневый — published-конфиг хоста должен сохранять
      полную структуру секций (стандартная практика, фиксируем в README).
- [x] **Переименование конфига `spoon` → `mailspoon`:** файл
      `config/mailspoon.php`, ключ `config('mailspoon.*')`, атрибуты
      `#[Config('mailspoon.*')]` — единый нейминг с пакетом и командами.
- [x] **Переименование env-переменных `SPOON_*` → `MAILSPOON_*`** (12 шт.:
      endpoint, key, archive, delivery, retention, cron) — релиз ломающий,
      таблица соответствия будет в UPGRADE.md.
- [x] `boot()`: `publishes([... => config_path('mailspoon.php')], 'mailspoon-config')`;
      `loadMigrationsFrom(database/migrations)` +
      `publishesMigrations(...)` (Laravel 13 переписывает даты при публикации);
      `Event::listen(MessageReceived::class, StoreIncomingMessage::class)` —
      авто-discovery листенеров на пакеты не действует.
- [x] Консольная часть (`runningInConsole()`): `commands([...3 команды...])` +
      перенос расписания из `bootstrap/app.php` (deliver / prune / pull-карта)
      в `callAfterResolving(Schedule::class, ...)` — логика копируется 1:1.
- [x] **Переименовать команды под собственный неймспейс `mailspoon:`** —
      пакет не должен публиковать команды в чужом `imap:*` (он принадлежит
      ImapEngine: `imap:watch` — vendor-команда, наши `imap:pull`/`imap:sentry`
      выглядят как его часть и рискуют коллизией имён):
      `imap:pull` → `mailspoon:pull`, `imap:sentry` → `mailspoon:sentry`,
      `spoon:deliver` → `mailspoon:deliver`. Старые имена не сохраняем —
      релиз и так ломающий; внутренний вызов `imap:watch` (vendor) остаётся.
      Обновить имена в расписании провайдера и в выводах/описаниях команд.
- [x] Конфиг: ключ `schedule.pull` становится обычным массивом
      `['default' => '*/5 * * * *']`, `json_decode`/`SPOON_PULL_SCHEDULE`
      удаляются. Скалярные настройки сохраняют `env()`-дефолты — это
      работает и в merged-конфиге без публикации.

### Этап 3 — тесты на testbench

- [x] `tests/TestCase.php` → `Orchestra\Testbench\TestCase`:
      `getPackageProviders()` возвращает `MailspoonServiceProvider` +
      `ImapServiceProvider` (imapengine); `defineEnvironment()` задаёт
      sqlite `:memory:` и `filesystems.disks.local.throw => true` (в скелете
      testbench дефолт `false`, а `ArchiveStorage` такие диски отвергает).
- [x] `RefreshDatabase` продолжает работать поверх `loadMigrationsFrom`.
- [x] Снести стоковые `tests/Feature/ExampleTest.php` и
      `tests/Unit/ExampleTest.php` (последний воскрес в e0da630), почистить
      `phpunit.xml` от app-специфики (`APP_KEY` и т.п. задаёт testbench).
- [x] `ScheduleTest` переписывать не пришлось: расписание регистрируется
      лениво (`callAfterResolving`), поэтому `config()` в теле теста успевает
      примениться до `schedule:list`.
- [x] Паритет: 25 тестов (27 минус два Example) зелёные; Pint чистый.

### Этап 4 — документация и релиз

- [x] README: установка (`composer require ttbooking/mailspoon`),
      `vendor:publish --tag=mailspoon-config` (+ конфиг imapengine — у его
      провайдера нет тега, публикация через `--provider=`), `php artisan
      migrate`, строка cron `schedule:run`, пример supervisor для
      `mailspoon:sentry`, раздел про карты в опубликованном конфиге и блок
      про обязательный `'throw' => true` на диске архива.
- [x] UPGRADE.md, раздел 2.x → 3.0: сводная таблица ломающих изменений,
      маппинг env-переменных и команд, `'throw' => true`, подключение
      существующей БД и архива, supervisor/cron, проверка.
- [x] CHANGELOG `[3.0.0] - 2026-06-10`; PR #2 смержен в master (squash),
      тег `v3.0.0` и GitHub Release опубликованы 2026-06-10.
- [ ] Packagist: публикация пакета, авто-обновление по GitHub-хуку.

### Этап 5 — миграция прода (wb2)

- [ ] Тонкое хост-приложение (`laravel new` + require пакета) в отдельном
      приватном репозитории; `.env` и опубликованный конфиг — собственность
      деплоя, проблема «не трогать отслеживаемые файлы» исчезает.
- [ ] Перенос данных: existing sqlite + каталог архива (или смена путей в
      конфиге на старые места).
- [ ] supervisor: в программе `mailspoon-idle` обновить путь artisan **и имя
      команды** (`imap:sentry` → `mailspoon:sentry`); `laravel-queue-horizon`
      не трогаем; cron `schedule:run` — путь.
- [ ] Прогон: `mailspoon:pull` тестового ящика, контроль доставки и prune;
      после стабилизации — снос старого checkout, ветка `2.x` объявляется EOL.

### Оценка

Этапы 1–2 — день; этап 3 — день-полтора (самая нудная часть); этапы 4–5 —
полдня + наблюдение за продом. Итого ~3 рабочих дня без спешки.

## Версионирование

Ломающее изменение всего: установка, неймспейсы, расположение конфигов
(`config/mailspoon.php`, ключ `mailspoon`), env-переменные
(`SPOON_*` → `MAILSPOON_*`), способ задания расписаний, имена artisan-команд
(`mailspoon:*`) → **3.0.0**. В UPGRADE.md — раздел 2.x → 3.0 (маппинг
env-переменных и старых имён команд на новые; перенос данных не требуется —
схема БД не меняется).

## Замечания

- `SPOON_PULL_SCHEDULE` объявляется deprecated в 2.x и удаляется в 3.0.0 —
  карта расписаний становится обычным массивом в опубликованном конфиге.
- Скелет провайдера можно собрать на `spatie/laravel-package-tools`, но
  провайдер тривиален — можно и руками, без лишней зависимости.
- Packagist: публикация под vendor `ttbooking`, авто-хук на теги GitHub.
- Очерёдность: #21 → #03 (фаза 1) → остальное.

## Definition of Done

- [x] `composer require ttbooking/mailspoon` в чистом Laravel-приложении
      поднимает команды, listener, миграции и расписание.
- [x] `vendor:publish --tag=mailspoon-config` публикует конфиг; настройки
      картами задаются в PHP, env-JSON удалён.
- [x] Тесты на testbench зелёные (паритет с текущими 27: 25 после сноса
      двух Example-стобов).
- [x] UPGRADE.md: раздел 2.x → 3.0.
- [ ] Прод переведён на тонкое хост-приложение с пакетом.
- [x] Релиз 3.0.0: тег и GitHub Release опубликованы.
- [ ] Пакет опубликован на Packagist.
