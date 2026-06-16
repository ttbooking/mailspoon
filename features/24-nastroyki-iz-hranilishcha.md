# 24. Настройки маршрутов из хранилища

**Приоритет:** 🟡 средний · **Трудоёмкость:** S–M

> ✅ Реализовано: `MailspoonManager` + фасад `Mailspoon` + контракт `Route`,
> маршруты и pull-расписание задаются в рантайме через `Mailspoon::register()`;
> динамические IMAP-подключения — через `Imap::register()` из `boot()` хоста
> (описано в README). Разблокировано переходом на пакет (#21).
>
> 3.8.0: `mailspoon:doctor` предупреждает (`warn`), если у ящика нет cron-pull
> расписания — рантайм-ящик без `schedule` больше не остаётся тихо
> неопрашиваемым (см. #17).

## Проблема

После #21 настройки живут в опубликованном `config/mailspoon.php` — обычный PHP,
собственность деплоя. Этого достаточно, пока набор ящиков статичен и известен на
момент деплоя. Но приложение, встраивающее mailspoon **как читца почты**, часто
хочет управлять маршрутами динамически:

- **Мультитенант.** Каждый тенант/клиент регистрирует свой ящик и свой
  webhook-эндпоинт с ключом через UI приложения. Карта `routes` тогда живёт в
  таблице БД, а не в файле — добавление тенанта не должно требовать правки
  конфига и редеплоя.
- **Ротация и управление из приложения.** Сменить ключ, поставить ящик на паузу
  (`enabled => false`), поправить фильтры — операция приложения над своей
  моделью, а не правка файла на сервере.
- **Источник истины вне файла.** Конфиг-файл плохо переживает несколько
  инстансов; БД (или кэш) — естественный общий источник.

Сейчас источник зашит: per-mailbox опции читаются из `config('mailspoon.routes')`,
карта pull-расписания — из `config('mailspoon.schedule.pull')`. Подменить нельзя.

## Цель

Сделать **по аналогии с `Imap`** из imapengine: единый `MailspoonManager` с
реестром маршрутов в памяти, фасад `Mailspoon` и метод `register()`. Провайдер
сидирует реестр из текущего конфига (поведение не меняется), а хост при
необходимости докидывает/переопределяет маршруты в рантайме:

```php
Mailspoon::register('tenant-42', [
    'endpoint' => 'https://tenant-42.example.com/api/mailgun/mime',
    'key' => 'key-42',
    'schedule' => '*/5 * * * *',   // optional: cron-poll this mailbox
    'enabled' => true,
    'mark' => 'keyword:Mailspoon',
    'filters' => ['deny' => ['from' => ['no-reply@*']]],
]);
```

Это ровно то, как imapengine даёт `Imap::register('name', [...])`. Глобальные
скаляры (`delivery.*`, `archive.*`, `retention`, `pull.chunk`, таймауты)
остаются в конфиге и читаются через `#[Config(...)]` — их выносить не цель.

## Что это даёт

- Один знакомый паттерн: `Mailspoon::register()` зеркалит `Imap::register()`.
- Ноль изменений для текущих пользователей: реестр сидируется из конфига.
- Минимальная хирургия: все per-mailbox опции уже сходятся в один чокпоинт
  `Support\MailboxRoute`, который начинает спрашивать менеджер.
- Нет интерфейсов, которые хост обязан реализовывать: хочешь из БД — просто
  вызови `register()` в своём `boot()`.

## Решение

### MailspoonManager

```php
namespace TTBooking\Mailspoon;

final class MailspoonManager
{
    /** @var array<string, array<string, mixed>> mailbox => route options */
    private array $routes = [];

    /**
     * Register or override the route for a mailbox at runtime.
     *
     * @param  array{endpoint?:string,key?:?string,enabled?:bool,mark?:string,filters?:array,schedule?:string}  $route
     */
    public function register(string $mailbox, array $route): void
    {
        $this->routes[$mailbox] = $route;
    }

    /** Resolved route options for a mailbox (empty when none). */
    public function route(string $mailbox): array
    {
        return $this->routes[$mailbox] ?? [];
    }

    /** All registered routes, keyed by mailbox. */
    public function routes(): array
    {
        return $this->routes;
    }
}
```

### Фасад

```php
namespace TTBooking\Mailspoon\Facades;

/**
 * @method static void register(string $mailbox, array $route)
 * @method static array route(string $mailbox)
 * @method static array routes()
 *
 * @see \TTBooking\Mailspoon\MailspoonManager
 */
final class Mailspoon extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MailspoonManager::class;
    }
}
```

### Биндинг и сидирование из конфига

```php
public function register(): void
{
    $this->mergeConfigFrom(__DIR__.'/../config/mailspoon.php', 'mailspoon');

    $this->app->singleton(MailspoonManager::class, function () {
        $manager = new MailspoonManager;

        foreach (config('mailspoon.routes', []) as $mailbox => $route) {
            $manager->register($mailbox, $route);
        }

        // Back-compat: the pull schedule lives in a separate config map today.
        // Fold it into each route's `schedule` so the manager is the single
        // source the scheduler iterates.
        foreach (config('mailspoon.schedule.pull', []) as $mailbox => $cron) {
            $manager->register($mailbox, [...$manager->route($mailbox), 'schedule' => $cron]);
        }

        return $manager;
    });
}
```

### MailboxRoute спрашивает менеджер

Публичный статический API сохраняется — меняются только тела:

```php
public static function option(string $mailbox, string $option): mixed
{
    return Mailspoon::route($mailbox)[$option] ?? null;
}

public static function enabled(string $mailbox): bool
{
    return (Mailspoon::route($mailbox)['enabled'] ?? true) !== false;
}
```

Так `endpoint`, `key`, `mark`, `filters`, `enabled` начинают резолвиться из
менеджера без правок в `StoreIncomingMessage`, `DeliverMessagesCommand`,
`CaptureMarker`, `MessageMatcher`, `DoctorCommand` — они уже ходят через
`MailboxRoute`.

### Планировщик идёт по реестру ✅

Реализовано. `MailspoonManager::schedules()` собирает карту `ящик => cron` из
двух источников: legacy-карты `config('mailspoon.schedule.pull')` (обратная
совместимость — конфиг не ломаем) и любого маршрута с ключом `schedule` (из
конфига или рантайм-регистрации; route-level имеет приоритет для своего ящика).
Провайдер идёт по этой карте:

```php
foreach (Mailspoon::schedules() as $mailbox => $cron) {
    if (empty($cron) || ! Mailspoon::route($mailbox)->enabled()) {
        continue;
    }

    $schedule->command('mailspoon:pull', [$mailbox])
        ->cron($cron)
        ->withoutOverlapping()
        ->runInBackground();
}
```

Так ящик, зарегистрированный хостом через
`Mailspoon::register('tenant', ['schedule' => '*/5 * * * *'])`, начинает
опрашиваться по cron без правки конфига.

`callAfterResolving(Schedule::class, ...)` выполняется на каждом `schedule:run`
после загрузки всех провайдеров, поэтому маршруты, зарегистрированные хостом в
`boot()`, уже на месте — новый ящик подхватывается без редеплоя.

## IMAP-аккаунты — тем же `Imap::register()`

Маршрут — сторона доставки. Чтобы pull-режим тоже работал из БД, ящик должен
подключаться без правки `config/imap.php`. **Своей абстракции для этого не
нужно** — imapengine уже даёт `Imap::register($name, $config)`, кладущий конфиг
аккаунта в свой singleton-менеджер на время процесса. Хост регистрирует и
маршрут, и аккаунт рядом, в своём `boot()`:

```php
public function boot(): void
{
    // Runs in every process, including the background `mailspoon:pull`
    // spawned by the scheduler — so accounts are present there too.
    foreach (Mailbox::all() as $box) {       // host's own model
        Imap::register($box->mailbox, $box->imap_config);   // connection
        Mailspoon::register($box->mailbox, [                // delivery + poll
            'endpoint' => $box->endpoint,
            'key' => $box->key,
            'schedule' => $box->poll_cron,
            'enabled' => $box->enabled,
        ]);
    }
}
```

Так покрыты все точки входа: `mailspoon:pull` (в т.ч. фоновый из планировщика),
`mailspoon:sentry` (регистрация переживает вложенные `pull` и vendor `imap:watch`
в одном процессе), `mailspoon:deliver` (к IMAP не ходит).

`boot()` выполняется на каждый процесс — хост должен помнить о стоимости запроса
(кэшировать выборку, либо регистрировать только под `runningInConsole()` или
нужные команды). Это забота хоста, не пакета. IMAP-пароли в БД — тоже: хранить
через encrypted-cast.

## Семантика времени (не меняется)

Когда правка в БД вступает в силу — ровно как у конфига сегодня:

- **`endpoint`** фиксируется на записи в момент **захвата**; смена влияет только
  на будущие письма.
- **`key`** резолвится в момент **доставки** — ротация применяется к ожидающим
  письмам сразу.
- **`mark` / `filters`** применяются в момент **захвата**.
- **`enabled` / `schedule`** перечитываются на каждом `schedule:run`.

## Ограничения

- **UIDVALIDITY-курсор** (`none`-режим) ключуется по `(mailbox, folder)` в
  `RelayCursor` — для динамических имён ящиков работает без изменений.
- Глобальные `endpoint`/`key` остаются конфиг-фолбэком: для полностью
  БД-управляемого хоста задавать каждому ящику полный маршрут.
- **Прямой запуск vendor `imap:watch` мимо mailspoon** получит аккаунт только
  если хост зарегистрировал его в `boot()` (а он это и делает по схеме выше).

## План реализации

### Этап 1 — менеджер, фасад, контракт ✅
- [x] `src/MailspoonManager.php` — реестр + `register()`/`route()`/`routes()`/
      `schedules()`; забинжен синглтоном, конфиг читается живьём (не снимок).
- [x] `src/Facades/Mailspoon.php` — фасад на менеджер.
- [x] `src/Contracts/Route.php` — `route()` возвращает объект `Route`
      (`endpoint`/`key`/`definesKey`/`enabled`/`marker`/`matcher`).
- [x] `MailspoonServiceProvider::register()` — `singleton(MailspoonManager)`.

### Этап 2 — прокладка ✅
- [x] `MailboxRoute` стал реализацией `Route` — чистый value-объект
      `(options, defaults)`, без `config()` внутри (читает менеджер).
- [x] `CaptureMarker::for()`/`MessageMatcher::for()` и `#[Config]` endpoint/key
      в листенере/команде удалены — резолвинг переехал в `Route`.
- [x] `MailspoonServiceProvider::schedule()` идёт по `Mailspoon::schedules()`
      (legacy `schedule.pull` + route-level `schedule`).
- [x] `StoreIncomingMessage`, `DeliverMessagesCommand`, `DoctorCommand`,
      `ImapPullCommand`, `ImapSentryCommand` переведены на `Mailspoon::route()`.

### Этап 3 — тесты ✅
- [x] `MailspoonManagerTest` — резолв из конфига, рантайм-override, замена-не-
      merge, `routes()`, `schedules()`, фасад на синглтон.
- [x] `MailboxRouteTest` — чистый value-объект на голых массивах.
- [x] `ScheduleTest` — pull по route-level `schedule` (конфиг и рантайм),
      пауза `enabled => false`.

### Этап 4 — документация ✅
- [x] README: разделы «Маршруты из хранилища» (`Mailspoon::register()`) и
      «Динамические IMAP-подключения» (`Imap::register()` из `boot()`) — пример
      из БД, стоимость `boot()` и кэширование, encrypted-cast для паролей,
      семантика времени, ограничение про прямой `imap:watch`.

## Версионирование

Аддитивно для существующих пользователей (реестр сидируется из конфига). Новый
публичный фасад/менеджер → **minor** (например 3.5.0). Ломающих изменений нет.

## Definition of Done

- [x] Без `register()` mailspoon работает как сейчас (тесты зелёные, паритет).
- [x] `Mailspoon::register()` добавляет/переопределяет маршрут (endpoint, key,
      enabled, mark, filters, schedule) в рантайме.
- [x] Хост может опрашивать ящик не из `config/imap.php`, зарегистрировав
      аккаунт через `Imap::register()` в `boot()` (без правок imapengine) —
      описано в README (кода в пакете не требует).
- [x] Тесты на регистрацию маршрута (захват, доставка, планировщик).
- [x] README: пример из БД, стоимость `boot()`, безопасность паролей, семантика.