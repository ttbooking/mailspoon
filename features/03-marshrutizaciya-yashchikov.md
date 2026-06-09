# 03. Маршрутизация ящиков → эндпоинтов

**Приоритет:** 🟡 средний · **Трудоёмкость:** M

> Актуализировано под store-and-forward (2.x): маршрут затрагивает обе фазы —
> захват (`StoreIncomingMessage`) и доставку (`spoon:deliver`).

## Проблема

`config/spoon.php` задаёт **один** глобальный `endpoint` и `key`:

```php
'endpoint' => env('SPOON_ENDPOINT'),
'key' => env('SPOON_KEY'),
```

При этом `config/imap.php` уже поддерживает несколько ящиков (`mailboxes`).
Сейчас все ящики неизбежно пересылаются на один и тот же URL с одним ключом —
нельзя, например, `support@` слать в один сервис, а `billing@` — в другой.

Кроме того, один ящик может обслуживать **несколько** приложений: общий
`inbox@`, письма из которого должны получить и CRM, и helpdesk. Сейчас
веер (fan-out) на несколько эндпоинтов невозможен в принципе — запись
`relayed_messages` несёт один `endpoint` и один статус доставки.

## Цель

Привязать каждый ящик к своему эндпоинту (или **нескольким** — fan-out) и
ключу подписи, сохранив обратную совместимость с одиночной конфигурацией.

## Контекст 2.x: где живёт маршрут

После перехода на store-and-forward доставка разнесена на две фазы, и маршрут
распределяется между ними:

- **Endpoint резолвится при захвате.** `StoreIncomingMessage` уже пишет
  `endpoint` в каждую запись `relayed_messages` — нужно лишь выбирать его по
  ящику вместо глобального конфига. Endpoint фиксируется на момент получения
  письма (аудит: видно, куда письмо было адресовано).
- **Ключ подписи резолвится при доставке.** `spoon:deliver` сейчас подписывает
  всё одним `spoon.key`. Ключ **не** хранится в БД (секрет; ротация должна
  действовать и на pending-письма) — он выбирается по колонкам
  `mailbox` + `target` в момент POST'а, с откатом на глобальный `spoon.key`.

## Предлагаемое решение

Карта маршрутов в `config/spoon.php`: ключ верхнего уровня — **имя ящика** из
`config/imap.php`, внутри — один или несколько **именованных таргетов**:

```php
return [
    // дефолт (back-compat)
    'endpoint' => env('SPOON_ENDPOINT'),
    'key' => env('SPOON_KEY'),

    'routes' => [
        // одиночная форма — нормализуется в таргет с именем 'default'
        'billing' => [
            'endpoint' => env('SPOON_BILLING_ENDPOINT'),
            'key' => env('SPOON_BILLING_KEY'),
        ],

        // fan-out: общий ящик, письмо получают оба приложения
        'support' => [
            'crm' => [
                'endpoint' => env('SPOON_SUPPORT_CRM_ENDPOINT'),
                'key' => env('SPOON_SUPPORT_CRM_KEY'),
            ],
            'helpdesk' => [
                'endpoint' => env('SPOON_SUPPORT_HELPDESK_ENDPOINT'),
                'key' => env('SPOON_SUPPORT_HELPDESK_KEY'),
            ],
        ],
    ],
];
```

### Fan-out: запись на каждый таргет

Статусы доставки независимы: CRM может принять письмо, а helpdesk — лежать.
Поэтому listener создаёт **по записи `relayed_messages` на каждый таргет**
маршрута (одно письмо в `support` с двумя таргетами → две записи). Каждая
запись живёт своим циклом `pending → delivered/failed` со своими ретраями и
backoff — `spoon:deliver` менять не нужно, он уже обрабатывает записи
независимо.

В таблицу добавляется колонка `target` (имя таргета, для одиночной формы —
`default`); ключ подписи при доставке резолвится по паре:

```php
$key = config("spoon.routes.{$message->mailbox}.{$message->target}.key")
    ?? $this->key;
```

Все записи одного письма ссылаются на **один** архивный `.eml`
(`archive_path` совпадает) — сырой MIME не дублируется.

### Проброс имени ящика

Событие `MessageReceived` несёт только `$message`, а `Mailbox` в ImapEngine
создаётся из массива конфига и **своего имени не знает** (`ImapManager::build`).
`$folder->mailbox()->config('username')` возвращает IMAP-логин, а не имя из
`config/imap.php` — ключевать маршруты им нельзя.

