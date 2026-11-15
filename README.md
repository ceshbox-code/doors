# Система учёта дверей, выполненных работ и оплаты труда

Реализация по ТЗ v2.0. Backend этапов 1–7 и интерфейс PWA (этап 8) находятся в этой ветке `feature/pwa-interface`. Перед переносом в `main` используется Draft PR.

## Стек
PHP 8.0 + Apache (контейнер `doors-app`) · MariaDB 10.11 (InnoDB) · Docker Compose · PHPUnit 9.6.
HTTPS и внешний адрес обеспечивает обратный прокси DSM, отдельного nginx в проекте нет.

## Web-интерфейс и PWA (ветка feature/pwa-interface)

- Клиент — нативные HTML/CSS/JavaScript-модули без React/Vue и без отдельного frontend-контейнера. Статика обслуживается Apache из `public/`.
- `public/manifest.json` и `public/sw.js` обеспечивают установку приложения и кэширование статической оболочки.
- Для Service Worker и установки PWA нужен HTTPS (либо localhost).
- Офлайн-записи и фотографии хранятся локально в IndexedDB браузера. Очередь отправляется при восстановлении сети, открытии/возврате приложения и вручную из раздела «Офлайн-очередь».
- API-ответы, сессионные данные и приватные фотографии Service Worker не кэширует. Локальные записи удаляются только после подтверждения сервера.
- До успешной синхронизации не очищайте данные сайта/браузера и не удаляйте приложение: очередь привязана к этому браузеру и устройству.
- Время выполнения передаётся с устройства в ISO 8601 с часовым поясом и сохраняется в UTC как `completed_at`; `received_at` отдельно фиксирует время приёма запроса сервером; `completed_at_source` указывает источник времени. Миграция: `018_work_record_received_at.sql`.
- Навигация интерфейса: обзор, регистрация работ, офлайн-очередь, история с фильтрами, поиск двери, статистика, финансы, расчёты/выплаты и настройки администратора.

Проверки ветки выполняются GitHub Actions: Composer validation, PHP lint, Node.js syntax check и PHPUnit.

## Развёртывание на Synology

Для существующей установки **не распаковывайте архив поверх проекта и не удаляйте `data/`**. Каталог `data/` содержит БД и пользовательские файлы.

### Первичная установка
```bash
ssh <логин>@<IP-NAS>
sudo -i
mkdir -p /volume1/docker/doors
cd /volume1/docker/doors
git clone -b feature/pwa-interface https://github.com/ceshbox-code/doors.git .
cp .env.example .env
DBP=$(openssl rand -hex 32)
ROOTP=$(openssl rand -hex 32)
sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DBP}|; s|^DB_ROOT_PASSWORD=.*|DB_ROOT_PASSWORD=${ROOTP}|" .env
chmod 600 .env
mkdir -p data/db data/storage/photos data/storage/exports data/storage/logs data/storage/backups data/storage/sessions
chown -R 33:33 data/storage
docker-compose up -d --build
docker-compose exec doors-app php bin/migrate.php
docker-compose exec doors-app php bin/seed.php
docker-compose exec doors-app php bin/create-owner.php admin "Фамилия Имя Отчество"
curl -fsS http://localhost:8066/api/v1/health
```

### Обновление существующей установки
1. Сделайте резервную копию БД, `.env` и `data/storage`.
2. Получите ветку `feature/pwa-interface` в существующем Git checkout. Не заменяйте `.env`, `data/db` и `data/storage`.
3. Пересоберите только приложение: `docker-compose up -d --build doors-app`.
4. Проверьте health endpoint: `curl -fsS http://localhost:<HTTP_PORT>/api/v1/health`.
5. Только после успешной резервной копии и проверки health выполните миграции: `docker-compose exec doors-app php bin/migrate.php`.

Порт по умолчанию — `8066`, задаётся переменной `HTTP_PORT` в `.env`. HTTPS обеспечивает reverse proxy DSM, направленный на `http://localhost:<HTTP_PORT>`.

## API (этапы 2–7)
Все ответы: `{"success":true,"data":...}` или `{"success":false,"error":{"code","message"[,"details"]}}`.
Не-GET запросы после входа требуют заголовок `X-CSRF-Token` (значение из ответа login/me).

