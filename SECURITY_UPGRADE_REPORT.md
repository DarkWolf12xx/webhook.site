# Webhook.site: обновление PHP и устранение уязвимостей

Отчёт о модернизации runtime, аудите безопасности и hardening самостоятельно
развёртываемой (open-source) версии Webhook.site.

- Дата работ: 2026-11-09
- Исходное состояние: коммит `96b7b42` (ветка `master`)
- Ветка с изменениями: `claude/gracious-tesla-38np6d`
- Значения секретов в отчёте не приводятся. В репозитории реальных секретов не
  найдено (см. раздел 4.6).

---

## 1. Версии: было → стало

| Компонент | Было | Стало |
|---|---|---|
| **PHP** (CLI, PHP-FPM, queue worker) | **7.4.27** (EOL с 28.11.2022) | **8.5.12** (21.10.2026) |
| Базовый образ | `bkuhl/fpm-nginx:7.4` (Alpine **3.15.0**, EOL, плавающий тег) | `php:8.5.12-fpm-alpine3.23@sha256:35eb24f8…` (Alpine **3.23.8**) |
| nginx | 1.17.6 | 1.28.3 (Alpine 3.23, серия `~1.28`) |
| Супервизор процессов | s6-overlay v2 (из bkuhl) | s6-overlay 3.2.0.3 (Alpine) |
| Laravel | 5.4.36 (EOL 2018) | **13.40.0** |
| Symfony components | 3.4 / 4.2 | 8.1 |
| Composer | в runtime-образе | 2.11.1, только в build-стадии (в runtime не попадает) |
| Redis (compose/helm) | `redis:alpine` (плавающий) | `redis:8.10.3-alpine@sha256:71885d8c…` (тот же digest, что сейчас у `redis:alpine` — версия фактически не менялась) |
| laravel-echo-server | `webhooksite/laravel-echo-server` (плавающий) | тот же образ, закреплён по digest `sha256:b013ed5b…` |
| Node (сборка фронтенда) | `node:11` (плавающий) | `node:11.15.0@sha256:67ca28ad…` (тот же релиз, только build-стадия) |
| PHPUnit | 5.7 | 12.5 |
| Размер runtime-образа | 529 MB | 221 MB |

Все процессы, использующие PHP, работают на одном бинарнике 8.5.12 — проверено в
работающем контейнере (`php -v` → `PHP 8.5.12 (cli)`, `php-fpm -v` →
`PHP 8.5.12 (fpm-fcgi)`, `queue:work` запускается тем же `php`).

## 2. Почему PHP 8.5.12

Источники (проверены 2026-11-09):

- https://www.php.net/supported-versions.php:

  | Ветка | Active support до | Security support до |
  |---|---|---|
  | 8.2 | 31.12.2024 | 31.12.2026 |
  | 8.3 | 31.12.2025 | 31.12.2027 |
  | 8.4 | 31.12.2026 | 31.12.2028 |
  | **8.5** | **31.12.2027** | **31.12.2029** |

- https://www.php.net/releases/ (`?json&version=8.5`): последний релиз ветки 8.5 —
  **8.5.12 от 21.10.2026**, security-релиз, закрывающий две критические RCE
  (CVE-2026-109618, CVE-2026-109619, CVSS 4.0 9.2).
- Новости php.net: **PHP 8.6.0 — только RC5** (05.11.2026); RC/beta/alpha не
  используются. В репозитории php-src для 8.5.13 есть только `RC1`.
- Официальный образ `php:8.5.12-fpm-alpine3.23` собран 05.11.2026.
- Информационное уведомление php.net от 23.10.2026: держать
  `session.upload_progress.enabled=0` до следующего bugfix-релиза — применено в
  `docker/php/php.ini`.

Выбрана самая новая поддерживаемая ветка (8.5) и её последний стабильный patch.
Ограничений, вынуждающих взять более старую ветку, нет: Laravel 5.4 с PHP 8 не
работает, но Laravel 13 (требует PHP ^8.3, поддерживает 8.5) снимает проблему, а все
используемые пакеты имеют релизы, совместимые с 8.5.

Примечание: изначально песочница блокировала `www.php.net`; хост был добавлен в
allowlist окружения, данные взяты с php.net напрямую и сверены с тегами
`github.com/php/php-src`.

## 3. Composer-зависимости

`composer.json`: `"php": "^8.5"`, `config.platform.php = 8.5.12` (lock-файл всегда
разрешается под production runtime), явные `ext-*`.

