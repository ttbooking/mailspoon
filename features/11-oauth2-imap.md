# 11. OAuth2 / XOAUTH2 для IMAP

**Приоритет:** 🔴 высокий · **Трудоёмкость:** M

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
