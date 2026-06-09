# 06. Действия после пересылки

**Приоритет:** 🟢 низкий · **Трудоёмкость:** S

## Проблема

Единственное действие после доставки — `markSeen()`. Письма копятся в INBOX
прочитанными навсегда. Нет способа архивировать обработанное, складывать
ошибочное в отдельную папку или удалять после пересылки — а это типичные
сценарии гигиены ящика-«робота».

## Цель

Настраиваемое действие над письмом по итогу обработки: пометить прочитанным,
переместить в папку, скопировать, удалить, добавить флаг/метку.

## Предлагаемое решение

Конфиг с действиями для разных исходов (ImapEngine поддерживает move/copy/delete
и работу с флагами):

```php
'after' => [
    'delivered' => 'move:Processed',   // mark_seen | move:<folder> | delete | flag:<name>
    'failed'    => 'move:Failed',
    'filtered'  => 'mark_seen',        // из #04
],
```

В listener после успеха/провала:

```php
match (true) {
    str_starts_with($action, 'move:')  => $message->move(substr($action, 5)),
    $action === 'delete'               => $message->delete(),
    str_starts_with($action, 'flag:')  => $message->flag(substr($action, 5)),
    default                            => $message->markSeen(),
};
```

## Изменения конфигурации

```dotenv
SPOON_AFTER_DELIVERED=move:Processed
SPOON_AFTER_FAILED=move:Failed
```

## Замечания

- Целевые папки должны существовать (или создавать через `folders()->create()`).
- `delete` необратимо — по умолчанию оставить `mark_seen`.
- Логику исходов согласовать с #01 (что считать «failed» после ретраев) и #04.

## Definition of Done

- [ ] Поддержка `mark_seen` / `move:` / `delete` / `flag:`.
- [ ] Разные действия для delivered/failed/filtered.
- [ ] Безопасные дефолты (никаких удалений по умолчанию).
- [ ] Тест на каждый тип действия.
