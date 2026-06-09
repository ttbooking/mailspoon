# 16. Планировщик (cron) для pull

**Приоритет:** 🟢 низкий · **Трудоёмкость:** S

## Проблема

Сейчас режима два: разовый `imap:pull` (запускать чем-то извне) и долгоживущий
`imap:sentry` на IDLE. Для лёгких сценариев (нечастая почта, окружения без
возможности держать постоянный процесс, shared-хостинг) держать воркер на IDLE
избыточно, а ручной cron-вызов `imap:pull` нигде не описан и не настроен в
приложении. `routes/console.php`/scheduler не используются.

## Цель

Встроенное расписание периодического `imap:pull` по нескольким ящикам, как
альтернатива постоянному `imap:sentry`.

## Предлагаемое решение

Описать расписание в `routes/console.php` на основе конфига:

```php
use Illuminate\Support\Facades\Schedule;

foreach (config('spoon.schedule', []) as $mailbox => $cron) {
    Schedule::command('imap:pull', [$mailbox])
        ->cron($cron)
        ->withoutOverlapping()        // не запускать параллельно тот же ящик
        ->runInBackground();
}
```

Тогда на сервере достаточно одной строки системного cron:

```cron
* * * * * cd /path/to/mailspoon && php artisan schedule:run >> /dev/null 2>&1
```

## Изменения конфигурации

```php
// config/spoon.php
'schedule' => [
    'default' => '*/5 * * * *',   // каждые 5 минут
    'billing' => '*/15 * * * *',
],
```

## Замечания

- `withoutOverlapping()` важен, чтобы долгая выборка не накладывалась на
  следующий тик.
- Это альтернатива #09 (постоянный воркер), а не замена — выбирается по нагрузке
  ящика и инфраструктуре.
- Латентность доставки = период cron (минуты), против near-real-time у IDLE.

## Definition of Done

- [ ] Расписание `imap:pull` по ящикам из конфига.
- [ ] `withoutOverlapping` включён.
- [ ] Инструкция по системному cron в README.