| Метод | Endpoint | Доступ |
|---|---|---|
| POST | `/auth/login` · `/auth/logout` · GET `/auth/me` | все / вошедший |
| GET, POST | `/users` · GET, PATCH `/users/{id}` · POST `/users/{id}/deactivate`, `/activate` | owner |
| GET | `/work-types`, `/work-types/{id}` | owner, director (все виды), master (только активные) |
| POST, PATCH | `/work-types`, `/work-types/{id}` · POST `/work-types/{id}/deactivate`, `/activate` | owner |
| POST, GET | `/rates` · GET `/rates/{id}`, `/rates/history`, `/users/{id}/rates` · POST `/rates/{id}/close` | owner |
| POST | `/work-records` (+ заголовок `Idempotency-Key`) | owner, master |
| GET | `/work-records`, `/work-records/{id}` | owner, director — все; master — только свои |
| POST | `/work-records/{id}/void` `{"reason":"..."}` | owner |
| GET | `/work-records/{id}/photos` | owner, director; master — только свои |
| POST | `/photos` (multipart: `work_record_id`, `photos[]`) — добавить фото к своей работе | owner, master |
| GET | `/photos/{id}[?variant=original]` — выдача файла | owner, director; master — только свои |
| GET | `/finance/summary` · `/finance/balances` | owner, director |
| GET | `/finance/accruals` · `/finance/users/{id}` · `/finance/adjustments` | owner, director — все; master — только свои |
| POST | `/finance/adjustments` — ручная корректировка / начальный остаток | owner |
| POST, GET | `/payroll-runs` · GET `/payroll-runs/{id}` · POST `/payroll-runs/{id}/confirm`, `/cancel` | изменения — owner; просмотр — owner, director, master (свои подтверждённые) |
| POST (+`Idempotency-Key`), GET | `/payments` · GET `/payments/{id}`, `/payments/{id}/allocations` | изменения — owner; просмотр — owner, director, master (свои подтверждённые) |
| PUT | `/payments/{id}/allocations` — изменить распределение черновика | owner |
| POST | `/payments/{id}/post` · `/payments/{id}/cancel` `{"reason":"..."}` | owner |
| GET | `/stats/summary` · `/stats/daily` · `/stats/work-types` · `/stats/users` · `/stats/doors` | owner, director |
| GET | `/export/pdf/production` · `/pdf/payments` · `/pdf/finance` · `/pdf/photos` | owner, director |
| GET | `/export/pdf/payroll/{id}` — расчётный лист | owner, director — подтверждённые; master — свой подтверждённый; черновик — только owner |
| GET | `/export/csv` · `/export/xlsx` (`dataset=...`) | owner, director |
| DELETE | `/photos/{id}` `{"reason":"..."}` — только фото аннулированной работы, мягкое | owner (право `photos.delete`) |
| GET | `/doors` · `/doors/{id}` · `/doors/{id}/history` | owner, director |
| GET | `/doors/number/{номер}` | owner, director (полная карточка), master (краткая) |
| POST | `/doors` (ручное создание) | owner |

### Регистрация работы (multipart/form-data, фото обязательно)
```
POST /api/v1/work-records            Idempotency-Key: <уникальная строка 8–100 символов>
Content-Type: multipart/form-data
  work_type_id=1  new_door=true  quantity=1  comment=...  photos[]=<файл>  photos[]=<файл>…   // новая дверь: номер присваивается автоматически
  work_type_id=2  door_number=D-000012  photos[]=<файл>                                         // существующая дверь
```
Стоимость вводить нельзя: расценка исполнителя определяется на момент выполнения, сумма = количество × расценка (decimal, без float).
Ошибки: `PHOTO_REQUIRED`, `PHOTO_INVALID`, `PHOTO_TOO_LARGE`, `DUPLICATE_PHOTO`, `RATE_NOT_FOUND`, `DUPLICATE_WORK`, `DOOR_REQUIRED`, `DOOR_NOT_FOUND`, `WORK_TYPE_INACTIVE`, `IDEMPOTENCY_KEY_REUSED`.
Повтор запроса с тем же ключом и теми же файлами возвращает прежний результат (заголовок `Idempotent-Replay: true`) без повторной обработки.

