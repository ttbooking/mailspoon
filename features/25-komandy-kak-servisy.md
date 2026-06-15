# 25. Команды как сервисы со структурным результатом

**Приоритет:** 🟡 средний · **Трудоёмкость:** M

> ✅ Реализовано: сервисы `Doctor`/`Replay`/`Deliverer` со структурными
> результатами (`DoctorReport`/`ReplayResult`/`DeliverySummary`, все
> `Arrayable`+`JsonSerializable`); команды стали тонкими адаптерами. Вытекает
> из #24: динамические ящики обслуживаются программно (из веб-слоя хоста), без
> скрапинга консоли. Пакет HTTP-слой не шипит — контроллеры строит хост.

## Проблема

Операции Mailspoon живут в artisan-командах (`mailspoon:doctor`,
`mailspoon:replay`, `mailspoon:deliver`, …). Команда — это CLI-адаптер: ввод
через сигнатуру, вывод через Symfony Console (таблицы, строки), результат —
exit-код. Для веба это неудобно:

- `Artisan::call('mailspoon:doctor', [...])` + `Artisan::output()` отдаёт
  **текст** (отрендеренную таблицу), а не данные — UI пришлось бы парсить
  консольный вывод;
- логика заперта в `handle()`: переиспользовать её из контроллера, Job'а или
  очереди нельзя, не подняв весь консольный аппарат;