Имя ящика знают команды (`imap:pull {mailbox}`, `imap:sentry {mailbox}`) —
пробрасываем его в listener через `Context`:

```php
// в imap:pull и imap:sentry, до выборки/IDLE
Context::add('spoon.mailbox', $mailboxName);

// в StoreIncomingMessage
$name = Context::get('spoon.mailbox');

$route = config("spoon.routes.{$name}", []);
$endpoint = $route['endpoint'] ?? $this->endpoint;
```

`imap:sentry` вызывает `imap:watch` in-process (`$this->call()`), поэтому
контекст, установленный в sentry, действует и для IDLE-событий.

Колонка `mailbox` начинает хранить имя ящика (а не username, как сейчас) —
именно по паре `mailbox` + `target` `spoon:deliver` выбирает ключ (см. выше).
IMAP-логин при желании сохраняем отдельно (новая nullable-колонка `account`).

### Дедупликация per-target

Сейчас `fingerprint` глобально уникален. Если одно письмо попадает в два ящика
(CC на `support@` и `billing@`), второй экземпляр отбрасывается как дубль и до
второго эндпоинта **не доходит**; fan-out с уникальным fingerprint невозможен
вовсе. Уникальность нужно сделать составной:

- миграция: `unique(['mailbox', 'target', 'fingerprint'])` вместо
  `unique('fingerprint')`;
- проверка дубля в listener — по той же тройке (повторный pull письма не
  плодит записей, но каждый таргет получает свою);
- имя файла архива уже включает дату и fingerprint — добавить ящик в путь
  (`{basePath}/{mailbox}/{Y/m/d}/...`), чтобы копии из разных ящиков не
  перезаписывали друг друга; таргеты одного ящика делят один файл.

### Retention и общий архив

`pruning()` в `RelayedMessage` удаляет `.eml` вместе с записью. При fan-out
один файл делят несколько записей — удалять его можно только когда удаляется
**последняя** ссылающаяся запись:

```php
$shared = self::query()
    ->where('archive_path', $this->archive_path)
    ->whereKeyNot($this->getKey())
    ->exists();
```

Иначе prune доставленной CRM-записи снесёт архив, который ещё нужен
недоставленной helpdesk-записи.

## Изменения конфигурации

```dotenv
SPOON_BILLING_ENDPOINT=https://other/.../mime
SPOON_BILLING_KEY=key-...
SPOON_SUPPORT_CRM_ENDPOINT=https://crm/.../mime
SPOON_SUPPORT_CRM_KEY=key-...
SPOON_SUPPORT_HELPDESK_ENDPOINT=https://helpdesk/.../mime
SPOON_SUPPORT_HELPDESK_KEY=key-...
```

## Замечания

- Back-compat: без `routes` (или для ящика без маршрута) поведение в точности
  сегодняшнее — глобальные `SPOON_ENDPOINT`/`SPOON_KEY`.
- Endpoint зафиксирован в записи на момент захвата; ключ берётся из конфига на
  момент доставки. Следствие: ротация ключа действует на pending-письма сразу,
  а смена endpoint — только на новые письма (старые доедут на прежний адрес).
- Хорошо сочетается с #04 (фильтрация) — правила можно держать на уровне
  маршрута.
- Запуск нескольких воркеров: по одному `imap:sentry <mailbox>` на ящик под
  supervisor (см. #09) либо cron-poll через `SPOON_PULL_SCHEDULE` (#16).

## Definition of Done

- [ ] Письмо сохраняется с эндпоинтом, соответствующим его ящику, и
      доставляется на него.
- [ ] Fan-out: ящик с несколькими таргетами создаёт запись на каждый; статусы
      и ретраи независимы, сырой MIME архивируется один раз.
- [ ] `spoon:deliver` подписывает запрос ключом таргета; ключи не хранятся в БД.
- [ ] Откат на глобальный `endpoint/key`, если маршрут не задан (back-compat).
- [ ] Колонка `mailbox` хранит имя ящика из `config/imap.php`; дедупликация и
      уникальный индекс — по тройке `(mailbox, target, fingerprint)`.
- [ ] Prune удаляет общий `.eml` только вместе с последней ссылающейся записью.
- [ ] Тест: два ящика → два разных `Http::fake()`-адреса с разными подписями.
- [ ] Тест: одно письмо в двух ящиках доставляется на оба эндпоинта.
- [ ] Тест: fan-out — отказ одного таргета не мешает доставке на второй.