### Фотографии
- Форматы JPEG, PNG, WebP; до 15 МБ и до 40 Мп; до 10 файлов за запрос, до 20 на работу. Тип определяется **по содержимому**, а не по расширению.
- Хранится оригинал (как есть) и оптимизированная копия (JPEG, до 1600 px по длинной стороне, поворот по EXIF, метаданные/GPS удалены). Имена — случайные серверные, SHA-256 сохраняется.
- Файлы лежат в `storage/photos/ГГГГ/ММ/` вне публичного каталога и отдаются только через `GET /photos/{id}` после проверки прав.
- Регистрация атомарна: при любой ошибке не остаётся ни записи, ни двери, ни файлов на диске.
- Аннулирование работы фотографии не удаляет. Удалить фото можно только у аннулированной работы, по праву `photos.delete`, с причиной; файлы при этом остаются на диске.

### Расценки
Новая расценка закрывает предыдущую открытую (`valid_to`) и начинается не раньше неё; прошедшей датой назначить нельзя; старые записи и начисления не меняются.

### Финансы (этап 6)
**Формулы.** Начислено = сумма действующих (не аннулированных) работ. Выплачено = распределения *подтверждённых* выплат (черновики и отменённые не учитываются). **Остаток = начислено − выплачено + ручные корректировки.** Отрицательный остаток выводится отдельно как `overpayment`, положительный — как `debt`. Показатели за период: начислено — по дате выполнения, выплачено — по дате выплаты, `debt_at_end` — остаток на конец периода.

**Выплата** проходит `draft → posted → cancelled`. Черновик остаток не меняет. При создании без `allocations` сумма автоматически распределяется на самые ранние неоплаченные начисления; можно передать своё распределение или изменить его у черновика (`PUT /payments/{id}/allocations`). `"post": true` создаёт и сразу подтверждает выплату. Поддерживаются частичные выплаты и выплата, покрывающая несколько начислений.

**Защита от двойного зачёта.** Подтверждение выплаты выполняется в транзакции: блокируются строки начислений, уже распределённая сумма перечитывается блокирующим запросом. Поэтому две одновременные выплаты на один остаток не пройдут обе (вторая получит `ALLOCATION_EXCEEDS_OUTSTANDING`), а сумма распределения всегда равна сумме выплаты. Порядок блокировок во всех операциях: расчёт → выплата → работы.

**Расчёт за период.** Черновик собирается из действующих работ исполнителя за период (даты — по часовому поясу предприятия) и сразу «занимает» их: одна работа — не более чем в одном действующем расчёте. Подтверждённый расчёт — неизменяемый снимок (названия, цены, суммы); смена расценок и новые работы его не меняют. Отмена возможна только пока по расчёту нет выплат; при отмене работы освобождаются. Выплата по расчёту (`payroll_run_id`) распределяется только на работы этого расчёта.

**Корректировки.** Исходные записи выплат не переписываются и не удаляются.
- Отмена подтверждённой выплаты → выплата получает статус `cancelled` (перестаёт учитываться в «выплачено»), создаётся запись `cancel_payment` с причиной.
- Аннулирование оплаченной работы → оплата сохраняется, образуется переплата; создаётся `void_paid_work`. Если работа была в подтверждённом расчёте — ещё и `payroll_correction`. Работа из *черновика* расчёта не аннулируется (сначала отмените черновик).
- Ручные корректировки (`manual`, `overpayment`, `payroll_correction`, знак «+» увеличивает долг перед исполнителем, «−» уменьшает) вводит администратор; они входят в остаток (`affects_balance = true`). Системные записи (`affects_balance = false`) фиксируют уже учтённое статусами последствие и в остаток не суммируются.
- **Начальные остатки** при внедрении вводятся ручной корректировкой `manual`. Их погашение фиксируется встречной ручной корректировкой (распределить выплату на начальный остаток нельзя: распределение возможно только на начисления за работы).

Физическое удаление финансовых записей не производится; у черновика выплаты распределение можно заменить до подтверждения.