| Пакет | Было | Стало | Причина |
|---|---|---|---|
| laravel/framework | 5.4.36 | 13.40.0 | 14 advisories (1 critical, 7 high), несовместим с PHP 8 |
| symfony/* (http-foundation, http-kernel, routing, process, console, …) | 3.4.23 / 4.2.4 | 8.1.x | 3.4 EOL, advisories (в т.ч. critical CVE-2019-10913) |
| league/flysystem | 1.0.50 | 3.36.0 | critical CVE-2021-32708 |
| swiftmailer/swiftmailer | 5.4.12 | удалён (symfony/mailer) | advisory, abandoned |
| nesbot/carbon | 1.36.2 | 3.15.1 | advisory |
| monolog/monolog | 1.24.0 | 3.12.1 | требование Laravel 13 |
| predis/predis | 1.1.1 | 3.7.1 | совместимость с PHP 8.5 / Laravel 13 |
| pusher/pusher-php-server | 2.6.4 | 7.3.0 | требование Laravel broadcasting |
| sentry/sentry-laravel | 0.8.1 | 4.29.0 (sentry/sentry 4.36.0) | совместимость |
| ramsey/uuid | 3.8.0 (транзитивно) | 4.9.4 (явно) | используется напрямую |
| vlucas/phpdotenv | 2.6.1 | 5.8.0 | требование Laravel 13 |
| webpatser/laravel-uuid | 2.2.1 | **удалён** | не используется |
| doctrine/dbal | 2.9.2 | **удалён** | Laravel 11+ меняет колонки без DBAL |
| dev: phpunit/phpunit | 5.7.27 | 12.5.43 | advisory (high), совместимость |
| dev: fzaninotto/faker | 1.8.0 | fakerphp/faker 1.24.1 | abandoned, падал на PHP 7.4 |
| dev: mockery/mockery | 0.9.11 | 1.6.15 | совместимость |
| dev: symfony/css-selector, dom-crawler | 3.1.10 | **удалены** | не используются |
| dev: larastan/larastan | — | 3.14.0 (phpstan 2.3.5) | статический анализ в CI |

`composer audit`:
- **до**: 38 advisories в 11 пакетах — 3 critical, 13 high, 11 medium, 7 low,
  4 без оценки (laravel/framework, symfony/http-foundation, http-kernel, routing,
  process, yaml, dom-crawler, league/flysystem, swiftmailer, nesbot/carbon,
  phpunit) + abandoned `phpunit/phpunit-mock-objects`;
- **после**: `No security vulnerability advisories found` (включая dev-зависимости).

> Примечание: в сообщении коммита `42fc43e` ошибочно указано «4 critical, 14 high»;
> верные цифры — приведённые выше. История не переписывалась.

## 4. Результаты аудита

### 4.1 Инструменты

| Инструмент | Версия | Что проверялось | Итог |
|---|---|---|---|
| composer audit | Composer 2.11.1 | PHP-зависимости | 38 → 0 |
| Trivy | 0.69.1 (DB от 2026-11-09) | ОС-пакеты и vendor в образе | 18 C / 74 H / 66 M / 8 L → **0** |
| Semgrep | 1.140.0, 390 правил из `semgrep/semgrep-rules@16b9e6d` (php, dockerfile, nginx, secrets, docker-compose, js, html) | код, конфиги | 9 срабатываний, разбор в 4.3 |
| PHPStan + Larastan | 2.3.5 / 3.14.0, level 5 | app, config, database, tests | 0 новых ошибок (13 унаследованных неточностей PHPDoc в baseline) |
| PHP_CodeSniffer + PHPCompatibility | 3.13.6 + 10.0.0-alpha2, `testVersion 8.5` | совместимость кода с PHP 8.5 | 70 файлов, 0 замечаний |
| gitleaks | 8.28.0 | вся история git (92 коммита) | 8 срабатываний, реальных секретов нет (4.6) |
| npm audit | npm 10 (Node 22) | фронтенд-зависимости | 132 advisories, см. раздел 8 |
| Динамические проверки | curl, redis-cli, Playwright/Chromium | исходный стек (PHP 7.4) и новый стек | все подтверждённые проблемы воспроизведены «до» и проверены «после» |
| Ручной code review | — | весь PHP/JS код приложения, nginx, FPM, Dockerfile, compose, helm | раздел 4.2 |

Semgrep registry (`semgrep.dev`) недоступен из песочницы, поэтому правила взяты из
официального репозитория правил. PHPCompatibility для PHP 8.x существует только в
ветке 10.0 (alpha); использована как внешний инструмент анализа, в проект не
добавлена.

### 4.2 Ручной анализ по классам уязвимостей

| Класс | Вывод |
|---|---|
| SQL Injection | SQL с пользовательскими данными нет; SQLite используется только для `failed_jobs` (фреймворк, параметризованные запросы). Redis-ключи строятся из ID, ограниченных UUID-паттерном маршрута. Не обнаружено. |
| XSS | **Найдено**: хранимый XSS через пользовательский ответ webhook-URL (V-01), отсутствие CSP/anti-framing в UI (V-11). Шаблоны Angular экранируют вывод; `hljs` экранирует подсветку. |
| CSRF | API бессостоятельное: нет cookie/сессий — CSRF неприменим. Webhook-эндпоинты обязаны принимать кросс-сайтовые запросы (назначение сервиса). Единственный маршрут с сессией (`broadcasting/auth`) защищён `VerifyCsrfToken`. |
| SSRF | Серверных исходящих HTTP-запросов с пользовательским URL нет (Sentry/Pusher — фиксированные хосты из конфигурации). Функция «Redirect» выполняется **в браузере** пользователя (XHR) — описана в разделе 8. |
| RCE / Command Injection | `BlockIp/UnblockIp` запускали `sudo ufw …` через shell с IP, проверенным `filter_var` (не эксплуатируемо); переведены на массив аргументов без shell. **Найдено**: открытый FastCGI-порт (V-02) и инъекция команд в «Copy as cURL» на машине пользователя (V-05). |
| Path Traversal / LFI / RFI | Операций с файлами по пользовательскому вводу нет; `allow_url_include=Off`, `allow_url_fopen=Off`. nginx выполнял любой `*.php` из `public/` — ограничен до `index.php` (V-14). |
| Небезопасная десериализация | Данные токенов/запросов — JSON. `unserialize` применяется фреймворком к задачам очереди и кэшу в Redis; Redis изолирован во внутренней сети, кэш ограничен скалярами (`serializable_classes=false`, V-16). |
| Загрузка файлов | Файлы не сохраняются: PHP кладёт части multipart во временные файлы и удаляет их в конце запроса; лимиты `upload_max_filesize=1M`, `max_file_uploads=10`, nginx `client_max_body_size 1m`. |
| Open Redirect | Редиректов на пользовательские URL нет. |
| HTTP-заголовки | **Найдено**: CR/LF и мусор в пользовательском Content-Type, невалидные статусы → HTTP 500 (V-07). `underscores_in_headers on` сохранён (нужен для захвата, #160); прокси-заголовкам приложение не доверяет. |
| Authentication / Access control / IDOR | Модель «capability URL»: UUIDv4-токен (122 бита из CSPRNG) — единственный секрет доступа, учётных записей нет. Поиск запросов ограничен хешем своего токена (чужой запрос по ID не прочитать). **Найдено**: подмена `token_id` через ввод (V-09). |
| Слабая случайность | `ramsey/uuid` v4 → `random_bytes`; CSP-nonce → `random_bytes`. Проблем нет. |
| Сессии / API-токены | Токен URL попадал в лог Laravel и в access-лог FPM (V-13). Нативные PHP-сессии не используются; php.ini всё равно усилен. |
| Ресурсы / DoS | **Найдено**: неограниченное создание URL (V-06); полезная нагрузка >1 МБ рассылалась подписчикам целиком (V-10). Задержка ответа до 10 с — особенность продукта (раздел 8). |
| Отображение webhook-данных | Raw-просмотр отдавал содержимое отправителя с исходного origin (V-01). «Copy as cURL» (V-05). |
| Секреты / env / логи | Утечки трассировок при `APP_DEBUG` (V-03), `display_errors` (V-04), пример ключа echo-server (V-15). Конфигурация берётся из env; `.env` исключён из git и из build-контекста. |
| Временные файлы, права ФС | Код приложения теперь принадлежит root и недоступен PHP на запись; запись только в `storage/`, `bootstrap/cache/`, `database/` (V-08). |

### 4.3 Разбор срабатываний Semgrep

| Правило | Место | Вердикт |
|---|---|---|
| possible-nginx-h2c-smuggling | `nginx.conf` (proxy `/socket.io`) | **Принято**: проксируется только `Upgrade: websocket` (V-16, `888f701`) |
| missing-image-version ×2 | `Dockerfile`: `FROM base AS …` | ложное: ссылка на внутреннюю стадию |
| prefer-json-notation | `HEALTHCHECK CMD …` | ложное/информационное: сигналы для healthcheck не нужны |
| laravel-cookie-null-domain | `config/session.php` | ложное: `env('SESSION_DOMAIN')` по умолчанию `null` |
| request-host-used | `proxy_set_header Host $host` | информационное: echo-server не использует Host для решений безопасности |
| unsafe-dynamic-method, js-open-redirect-from-function | `public/assets/scripts/libs/autotrack.js` | сторонняя библиотека GA, нигде не подключается (мёртвый файл) |
| tainted-filename | `server.php` | роутер встроенного dev-сервера `artisan serve`; в Docker/nginx не используется |

### 4.4 Trivy

| Образ | CRITICAL | HIGH | MEDIUM | LOW |
|---|---|---|---|---|
| Исходный (`bkuhl/fpm-nginx:7.4` + vendor) | 18 | 74 | 66 | 8 |
| Новый (до `apk upgrade`) | 0 | 0 | 1 (zlib, CVE-2026-4738) | 0 |
| **Новый, итоговый** | **0** | **0** | **0** | **0** |
| `redis:8.10.3-alpine` (как есть) | 0 | 0 | 1 | 0 |
| `webhooksite/laravel-echo-server` (сторонний, 2023 г.) | 9 | 126 | 189 | 130 |

### 4.5 Исходное поведение (воспроизведено на сборке исходного Dockerfile)

Все проблемы из раздела 5 были подтверждены на работающем стеке до изменений:
HTML-ответ `<script>…</script>` отдавался с `Content-Type: text/html` без
ограничений; `nc` из соседнего контейнера подключался к порту 9000; JSON ошибок
содержал `trace`/`file`/`line`; `?tokenId=<чужой>` приводил к публикации события в
канал чужого токена (проверено `redis-cli SUBSCRIBE`); два переключения CORS
возвращали `{"enabled":true}`; статус 999 и CR/LF в Content-Type давали 500;
`/latest` на пустом URL — 502; вставка «Copy as cURL» выполняла
`touch PWNED_BY_HEADER`.

### 4.6 Секреты (gitleaks)

8 срабатываний `generic-api-key`, реальных секретов нет:
- UUID тестовых фикстур (`tests/Compatibility/LegacyDataCompatibilityTest.php`) и
  примеры ID в старом `docs/api.md` (удалён ранее) — ложные;
- `laravel-echo-server.json.example` в коммитах 2019 г. содержал конкретные
  `appId`/`key`; в текущей версии заменены плейсхолдерами (V-15). **Если
  `laravel-echo-server.json` когда-либо создавался из старого примера — сменить
  ключ.** История git не переписывалась.

## 5. Исправленные проблемы

| ID | Проблема | Критичность | Компонент | Первопричина | Исправление | Тесты | Коммит |
|---|---|---|---|---|---|---|---|
| V-18 | 38 известных уязвимостей зависимостей | **Critical** | Composer | Laravel 5.4/Symfony 3.4 EOL | Laravel 13 / Symfony 8.1, удаление неиспользуемых пакетов | `composer audit` (CI) | `42fc43e` |
| V-19 | EOL-рантайм и ОС: PHP 7.4, Alpine 3.15 | **Critical** | Docker-образ | неподдерживаемый базовый образ | `php:8.5.12-fpm-alpine3.23` по digest, `apk upgrade` | `PlatformTest`, Trivy | `5af074d`, `877d81b` |
| V-01 | Хранимый XSS: пользовательский HTML-ответ и raw-просмотр в origin приложения → кража токенов из localStorage | **High** | `RequestController::create/raw` | контент третьих лиц отдаётся с origin приложения без изоляции | `Content-Security-Policy: sandbox` + `nosniff` (настраивается `WEBHOOK_RESPONSE_CSP`) | `WebhookResponseSandboxTest` | `1371964` |
| V-02 | PHP-FPM слушал `0.0.0.0:9000` (FastCGI доступен соседним контейнерам/подам → выполнение кода) | **High** | PHP-FPM | `zz-docker.conf` базового образа, `EXPOSE 9000` | `listen = 127.0.0.1:9000` | CI docker-job (`nc` из backend-сети) | `4eb7ce9` |
| V-03 | Стек-трейсы, пути и классы в ответах (`APP_DEBUG`) | **Medium** | `config/app.php`, compose, helm | debug по умолчанию `true`, в манифестах `true` | default `false`, манифесты `false` | `ErrorDisclosureTest` | `4eb7ce9`, `2e30107` |
| V-04 | `display_errors` в HTTP-ответ, версии в `X-Powered-By`/`Server` | **Medium** | PHP, nginx | нет php.ini (dev-умолчания), `server_tokens on` | php.ini-production + `docker/php/php.ini`, `server_tokens off`, `fastcgi_hide_header` | smoke (заголовки) | `4eb7ce9` |
| V-05 | Инъекция shell-команд через «Copy as cURL»; `-d @file` выгружал локальный файл | **Medium** | `resources/assets/js` | конкатенация значений отправителя в shell-строку | `curl.js`: shell-quoting каждого значения, `--data-raw` | `tests/js/curl.test.js` | `26c3582` |
| V-06 | Неограниченное создание URL → исчерпание памяти Redis | **Medium** | `POST /token` | нет rate limiting | именованный лимитер по IP (`WEBHOOK_TOKEN_RATE_LIMIT`, 60/мин), `ValidatePostSize`, опц. `REDIS_MAXMEMORY` | `ResourceLimitsTest` | `c2231f8` |
| V-07 | Невалидные настройки ответа (статус 999, CR/LF в Content-Type) и пути вида `/<token>/2024-01-01` → HTTP 500 | **Medium** | `CreateTokenRequest`, `RequestController` | слабая валидация, неякорный regex | правила валидации, `\A[1-5]\d{2}\z`, fallback для старых данных | `ResponseSettingsValidationTest` | `9f39ac8` |
| V-08 | Все процессы под root, код доступен на запись, нет cap_drop/no-new-privileges, redis/echo в общей сети | **Medium** | Docker, compose, helm | конфигурация образа и манифестов | `USER www-data`, порт 8080, код `root:root`, cap_drop ALL, internal-сеть, securityContext | CI docker-job, smoke | `4eb7ce9` |
| V-09 | Подмена `token_id` параметром `tokenId` → события в чужой realtime-канал | **Low** | `Storage\Request::createFromRequest` | `$request->tokenId` берётся из input раньше маршрута | ID токена передаётся явно | `RequestTokenBindingTest` | `ad84809` |
| V-10 | CORS невозможно выключить; усечение >1 МБ broadcast не работало | **Low** | `Storage\Entity` | нет `__isset/__unset` | `__isset`, `__unset`, null-safe `__get` | `EntityAttributeHandlingTest` | `25c7874` |
| V-11 | Clickjacking UI, отсутствие CSP | **Low** | `IndexController`, view | заголовки не задавались | CSP с nonce (без unsafe-inline/eval для скриптов), `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy` | `WebUiSecurityHeadersTest`, Playwright | `0f9a013` |
| V-12 | `GET …/request/latest` на пустом URL → 500/502 | **Low** | `RequestController::latest` | неимпортированный класс, `empty()` на коллекции | импорт, `isEmpty()`, 404 | `ErrorDisclosureTest` | `2e30107` |
| V-13 | Токен URL (bearer-секрет) в логах | **Low** | `TokenController`, FPM | логирование ID и URI | ID убран из лога; `access.format` без URI | `ErrorDisclosureTest` | `2e30107`, `4eb7ce9` |
| V-14 | nginx исполнял любой `*.php`, отдавал `.htaccess`/`web.config`; webhook-пути на `.php` не захватывались | **Low** | nginx | regex-location на все `.php` | только `/index.php` в FPM, прочее — в приложение | smoke | `4eb7ce9` |
| V-15 | Конкретные appId/key в примере конфигурации echo-server | **Low** | `laravel-echo-server.json.example` | пример с реальными значениями | плейсхолдеры, `devMode:false` | — | `4eb7ce9` |
| V-16 | Десериализация объектов из кэша; проброс любых `Upgrade` (h2c) | **Low** (hardening) | `config/cache.php`, nginx | умолчания | `serializable_classes=false`, только `Upgrade: websocket` | `CacheUnserializeTest`, проверка 101/200 | `888f701` |
| V-17 | Данные Redis терялись при пересоздании контейнера (в т.ч. при обновлении) | **Medium** (целостность данных) | docker-compose | образ redis без `VOLUME` | именованный том `redis-data` | проверка `--force-recreate` | `f93ba3c` |

Регрессии, вызванные самим обновлением и исправленные в `42fc43e`: raw-просмотр
запроса без Content-Type давал 500 на PHP 8/Symfony 8 (`RawContentTest`),
`sprintf()` с недостающими аргументами (`ArgumentCountError`), `Process` со
строкой команды, `Request::get()`.

## 6. Изменения конфигураций

| Файл | Изменения |
|---|---|
| `Dockerfile` | 4 стадии (npm → base → vendor → runtime); всё по версии+digest; `apk upgrade`; nginx/s6 из Alpine; `pcntl`; php.ini-production + `docker/php/php.ini`; пул FPM; composer только в vendor-стадии; код `root:root`, запись только в `storage`, `bootstrap/cache`, `database`; `USER www-data`; `EXPOSE 8080`; `HEALTHCHECK` |
| `docker/php/php.ini` | `expose_php=Off`, `display_errors=Off`, `display_startup_errors=Off`, `log_errors=On` (stderr), `allow_url_include=Off`, `allow_url_fopen=Off`, `memory_limit=${PHP_MEMORY_LIMIT}` (128M), `max_execution_time=30`, `max_input_time=30`, `post_max_size=2M`, `upload_max_filesize=1M`, `max_file_uploads=10`, `session.use_strict_mode=1`, `use_only_cookies=1`, `use_trans_sid=0`, `cookie_httponly=1`, `cookie_secure=${PHP_SESSION_COOKIE_SECURE}`, `cookie_samesite=Lax`, `session.upload_progress.enabled=0`, OPcache |
| `docker/php-fpm/zzz-webhook.conf` | `listen=127.0.0.1:9000`, `pm.max_children=${PHP_FPM_MAX_CHILDREN}` (20), `pm.max_requests=1000`, `request_terminate_timeout=60s`, `security.limit_extensions=.php`, emergency restart, `access.format` без URI, `disable_functions` (exec, passthru, shell_exec, system, proc_open, popen, pcntl_exec) только для FPM |
| `docker/nginx/nginx.conf` | без root (нет `user`), pid/temp в доступных путях, `server_tokens off`, map для WebSocket-апгрейда |
| `nginx.conf` (vhost) | `listen 8080`; в FPM только `/index.php`; `.php`, dotfiles, `web.config` → приложение; `client_max_body_size 1m`; таймауты; заголовки для статики; `fastcgi_hide_header X-Powered-By`; `/socket.io` с `^~` и только `Upgrade: websocket` |
| `docker/s6-rc.d/*` | сервисы nginx и php-fpm (s6-overlay v3) |
| `docker-compose.yml` | порт `8084:8080`; `APP_ENV=production`, `APP_DEBUG=false`; `no-new-privileges`, `cap_drop: ALL` (redis: +CHOWN/SETGID/SETUID для entrypoint); `pids_limit`; `restart`; `depends_on` echo-server; сеть `backend` (`internal: true`) для redis/echo; том `redis-data`; `REDIS_MAXMEMORY` + `volatile-ttl`; pinned images |
| `helm/templates/*` | порт 8080, `APP_DEBUG=false`, `APP_ENV=production`, `securityContext` (runAsNonRoot 82, no privilege escalation, drop ALL, seccomp RuntimeDefault), исправлены `REDIS_HOST` и auth host echo-server, pinned images |
| `.dockerignore` | исключены `.git`, `.env*`, логи, кэши, `tests/`, `helm/` |
| `config/*.php` | формат Laravel 13; старые имена env (`QUEUE_DRIVER`, `CACHE_DRIVER`, `BROADCAST_DRIVER`, `APP_LOG`, `PUSHER_*`) поддерживаются; Redis-префикс пустой (совместимость данных); `filesystems` с `serve=false` (не регистрируются `GET/PUT storage/{path}`); `debug=false` по умолчанию; `cache.serializable_classes=false`; новые `WEBHOOK_RESPONSE_CSP`, `WEBHOOK_TOKEN_RATE_LIMIT`, `WEBHOOK_UI_CSP` |
| `laravel-echo-server.json.example` | плейсхолдеры, `devMode:false` |

Новые/изменённые переменные окружения:

| Переменная | По умолчанию | Назначение |
|---|---|---|
| `APP_DEBUG` | `false` (было `true`) | не включать на доступных инстансах |
| `WEBHOOK_RESPONSE_CSP` | `sandbox` | CSP для пользовательских ответов и raw-просмотра (`""` — отключить) |
| `WEBHOOK_TOKEN_RATE_LIMIT` | `60` | создание URL в минуту на IP (`0` — без ограничения) |
| `WEBHOOK_UI_CSP` | см. `config/app.php` | замена CSP интерфейса, `{nonce}` подставляется |
| `PHP_SESSION_COOKIE_SECURE` | `0` | `1` при работе по HTTPS |
| `PHP_MEMORY_LIMIT` / `PHP_FPM_MAX_CHILDREN` / `PHP_OPCACHE_VALIDATE_TIMESTAMPS` | `128M` / `20` / `0` | тюнинг |
| `REDIS_MAXMEMORY` | `0` (без лимита) | например `1gb`; вытесняются только данные с TTL |
| `REDIS_CLIENT` | `predis` | `phpredis` требует расширения redis |

HSTS не включён: TLS в этой поставке не терминируется (см. раздел 10).

## 7. Результаты проверок

Окружение проверки: Docker 29.9 в песочнице; сборки выполнялись штатным
`Dockerfile` с двумя чисто инфраструктурными подстановками, не попавшими в
репозиторий: доверие к CA перехватывающего прокси песочницы и offline-кэш Composer
(загрузки dist-архивов с GitHub через прокси запрещены). Итоговый образ собран из
`git archive` коммита `877d81b` с `--no-cache`; на нём повторены Trivy, smoke и
браузерный e2e. Docker Hub отвечал лимитом анонимных загрузок, поэтому образы
загружались через официальное зеркало `mirror.gcr.io` (те же digest).

| # | Проверка | Результат |
|---|---|---|
| 1 | Сборка Docker-образов | ✅ из чистого экспорта `877d81b`, `--no-cache` |
| 2 | Запуск контейнеров | ✅ `docker compose up --wait`: webhook-site **healthy**, redis, echo-server |
| 3 | Версия PHP в CLI и FPM | ✅ `PHP 8.5.12 (cli)`, `PHP 8.5.12 (fpm-fcgi)`; queue worker — тот же `php` |
| 4 | Расширения | ✅ ctype, filter, hash, json, mbstring, openssl, pdo_sqlite, session, tokenizer (`PlatformTest`); в образе дополнительно pcntl и Zend OPcache (`php -m`) |
| 5 | Установка Composer-зависимостей | ✅ `composer validate --strict`, `composer install` с нуля |
| 6 | Известные уязвимости Composer | ✅ `composer audit`: 0 |
| 7 | Существующие тесты | ✅ 4/4 (на исходном PHP 7.4: 1 error из-за faker) |
| 8 | Новые тесты безопасности | ✅ PHPUnit **63 теста / 329 проверок**; node **4/4**. Регрессионные тесты каждого исправления падают без него (проверено временным откатом исправления); исключение — отдельные guard-тесты, проходящие и до исправления (например, формат ошибки 500 при `debug=false`), они отмечены в сообщениях коммитов |
| 9 | Основные API-эндпоинты | ✅ `ApiSmokeTest` + curl-smoke на стеке |
| 10 | Приём webhook | ✅ GET/POST/PUT/PATCH/DELETE/OPTIONS, query, form, JSON, пути с `.php` и цифрами, статус из пути, квота 410, тело >1 МБ → 413 |
| 11 | Отображение запросов | ✅ Playwright/Chromium: URL создаётся, запрос появляется в реальном времени, заголовки/тело/query отображаются, работает после перезагрузки; нарушений CSP нет |
| 12 | Пересылка запросов | ✅ «Copy as cURL» (экранирование проверено в браузере и node-тестом). ⚠️ «Auto redirect/Redirect Now» (XHR из браузера на адрес пользователя) вручную не прогонялся; CSP `connect-src *` его не ограничивает |
| 13 | Фоновые процессы и очереди | ✅ broadcast через `queue:work` → Redis → laravel-echo-server → socket.io (live-обновление UI); сбойное задание записано в SQLite `failed_jobs` от `www-data` |
| 14 | БД и сохранность данных | ✅ миграции на SQLite; данные исходной версии читаются новой (живой стек и `LegacyDataCompatibilityTest`); новые данные читаются старой версией (для отката) |
| 15 | Работа после перезапуска | ✅ `restart` и `up --force-recreate` — данные сохраняются (после `f93ba3c`); процедура обновления раздела 10 прогнана целиком с PHP 7.4-стека |
| — | Hardening в работе | ✅ порт 9000 закрыт для соседних контейнеров; все процессы `www-data`; запись в код запрещена; заголовки безопасности; WebSocket 101 / h2c не апгрейдится |
| — | Статический анализ | ✅ PHPStan level 5: 0; PHPCompatibility 8.5: 0; Semgrep: разобрано |
| — | Trivy | ✅ финальный образ: 0 уязвимостей |
| — | CI workflow | ⚠️ шаги docker-job выполнены локально на штатном compose; сам workflow на GitHub ещё не запускался |
| — | Helm | ⚠️ только синтаксическая проверка YAML; кластера для проверки нет |

## 8. Оставшиеся проблемы и ограничения

Приоритет — по убыванию риска.

1. **laravel-echo-server** (`webhooksite/laravel-echo-server`, Node 20.6 / Debian
   12.1, 2023 г.): Trivy — 9 critical / 126 high (gnutls, perl, zlib, form-data,
   tar, proxy-addr). Проект laravel-echo-server не поддерживается, исходников образа
   в репозитории нет. Смягчено: только внутренняя сеть, без опубликованных портов и
   исходящего доступа, `cap_drop: ALL`. **Рекомендация**: заменить на поддерживаемый
   Pusher-совместимый сервер (Laravel Reverb или soketi) либо пересобрать образ на
   актуальном Node LTS — отдельная задача.
2. **Фронтенд-зависимости** (вне PHP-обновления): `npm audit` — 132 advisories
   (47 critical, 62 high), большинство в сборочном инструментарии (gulp 3,
   laravel-elixir, webpack 1, node:11). В браузер попадают AngularJS 1.7.8 (EOL),
   json-bigint < 1.0 (CVE-2020-8237, DoS при «Format JSON»), moment (ReDoS),
   jQuery ≤ 3.4.1, highlight.js 9 (ReDoS), socket.io-client 2. CSP (V-11) снижает
   последствия XSS, но не устраняет уязвимости. **Рекомендация**: модернизация
   фронтенда и сборки.
3. **Готовый образ `webhooksite/webhook.site` на Docker Hub** (используется helm и
   compose при отсутствии локальной сборки) этих изменений **не содержит**. Нужно
   собрать и опубликовать свой образ с конкретным тегом и указать его в манифестах.
4. **Задержка ответа до 10 с** (настройка URL) удерживает воркер FPM; захват
   webhook намеренно не ограничен по частоте. При 20 воркерах возможна деградация
   под нагрузкой. Рекомендуется rate limiting на edge (например, `limit_req`/WAF) и
   подбор `PHP_FPM_MAX_CHILDREN`.
5. **Redis без пароля** — доступен только из внутренней сети. Для helm/общих сетей
   задать `REDIS_PASSWORD`/ACL и `REDIS_MAXMEMORY`.
6. **Helm**: не проверен на кластере; у redis нет PVC (данные теряются при
   перезапуске пода — как и раньше); нет NetworkPolicy; тег образа плавающий
   (см. п.3).
7. **Reverse proxy**: TrustProxies не настроен (как и раньше). За прокси приложение
   видит IP прокси — rate limit будет общим для всех клиентов. Настроить доверенные
   прокси при таком развёртывании.
8. **`APP_KEY`** не задаётся в compose; нужен только маршруту `broadcasting/auth`
   (приватные каналы не используются). Рекомендуется задавать через секрет.
9. **«Redirect»** выполняется из браузера пользователя на указанный им адрес
   (включая `localhost`/внутреннюю сеть) с содержимым, пришедшим от отправителя
   webhook, — это сценарий продукта; пользователь включает его сам.
10. **Read-only root filesystem** не включён: SQLite (`failed_jobs`) и каталоги
    Laravel в дереве приложения требуют записи; потребовались бы tmpfs/тома и
    миграции при старте. Запись в код приложения при этом запрещена правами.
11. **HSTS** не включён: TLS терминируется вне контейнера. Включать на внешнем
    прокси после проверки HTTPS для всех поддоменов.
12. Метаданные образа содержат `EXPOSE 9000`, унаследованный от базового образа;
    FPM слушает только 127.0.0.1.
13. Функциональные мелочи, не относящиеся к безопасности и не менявшиеся: экспорт
    HAR с инвертированным условием Content-Type; параметры `page`/`per_page` не
    валидируются; `public/js/libs.js` в репозитории не воспроизводится из lock-файла
    (Docker собирает ассеты сам); мёртвый файл `public/assets/scripts/libs/autotrack.js`.
14. PHPStan baseline содержит 13 унаследованных неточностей PHPDoc.
15. Коммит `5af074d` (runtime) отдельно не собирается: Laravel 5.4 не работает на
    PHP 8. Коммиты `5af074d` + `42fc43e` — единое целое для развёртывания.

## 9. Коммиты

| SHA | Сообщение | Назначение |
|---|---|---|
| `5af074d` | build(php): upgrade PHP runtime to latest supported stable release | PHP 8.5.12, базовый образ, nginx, s6, pcntl, pin образов |
| `42fc43e` | chore(deps): update vulnerable PHP dependencies | Laravel 13 и зависимости, адаптация кода, тесты на PHPUnit 12 |
| `4eb7ce9` | security(config): harden PHP and web server configuration | php.ini, PHP-FPM, nginx, non-root, compose, helm |
| `1371964` | fix(security): sandbox user-controlled webhook responses to prevent stored XSS | V-01 |
| `9f39ac8` | fix(security): validate custom response settings of webhook URLs | V-07 |
| `ad84809` | fix(security): bind captured requests to the URL's token, not user input | V-09 |
| `2e30107` | fix(security): stop leaking stack traces and internal errors | V-03, V-12, V-13 |
| `c2231f8` | fix(security): rate-limit webhook URL creation and bound request sizes | V-06 |
| `25c7874` | fix(security): make CORS revocable and enforce broadcast truncation | V-10 |
| `26c3582` | fix(security): shell-quote values in the "Copy as cURL" export | V-05 |
| `0f9a013` | fix(security): add CSP and anti-clickjacking headers to the web UI | V-11 |
| `888f701` | security(config): restrict cache unserialization and proxied upgrades | V-16 |
| `27c29ca` | test(security): add security regression and compatibility tests | тесты совместимости, PHPStan, CI |
| `f93ba3c` | fix(compose): persist Redis data across container re-creation | V-17 |
| `877d81b` | build(docker): apply Alpine security updates during the image build | Trivy → 0 |
| _этот коммит_ | docs(security): document PHP upgrade and security remediation | отчёт |

## 10. Развёртывание (docker compose)

Изменения, ломающие совместимость развёртывания:
- контейнер слушает **8080** вместо 80 (`ports: "8084:8080"`); внешние прокси,
  указывающие на порт 80 контейнера, нужно перенастроить;
- контейнер redis пересоздаётся один раз, данные нужно перенести (шаги 2 и 5);
- формат задач очереди Laravel 13 несовместим с 5.4 — очередь нужно опустошить.

1. Проверить, что очередь пуста (на старой версии):

   ```bash
   docker compose exec redis redis-cli LLEN queues:default
   ```

   ```bash
   docker compose exec redis redis-cli ZCARD queues:default:delayed
   ```

2. Сделать резервную копию Redis:

   ```bash
   docker compose exec redis redis-cli SAVE
   ```

   ```bash
   docker cp webhook-redis:/data/dump.rdb ./redis-backup.rdb
   ```

3. Обновить код, проверить переменные окружения (раздел 6; `APP_DEBUG=false`,
   при HTTPS `PHP_SESSION_COOKIE_SECURE=1`) и собрать образ:

   ```bash
   git pull
   ```

   ```bash
   docker compose build
   ```

4. Запустить новую версию:

   ```bash
   docker compose up -d --remove-orphans --wait
   ```

5. Восстановить данные Redis в новый том:

   ```bash
   docker compose stop redis
   ```

   ```bash
   docker cp ./redis-backup.rdb webhook-redis:/data/dump.rdb
   ```

   ```bash
   docker compose up -d --wait
   ```

6. Проверить:

   ```bash
   docker compose exec webhook php -v
   ```

   ```bash
   docker compose exec redis redis-cli DBSIZE
   ```

   ```bash
   curl -fsS -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8084/
   ```

   Открыть UI, отправить тестовый webhook и убедиться, что он появился без
   перезагрузки страницы.

Эта процедура выполнена целиком в песочнице на стеке исходной версии (PHP 7.4):
настройки старых URL (статус, тело, CORS) и захваченные запросы сохранились и
обслуживаются новой версией.

Helm: собрать и опубликовать образ, указать его тег в
`helm/templates/webhook-deployment.yaml`; сервис направляет трафик на порт 8080.

### Обновление закреплённых версий

- PHP: заменить тег и digest `php:8.5.X-fpm-alpine3.23@sha256:…` в `Dockerfile`
  (digest: `docker pull` + `docker image inspect --format '{{index .RepoDigests 0}}'`),
  затем `config.platform.php` в `composer.json` и `composer update --lock`.
- Пакеты Alpine закреплены на сериях (`nginx~1.28`, `s6-overlay~3.2`) — Alpine хранит
  только последнюю ревизию пакета; патчи безопасности применяются `apk upgrade` при
  каждой сборке.
- Composer, Node, Redis, echo-server: тег + digest в `Dockerfile`,
  `docker-compose.yml`, `helm/templates/*.yaml`, `.github/workflows/ci.yml`.
- Composer-пакеты: `composer update <пакет>` → `composer audit` → тесты.

## 11. План отката

Точка отката — коммит `96b7b42` (или ранее развёрнутый образ).

1. Сохранить данные Redis новой версии:

   ```bash
   docker compose exec redis redis-cli SAVE
   ```

   ```bash
   docker cp webhook-redis:/data/dump.rdb ./redis-rollback.rdb
   ```

2. Дождаться пустой очереди (`LLEN queues:default`, `ZCARD queues:default:delayed`
   = 0): задачи Laravel 13 не выполняются воркером Laravel 5.4.
3. Вернуть код и запустить прежнюю версию:

   ```bash
   git checkout 96b7b42
   ```

   ```bash
   docker compose up -d --build --remove-orphans
   ```

4. Восстановить данные в контейнер redis старой версии (там нет тома, данные живут
   в слое контейнера):

   ```bash
   docker compose stop redis
   ```

   ```bash
   docker cp ./redis-rollback.rdb webhook-redis:/data/dump.rdb
   ```

   ```bash
   docker compose start redis
   ```

5. Вернуть порт 80 во внешних прокси, если он менялся.

Совместимость данных при откате проверена: исходный образ (PHP 7.4) читает токены и
запросы, записанные новой версией, и применяет их настройки (статус, CORS). Сама
последовательность отката целиком не прогонялась. Именованный том `redis-data` при
откате не удаляется — его можно использовать для повторного обновления.

Частичный откат: можно откатить отдельные `fix(security)` коммиты через
`git revert <sha>`; коммиты `5af074d` и `42fc43e` откатываются только вместе.
