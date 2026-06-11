# 09. Деплой и Docker

**Приоритет:** 🟢 низкий · **Трудоёмкость:** M

> 🔀 **Утратила актуальность после [#21](21-paket-laravel.md) (пакет):**
> деплой — собственность хост-приложения (его Dockerfile, supervisor, cron,
> CI). Примеры supervisor и cron `schedule:run` уже в README пакета.
> Возможный остаток фичи — пример тонкого хост-приложения в документации.
> Эскиз ниже сохранён для истории и как референс по pcntl/healthcheck.

## Проблема

Mailspoon по сути — фоновый воркер (`imap:sentry`), но в репозитории нет ни
образа, ни конфигурации супервизора, ни инструкции по эксплуатации. Каждый
разворачивает руками, а без авто-рестарта упавший IDLE-процесс останавливает
доставку.

## Цель

Воспроизводимый деплой воркера: Docker-образ + готовые конфиги supervisor и
systemd, с авто-рестартом и health-check.

## Предлагаемое решение

**Dockerfile** (php-cli, без веб-сервера — это воркер):

```dockerfile
FROM php:8.3-cli-alpine
RUN apk add --no-cache git && \
    docker-php-ext-install pcntl && \
    install-php-extensions imap
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY . .
RUN composer install --no-dev --optimize-autoloader --no-interaction
HEALTHCHECK --interval=60s CMD php artisan spoon:health || exit 1   # из #07
CMD ["php", "artisan", "imap:sentry", "default"]
```

**docker-compose.yml** — воркер + (опц.) очередь из #01 + SQLite-том из #05.

**Supervisor** (классический VPS-вариант):

```ini
[program:mailspoon]
command=php /var/www/mailspoon/artisan imap:sentry default
autostart=true
autorestart=true
startretries=10
stopwaitsecs=40
stderr_logfile=/var/log/mailspoon.err.log
```

**systemd** — юнит с `Restart=always` и `RestartSec=5` как альтернатива.

## Замечания

- Воркеру нужен `pcntl` для корректной обработки сигналов и graceful-stop по
  IDLE-таймауту.
- Несколько ящиков (#03) → несколько процессов: параметризовать `default` через
  переменную/аргумент.
- `HEALTHCHECK` завязан на #07 (`spoon:health`).
- Не забывать `php artisan config:cache` в образе для прода.

## Definition of Done

- [ ] Dockerfile собирает рабочий образ воркера.
- [ ] `HEALTHCHECK` подключён.
- [ ] Примеры supervisor и systemd с авто-рестартом.
- [ ] Краткая инструкция по эксплуатации в README.
