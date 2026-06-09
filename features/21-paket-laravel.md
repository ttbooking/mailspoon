# 21. Конверсия в Laravel-пакет

**Приоритет:** 🔴 высокий · **Трудоёмкость:** M–L

> Блокирует #03 (маршрутизация): карты маршрутов с эндпоинтами и ключами в
> env-JSON делать не хотим — сначала пакет с нормальными публикуемыми
> конфигами, потом #03 поверх них.

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
публикует и **владеет** своим `config/spoon.php` как обычным PHP — карты,
расписания, замыкания, без сериализации в env.

## Что это даёт

- **Конфиги первого класса.** `vendor:publish` → `config/spoon.php` принадлежит
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
├── config/spoon.php         # дефолты
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
    $this->mergeConfigFrom(__DIR__.'/../config/spoon.php', 'spoon');
}

public function boot(): void
{
    $this->publishes([__DIR__.'/../config/spoon.php' => config_path('spoon.php')], 'mailspoon-config');
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

## Версионирование

Ломающее изменение всего: установка, неймспейсы, расположение конфигов,
способ задания расписаний → **3.0.0**. В UPGRADE.md — раздел 2.x → 3.0
(маппинг env-переменных на ключи конфига, перенос данных не требуется —
схема БД не меняется).

## Замечания

- `SPOON_PULL_SCHEDULE` объявляется deprecated в 2.x и удаляется в 3.0.0 —
  карта расписаний становится обычным массивом в опубликованном конфиге.
- Скелет провайдера можно собрать на `spatie/laravel-package-tools`, но
  провайдер тривиален — можно и руками, без лишней зависимости.
- Packagist: публикация под vendor `ttbooking`, авто-хук на теги GitHub.
- Очерёдность: #21 → #03 (фаза 1) → остальное.

## Definition of Done

- [ ] `composer require ttbooking/mailspoon` в чистом Laravel-приложении
      поднимает команды, listener, миграции и расписание.
- [ ] `vendor:publish --tag=mailspoon-config` публикует конфиг; настройки
      картами задаются в PHP, env-JSON удалён.
- [ ] Тесты на testbench зелёные (паритет с текущими 27).
- [ ] UPGRADE.md: раздел 2.x → 3.0.
- [ ] Прод переведён на тонкое хост-приложение с пакетом.
- [ ] Пакет опубликован на Packagist, релиз 3.0.0.
