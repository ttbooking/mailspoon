# 02. Полный payload Mailgun

**Приоритет:** 🔴 высокий · **Трудоёмкость:** S

## Проблема

Сейчас listener шлёт только сырой MIME и поля подписи:

```php
'body-mime' => (string) $event->message,
'timestamp' => ...,
'token' => ...,
'signature' => ...,
```

Этого достаточно для драйверов, которые парсят `body-mime` целиком (например
`laravel-mailbox` mailgun/mime). Но «настоящий» входящий вебхук Mailgun
содержит дополнительно разобранные поля (`recipient`, `sender`, `from`,
`subject`, `Message-Id`, `body-plain`, `body-html`, `attachment-count`,
`attachment-N`). Получатели, ожидающие классический Mailgun-payload (не
`/mime`-маршрут), сейчас работать не будут, а вложения не передаются отдельными
частями.

## Цель

Опционально отправлять расширенный payload, максимально близкий к формату
Mailgun «Routes → Store and notify», включая вложения как multipart-файлы.

## Предлагаемое решение

Расширить тело запроса (ImapEngine `MessageInterface` даёт доступ к заголовкам,
тексту и вложениям):

```php
$message = $event->message;

$payload = [
    'body-mime'   => (string) $message,
    'recipient'   => $message->to()->first()?->email(),
    'sender'      => $message->from()?->email(),
    'from'        => (string) $message->from(),
    'subject'     => $message->subject(),
    'Message-Id'  => $message->messageId(),
    'body-plain'  => $message->text(),
    'body-html'   => $message->html(),
    'timestamp'   => $timestamp,
    'token'       => $token,
    'signature'   => hash_hmac('sha256', $timestamp.$token, $this->key),
];

$request = Http::asMultipart(); // multipart нужен для файлов

foreach ($message->attachments() as $i => $attachment) {
    $request->attach("attachment-{$i}", $attachment->contents(), $attachment->filename());
}
$payload['attachment-count'] = count($message->attachments());

$request->post($this->endpoint, $payload);
```

> Точные имена методов уточнить по API ImapEngine (`->subject()`, `->from()`,
> `->attachments()` и т. п.) — при необходимости подгружать части через
> `--with=` в командах.

## Изменения конфигурации

Флаг, чтобы не ломать существующих потребителей `/mime`:

```dotenv
SPOON_PAYLOAD=mime        # mime | full
SPOON_SEND_ATTACHMENTS=true
```

При `mime` — текущее поведение; при `full` — расширенный payload выше.

## Замечания

- Вложения требуют `multipart/form-data` вместо `asForm()`.
- Большие письма/вложения — следить за `SPOON_TIMEOUT` и лимитами на стороне
  получателя.
- Стоит согласовать с командами: добавлять `--with=flags,body` чтобы части были
  доступны без отдельного fetch.

## Definition of Done

- [ ] Режим `full` шлёт разобранные поля Mailgun.
- [ ] Вложения передаются как `attachment-N`.
- [ ] Режим `mime` сохраняет текущее поведение по умолчанию.
- [ ] Тест: `Http::fake()` проверяет наличие полей и файлов (см. #08).
