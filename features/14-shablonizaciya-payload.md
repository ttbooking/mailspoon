# 14. Шаблонизация payload (другие форматы получателей)

**Приоритет:** 🟡 средний · **Трудоёмкость:** M

## Проблема

Формат запроса жёстко зашит под Mailgun (`body-mime` + подпись). Если получатель
ждёт другой формат — входящий вебхук SendGrid, обычный JSON, сообщение в Slack,
кастомная схема внутреннего API — Mailspoon не подходит без правки кода. Формат
доставки не отделён от механики реле.

## Цель

Подключаемые «форматтеры» payload, выбираемые конфигом, с возможностью добавить
свой без изменения ядра.

## Предлагаемое решение

Интерфейс форматтера, превращающего письмо в тело запроса:

```php
interface PayloadFormatter
{
    public function format(MessageInterface $message): PendingRequest|array;
}
```

Встроенные реализации:
- `MailgunMimeFormatter` — текущее поведение (по умолчанию);
- `MailgunFullFormatter` — расширенный payload (#02);
- `JsonFormatter` — `{ "from", "subject", "html", "text", "raw" }` как JSON;
- `SlackFormatter` — краткое уведомление в Slack incoming webhook.

Выбор в конфиге (глобально или на назначение из #13):

```php
'format' => env('SPOON_FORMAT', 'mailgun-mime'),
'formatters' => [
    'mailgun-mime' => MailgunMimeFormatter::class,
    'mailgun-full' => MailgunFullFormatter::class,
    'json'         => JsonFormatter::class,
    'slack'        => SlackFormatter::class,
],
```

Listener остаётся тонким: берёт форматтер по конфигу, формирует запрос, шлёт.

## Изменения конфигурации

```dotenv
SPOON_FORMAT=mailgun-mime    # mailgun-mime | mailgun-full | json | slack
```

## Замечания

- Подпись/секрет имеют смысл не для всех форматов (у Slack — свой URL-секрет) —
  вынести аутентификацию в стратегию форматтера.
- Естественно сочетается с #13 (разный формат на разные назначения) и #02
  (full — частный случай форматтера).

## Definition of Done

- [ ] Интерфейс `PayloadFormatter` + выбор через конфиг.
- [ ] Встроенные форматтеры mailgun-mime/full/json.
- [ ] Документировано добавление своего форматтера.
- [ ] Дефолт сохраняет текущее Mailgun-mime поведение.