### Статистика и отчёты (этап 7)
**Фильтры** (одни и те же у статистики, PDF, CSV и XLSX): `period` = `today | yesterday | this_week | prev_week | this_month | prev_month` **или** `date_from`/`date_to` (`YYYY-MM-DD`), `user_id`, `work_type_id`, `door_number`. Границы дней — по часовому поясу предприятия (`APP_TIMEZONE`), неделя начинается с понедельника. Считаются только действующие (не аннулированные) работы.

**Статистика (JSON).** `summary` — число уникальных дверей, ремонтов, упаковок, дополнительных работ и операций + начислено, выплачено, задолженность, переплата; `daily` — ряд по дням (для графиков); `work-types`, `users` (по видам работ, дверям, начислениям, выплатам и остатку), `doors` (список дверей с историей, постранично). Для детализации используйте `GET /work-records` с теми же фильтрами. Выплаты и остаток не делятся по видам работ и дверям: при таких фильтрах они возвращаются как `null`, показывается только начислено.
**Категория вида работ** (`category`: `repair` — ремонт, `packing` — упаковка, `other` — дополнительные работы) задаётся в справочнике (`POST/PATCH /work-types`) и определяет, в какой показатель попадает работа. Существующие «Ремонт…» и «Упаковк…» получили категорию автоматически миграцией 017.

**PDF** (mPDF, шрифт DejaVu Sans с кириллицей, A4, нумерация страниц и дата формирования в колонтитуле, шапка таблиц повторяется на каждой странице, строки не разрываются): производственный отчёт, расчётный лист, отчёт по выплатам (`status=posted|draft|cancelled|all`), общий финансовый отчёт, фотоотчёт (не более 100 работ за раз, до 3 снимков на работу, снимки уменьшаются). Расчётный лист: работы расчёта (аннулированные помечены), корректировки, итог начислений, выплачено, остаток или переплата, общий остаток исполнителя.
**CSV / XLSX** (`dataset`): `works` (+ `status=active|voided|all`), `payments` (+ `status`), `balances`, `stats-users`, `stats-types`, `stats-daily`, `stats-doors`. CSV — UTF-8 с BOM, разделитель `;`, десятичная запятая (`decimal=dot` — точка), защита от формульных инъекций. XLSX — настоящие числа и даты, формат денег, закреплённая строка заголовков, автофильтр, строка итогов, лист «Параметры» с условиями выборки; значения, начинающиеся с «=», остаются текстом.
Файлы формируются на лету и не хранятся; каждый экспорт пишется в журнал аудита. Все форматы строятся из одних и тех же выборок, поэтому суммы совпадают с модулем финансов. Валюта в отчётах — `APP_CURRENCY` (по умолчанию ₽), название организации — `APP_ORG_NAME`.

## Сквозная проверка на сервере
```bash
docker-compose exec -e SMOKE_OWNER=admin -e SMOKE_OWNER_PASSWORD='...' doors-app php bin/smoke.php
docker-compose exec -e SMOKE_CLEANUP=1 doors-app php bin/smoke.php     # удалить тестовые данные smoke_*
```

## Тесты
```bash
docker-compose exec -T -u root doors-app composer install        # dev-зависимости (в образе их нет); выполняется один раз
docker-compose exec -T -u www-data doors-app vendor/bin/phpunit   # юнит-тесты
```
Тесты запускайте **от `www-data`**: от root они создают в `storage/` каталоги, недоступные веб-серверу (в том числе кэш PDF).
Сквозная проверка на работающем сервере — `bin/smoke.php` (см. ниже).

## Миграции
`database/migrations/NNN_*.sql` применяются по порядку; журнал — таблица `schema_migrations`.
Менять уже применённые файлы нельзя — только добавлять новые.

## Зависимости
PDF — mPDF, XLSX — PhpSpreadsheet; ставятся Composer при сборке образа (нужен доступ в интернет). Для воспроизводимых сборок после первой успешной можно сохранить `composer.lock`: `docker-compose exec -T doors-app cat composer.lock > composer.lock` и положить в корень проекта.

## Этапы (ТЗ, раздел 20)
1. ✅ Каркас  2. ✅ Авторизация и роли  3. ✅ Двери и работы  4. ✅ Фотоархив  5. ✅ Расценки (в объёме этапов 3 и 6)  6. ✅ Финансы  7. ✅ Статистика и отчёты  8. Тестирование и запуск
