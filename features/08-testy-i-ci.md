# 08. Тесты и CI

**Приоритет:** 🟡 средний · **Трудоёмкость:** S (остался только CI)

> ✅ **Реализована.** Тестовая часть выполнена в рамках #21 — Pest-тесты на
> orchestra/testbench (захват, доставка, ретраи, планировщик, маршрутизация,
> архив). CI добавлен после 3.1.0: `.github/workflows/tests.yml` — матрица
> PHP 8.3/8.4, `composer install` → `vendor/bin/pint --test` →
> `vendor/bin/pest` на push в master и каждый PR.

## Проблема

В `tests/` лежат только дефолтные `ExampleTest`. Ключевая логика —
`NotifyNewMessage` (подпись, `markSeen`, обработка ошибок) и команды
`imap:pull`/`imap:sentry` — не покрыта вообще. Любая правка (например #01)
рискует молча сломать доставку. Pest уже подключён в `require-dev`.

## Цель

Базовое, но осмысленное покрытие критичных путей + автоматический прогон в CI.

## Предлагаемое решение

**Listener (юнит/feature с `Http::fake()`):**

```php
it('posts a signed mailgun-compatible payload', function () {
    Http::fake();
    config(['spoon.endpoint' => 'https://hook.test', 'spoon.key' => 'k']);

    (new NotifyNewMessage('https://hook.test', 'k'))->handle(new MessageReceived($message));

    Http::assertSent(function ($request) {
        expect($request['signature'])
            ->toBe(hash_hmac('sha256', $request['timestamp'].$request['token'], 'k'));
        return $request->url() === 'https://hook.test';
    });
});

it('does not mark message seen when endpoint fails', function () {  // защита для #01
    Http::fake(fn () => Http::response('', 500));
    // ... ожидаем, что markSeen НЕ вызван
});
```

**Команды** — мок `Imap`-фасада (mailbox/folder/messages) и проверка, что на
каждое непрочитанное письмо диспатчится `MessageReceived` (`Event::fake()`).

**Подпись** — отдельный кейс на формулу `HMAC-SHA256(timestamp+token, key)`,
чтобы совместимость с Mailgun/laravel-mailbox не сломалась.

## CI

`.github/workflows/tests.yml`:

```yaml
name: tests
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3' }
      - run: composer install --no-interaction --prefer-dist
      - run: vendor/bin/pint --test     # стиль
      - run: php artisan test           # Pest
```

## Замечания

- `Http::fake()` + `Event::fake()` снимают зависимость от реального IMAP/HTTP.
- Для команд понадобится мок `Imap`-фасада — обернуть доступ так, чтобы его
  легко было подменить.
- Pint (`laravel/pint`) уже в зависимостях — добавить в CI как линтер.

## Definition of Done

- [x] Тесты на подпись и payload listener (в 2.x — на захват и `mailspoon:deliver`).
- [x] Тест «не помечаем seen при ошибке» (страхует #01) — в store-and-forward:
      письмо не помечается, пока не зафиксировано в журнале и архиве.
- [x] Тест диспатча события командой `mailspoon:pull`.
- [x] CI-workflow: install + pint + test.
