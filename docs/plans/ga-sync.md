# GA4: збір даних лише кроном або кнопкою

## Проблема
Сторінка GA, картка Overview і 4WP Planner (`forwp_planner_metric_value`) викликали Analytics API під час завантаження сторінки. Planner — окремий запит на кожен план. Після помилки lock не ставився, тому кожне завантаження знову чекало до 20 с (`cURL error 28`), і так до вичерпання квоти.

## Рішення
1. Читачі (сторінка GA, Overview, Planner) — **лише з БД** (`wp_forwp_ga_daily`, `wp_forwp_ga_period`).
2. З API працює тільки `Ga\Sync`: cron `forwp_seo_ga_sync` (twicedaily) або кнопка «Sync now».
3. День закритий через `SETTLE_DAYS` (2) після завершення. Закритий день, синхронізований після закриття, — фінальний, більше не запитується.
4. `activeUsers` за період: читач ставить період у чергу (`forwp_seo_ga_wanted_periods`), наступний запуск збирає. До того значення немає (`—`, Planner лишається без значення) — гап допустимий, вигаданих чисел немає.
5. Після помилки API — пауза 30 хв для cron. «Sync now» — без перезавантаження: AJAX `forwp_seo_ga_sync` (nonce) ставить фонову задачу (`wp_schedule_single_event` + `spawn_cron`), JS кожні 5 с опитує `forwp_seo_ga_sync_status` і оновлює статус і підсумок 30 днів.
6. Таймаути (лише у фоні): дні — 30 с, періоди — 60 с. `activeUsers` за 30 днів GA4 рахує 20+ с, за 7 днів — ~0.5 с. Повільний період не зупиняє решту, лишається в черзі. Ліміт часу на запуск — 120 с.

## Файли
| Файл | Що |
|---|---|
| `includes/Ga/Sync.php` (нов.) | Збір: дні (backfill 90 днів або від найранішого періоду в черзі, макс. 900), періоди (до 25 за запуск), пауза, стан `forwp_seo_ga_sync_state` |
| `includes/Ga/Module.php` | `totals()` / `status()` лише з БД; прибрано `sync_daily()`; на сторінці — «Sync now», останній успішний sync, наступний cron, остання помилка |
| `includes/Ga/Repository.php` | `final_dates()` (synced_at > дата + SETTLE_DAYS) |
| `includes/Ga/Client.php` | `timeout` runReport: дні 30 с, періоди 60 с |
| `4wp-seo-helper.php` | Зняття cron при деактивації |

## Перевірки
| Перевірка | Результат |
|---|---|
| `php -l` змінених файлів | OK |
| wp-cli: клас вантажиться, `is_ready`, cron заплановано | OK (локально) |
| wp-cli: `Sync::run()` | OK, 26 с; 30 днів: 1299 users · 1381 sessions · 1620 pageviews; у черзі 3 відкриті періоди |
| Сторінка `fwpp-planner` без запитів до Google | (уточнити) — автор |
| «Sync now» на `forwp-seo-ga`, дані в таблицях | (уточнити) — автор |
| Прод: cron, відсутність `cURL error 28` на сторінках | (уточнити) — автор |
