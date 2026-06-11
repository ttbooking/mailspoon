# 11. OAuth2 / XOAUTH2 для IMAP

**Приоритет:** 🔴 высокий · **Трудоёмкость:** M

> Актуализация (3.0): команда — `mailspoon:oauth`; настройки OAuth — в
> опубликованном `config/mailspoon.php`, конфиг ящиков — `config/imap.php`
> (imapengine). Суть фичи переход на пакет не меняет.

## Разведка (2026-06-11, работа отложена)

Проведена разведка по коду ImapEngine v1.3 — выводы для будущей реализации.

**Что уже есть.** ImapEngine умеет XOAUTH2 из коробки:
`'authentication' => 'oauth'` → `AUTHENTICATE XOAUTH2`, access-token
передаётся как `password` (`Mailbox::authenticate()`,
`ImapConnection::authenticate()`). Протокольную часть писать не нужно.

**Подводный камень.** `Mailbox::config('password')` — `data_get()` по
статичному массиву: **closure из эскиза ниже не поддерживается** (уедет в
IMAP как есть), Mailbox строится один раз и кэшируется менеджером. Следствия:

- **cron-poll (`mailspoon:pull`) работает без upstream-правок**: каждый запуск —
  новый процесс; резолвим свежий токен и подсовываем через
  `Imap::register($name, [...конфиг, 'password' => $token])` до подключения;
- **IDLE (`mailspoon:sentry`) ломается через ~час**: токен нужен только при
  логине, но при реконнекте внутри `WatchMailbox` (ловит дисконнект и
  переподключается тем же инстансом Mailbox) логин уйдёт с протухшим токеном.

План: первая итерация с ограничением **«OAuth-ящики → только cron-poll»** +
параллельно upstream PR в ImapEngine на callable password, вычисляемый в
`authenticate()` (по образцу принятого PR с именем ящика для #03).

**Consent-флоу различается по провайдерам:**

- **Microsoft** — device code flow (`IMAP.AccessAsUser.All` + `offline_access`
  поддерживаются): команда печатает код, оператор вводит на
  microsoft.com/devicelogin. Идеально для headless-сервера.
- **Google** — device flow для Gmail-scope **запрещён**, OOB-флоу убит.
  Остаётся loopback-redirect (Desktop-клиент): `mailspoon:oauth` поднимает
  листенер на 127.0.0.1 и открывает браузер — выполняется на машине
  оператора, refresh-token потом переносится на сервер.

**Реализация в mailspoon** (новых composer-зависимостей не нужно — refresh →
access это один `Http::post`):

1. таблица токенов: refresh-token шифрованно (Crypt), по ящику; access-token
   кэшируется до истечения с запасом;
2. `TokenProvider` с абстракцией провайдера (разные endpoints/scopes);
3. `mailspoon:oauth {mailbox}` — consent-флоу по провайдеру.

**Prerequisites на стороне оператора:**

| | Google | Microsoft 365 |
|---|---|---|
| Регистрация | GCP → consent screen → Client ID типа **Desktop app** | Entra ID → App registration, **Allow public client flows** = on |
| Scopes | `https://mail.google.com/` | `IMAP.AccessAsUser.All` + `offline_access` (delegated, возможно admin consent) |
| Креды в конфиг | client_id + client_secret | client_id + tenant |
| Грабли | consent screen в статусе *Testing* → refresh-токен живёт **7 дней**; для прода — Production/Internal | refresh-токен ~90 дней скользящих; IMAP должен быть включён в Exchange Online |
| Для теста | живой Gmail-ящик | живой M365-ящик |

Протухание refresh-токена — повод для алерта (#07).

**Открытые вопросы перед стартом:** какой провайдер первым (Google/MS/оба);
есть ли уже зарегистрированные OAuth-приложения у TTBooking.

## Проблема

`config/imap.php` поддерживает только парольную аутентификацию
(`IMAP_AUTHENTICATION=plain`, `IMAP_PASSWORD`). Но крупнейшие провайдеры —
**Gmail и Microsoft 365** — отключили «обычные пароли» для IMAP и требуют
современную аутентификацию OAuth2 (механизм `XOAUTH2`). Сейчас подключить
Mailspoon к этим ящикам без «паролей приложений» (а у многих организаций они
запрещены политикой) невозможно.

## Цель

Подключение к IMAP по OAuth2 access-token с автоматическим обновлением по
refresh-token, без хранения «вечного» пароля.

## Предлагаемое решение

ImapEngine поддерживает передачу OAuth-токена как пароля при
`authentication => 'oauth'`. Нужен слой получения/обновления токена:

```php
// config/imap.php → mailbox
'authentication' => env('IMAP_AUTHENTICATION', 'plain'), // 'oauth'
'username' => env('IMAP_USERNAME'),
'password' => fn () => app(TokenProvider::class)->accessToken('default'),
```

`TokenProvider`:
- хранит `refresh_token` (зашифрованно, см. #15);
- по запросу меняет refresh → access через token-endpoint провайдера;
- кэширует access-token до истечения, обновляет заранее.

Команда первичной авторизации (consent-flow):

```bash
php artisan spoon:oauth default   # открывает URL согласия, сохраняет refresh-token
```

## Изменения конфигурации

```dotenv
IMAP_AUTHENTICATION=oauth
IMAP_OAUTH_PROVIDER=google           # google | microsoft
IMAP_OAUTH_CLIENT_ID=...
IMAP_OAUTH_CLIENT_SECRET=...
IMAP_OAUTH_TENANT=common             # для microsoft
```

## Замечания

- Refresh-token — секрет, хранить шифрованно (пересекается с #15).
- У Google и Microsoft разные scopes и token-endpoints — абстрагировать
  провайдером.
- На переавторизацию (отозванный токен) — алерт (#07).

## Definition of Done

- [ ] Подключение к Gmail/M365 по OAuth2 работает.
- [ ] Access-token автоматически обновляется по refresh-token.
- [ ] Команда первичной авторизации.
- [ ] Парольный режим сохранён по умолчанию (back-compat).