- exit-код (`SUCCESS`/`INVALID`) — слишком грубо для UI, которому нужны детали
  (какая проверка doctor'а упала и почему; какие письма переотправлены).

С динамическими ящиками (#24) это становится узким местом: завёл ящик в UI —
а продиагностировать его «здоровье» или переотправить зависшие письма из того
же UI нечем, кроме SSH в консоль.

## Цель

Вынести тело команд в **invokable-сервисы**, возвращающие структурные объекты
результата. Команды становятся тонкими CLI-адаптерами над сервисами (рендерят
тот же результат в консоль — поведение CLI не меняется), а хост вызывает сервисы
**напрямую** и получает данные, годные для JSON-ответа или дальнейшей обработки.

**Пакет по-прежнему не шипит HTTP-слой** (нет `illuminate/routing`/`view` в
зависимостях — сознательно, см. #21). Контроллеры, маршруты и UI строит хост
поверх сервисов. Это и есть программная подложка под отложенный веб-дашборд
(#12): не «дашборд от пакета», а сервисы, на которых хост соберёт свой экран.

## Что это даёт

- Хост зовёт `Doctor`/`Replay` из контроллера/Job'а → структурный результат,
  без парсинга консоли.
- Тестируется чище: сервис проверяется ассертами по объекту результата, а не по
  строкам вывода.
- CLI не ломается: команды остаются, просто худеют до адаптеров.
- Ровно ложится на #24: динамические ящики обслуживаются из того же UI.

## Предлагаемое решение

### Сервис + объект результата

На примере `doctor` (самый ценный для UI — у него богатый структурный итог):

```php
namespace TTBooking\Mailspoon\Services;

final class Doctor
{
    /** @param list<string> $mailboxes Empty = all configured. */
    public function run(array $mailboxes = [], bool $send = false): DoctorReport { /* ... */ }
}
```

```php
namespace TTBooking\Mailspoon\Results;

use Illuminate\Contracts\Support\Arrayable;use JsonSerializable;

/** Per-mailbox diagnostic outcome. */
final readonly class DoctorReport implements Arrayable, JsonSerializable
{
    /** @param list<MailboxCheck> $checks */
    public function __construct(public array $checks) {}

    public function ok(): bool { /* все проверки passed */ }
    // toArray()/jsonSerialize() → пригодно для response()->json($report)
}

/** Одна проверка: route / capture / imap / endpoint. */
final readonly class MailboxCheck
{
    public function __construct(
        public string $mailbox,
        public string $check,           // 'route' | 'capture' | 'imap' | 'endpoint'
        public CheckStatus $status,     // enum: Ok | Warn | Fail | Skipped
        public string $message,
    ) {}
}
```

Ключевой момент: сервис **не бросает** на проваленной проверке (как сейчас
`DoctorCommand` ловит `Throwable` и красит строку), а складывает исход в
`MailboxCheck` — так UI получает полную картину, а не первую ошибку. Команда
`mailspoon:doctor` рендерит `DoctorReport` в текущую таблицу и выбирает exit-код
по `->ok()`. Побочные эффекты (живой коннект к IMAP, проба эндпоинта, `--send`)
остаются под контролем флагов — поведение и риск не меняются.

### Аналогично для остальных

- **`Replay` → `ReplayResult`.** Сейчас `ReplayMessagesCommand` выбирает письма
  по `id`/`--failed`/`--mailbox` и зовёт `$message->replay()`. Выносим в
  `Replay::run(ReplayCriteria $criteria): ReplayResult` (число и список
  переотправленных — fingerprint/mailbox/endpoint). Команда печатает строки.
- **`Deliverer` → `DeliverySummary`** (опционально). `DeliverMessagesCommand`
  уже считает `delivered`/`failed` — естественно вернуть это структурой; но
  команда процессная (лимиты, бэкофф), для «вызвал-получил» менее очевидна.
- **`pull`/`sentry`** — процессные/долгоживущие, не «вызвал-получил». Оставляем
  командами; в рамки фичи не входят.

### Использование хостом

```php
public function show(Doctor $doctor, string $mailbox)
{
    return response()->json($doctor->run([$mailbox]));
}
```

## Связь с другими фичами

- **#24** — динамические ящики обслуживаются из того же UI этими сервисами.
- **#12 (веб-дашборд)** — переопределяется: пакет даёт сервисы и структурные
  результаты, экран строит хост. Снимает вопрос «дашборд дорого шипить из пакета».

## Ограничения и риски

- `Doctor` делает реальные сетевые операции (IMAP-коннект, HTTP-проба) — это
  по-прежнему синхронно и с таймаутами; в вебе такой эндпоинт лучше гонять через
  Job/очередь, а не в реквест-цикле. Отметить в README.
- `Replay` мутирует БД (сбрасывает статусы) — обычное действие, но в UI требует
  авторизации на стороне хоста.
- Структурные DTO — часть публичного API: их форма попадает под SemVer.

## План реализации

### Этап 1 — Doctor (наибольшая отдача) ✅
- [x] `Services/Doctor`, `Results/DoctorReport` + `Check` + `CheckStatus`
      (общий `Check` с `mailbox: ?string` вместо `MailboxCheck` — покрывает и
      общие проверки database/archive).
- [x] Логика перенесена из `DoctorCommand` в сервис; команда рендерит отчёт.
- [x] Тесты: `DoctorTest` (статусы, упавшая проверка не прерывает прогон, JSON);
      `DoctorCommandTest` (рендер) зелёный без правок.

### Этап 2 — Replay ✅
- [x] `Services/Replay` + `ReplayCriteria`, `Results/ReplayResult` + `ReplayedEntry`.
- [x] `ReplayMessagesCommand` → адаптер; CLI-поведение и exit-коды без изменений.
- [x] `ReplayTest` (выборка по id/failed/mailbox, бросок при пустом критерии,
      пустой результат, JSON); `ReplayMessagesCommandTest` зелёный без правок.

### Этап 3 — Deliverer + документация ✅
- [x] `Services/Deliverer` → `Results/DeliverySummary` (delivered/failed/total);
      `pending()` для dry-run-выборки без побочек. Ретраи/бэкофф/события и
      `DeliveryPermanentlyFailed` переехали в сервис; команда — адаптер,
      dry-run-таблица осталась в команде.
- [x] `DelivererTest`; `DeliverMessagesCommandTest` зелёный без правок.
- [x] README: раздел «Вызов операций из приложения» — примеры `Doctor`/`Replay`/
      `Deliverer`, `response()->json()`, рекомендация про очередь и авторизацию,
      напоминание что пакет HTTP-слой не шипит.

## Версионирование

Аддитивно: новые сервисы и DTO, команды сохраняют CLI-поведение. Новый публичный
API (сервисы, формы результатов) → **minor**. Пакет HTTP-слой не добавляет.

## Definition of Done

- [x] `Doctor`/`Replay`/`Deliverer` доступны как сервисы, возвращают структурный
      результат, пригодный для `response()->json()`.
- [x] Команды `mailspoon:doctor`/`:replay`/`:deliver` работают как раньше (рендер
      поверх сервисов), их тесты зелёные.
- [x] Тесты сервисов с ассертами по объектам результата.
- [x] README: вызов операций из приложения, заметки про очередь и авторизацию.