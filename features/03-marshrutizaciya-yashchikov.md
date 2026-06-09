# 03. Маршрутизация ящиков → эндпоинтов

**Приоритет:** 🟡 средний · **Трудоёмкость:** M

## Проблема

`config/spoon.php` задаёт **один** глобальный `endpoint` и `key`:

```php
'endpoint' => env('SPOON_ENDPOINT'),
'key' => env('SPOON_KEY'),
```

При этом `config/imap.php` уже поддерживает несколько ящиков (`mailboxes`).
Сейчас все ящики неизбежно пересылаются на один и тот же URL с одним ключом —
нельзя, например, `support@` слать в один сервис, а `billing@` — в другой.

## Цель

Привязать каждый ящик (и при желании папку) к своему эндпоинту и ключу
подписи, сохранив обратную совместимость с одиночной конфигурацией.

## Предлагаемое решение

Карта маршрутов в `config/spoon.php`:

```php
return [
    // дефолт (back-compat)
    'endpoint' => env('SPOON_ENDPOINT'),
    'key' => env('SPOON_KEY'),

    'routes' => [
        'support' => [                       // имя ящика из config/imap.php
            'endpoint' => env('SPOON_SUPPORT_ENDPOINT'),
            'key' => env('SPOON_SUPPORT_KEY'),
        ],
        'billing' => [
            'endpoint' => env('SPOON_BILLING_ENDPOINT'),
            'key' => env('SPOON_BILLING_KEY'),
        ],
    ],
];
```

`MessageReceived` от ImapEngine несёт имя ящика/папки — listener выбирает
маршрут по нему, с откатом на глобальный `endpoint/key`:

```php
$route = config("spoon.routes.{$event->mailbox}", [
    'endpoint' => $this->endpoint,
    'key' => $this->key,
]);
```

> Уточнить, как `MessageReceived` отдаёт имя ящика (свойство события либо через
> `$message->mailbox()`); при необходимости пробрасывать имя из команды.

## Изменения конфигурации

```dotenv
SPOON_SUPPORT_ENDPOINT=https://app/.../mime
SPOON_SUPPORT_KEY=key-...
SPOON_BILLING_ENDPOINT=https://other/.../mime
SPOON_BILLING_KEY=key-...
```

## Замечания

- Хорошо сочетается с #04 (фильтрация) — правила можно держать на уровне
  маршрута.
- Запуск нескольких воркеров: по одному `imap:sentry <mailbox>` на ящик под
  supervisor (см. #09).

## Definition of Done

- [ ] Письмо пересылается на эндпоинт, соответствующий его ящику.
- [ ] Откат на глобальный `endpoint/key`, если маршрут не задан.
- [ ] Тест: два ящика → два разных `Http::fake()`-адреса.
