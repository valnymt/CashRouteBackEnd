# CashRoute – Backend API

**Team members: start with [docs/TEAM_GUIDE.md](docs/TEAM_GUIDE.md)** — database design, MySQL setup,
migrations, and which git branch each person works on.

Laravel 12 API for CashRoute. The HQ admin portal (React) is a separate project next to this one:

```
C:\xampp\htdocs\CashRoute\
  CashRoute_Admin_Portal\   ← frontend (React + Vite)
  Cashroute_backend\        ← this folder (Laravel API)
```

The admin portal and Android app use this API. Laravel connects to MariaDB and handles authentication, assignments, trips, OTP, and the notification inbox.

## Setup (first time, e.g. after cloning)

1. Start MySQL in XAMPP.
2. Run `composer install`, then copy `.env.example` to `.env` and run `php artisan key:generate`. Keep your existing `.env` and app key when updating an installed checkout.
3. Set the `DB_*` values in `.env` for your local MariaDB instance. The example uses `DB_CONNECTION=mariadb`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`, and `DB_DATABASE=cashroute`.
4. For a **new database**, import `database/sql/01_schema.sql` in phpMyAdmin. Import `database/sql/02_seed.sql` afterward for system roles, permissions, and settings. Provision real branches (including HQ), accounts, and teams before creating requests.
5. For an **existing database**, keep your data and verify the connection with `php artisan db:show --database=mariadb`. SQL-imported databases may have no Laravel migration ledger. The SQL schema supplies the application tables, including `notifications` and `device_tokens`.
6. For Android push, set `FIREBASE_CREDENTIALS` to the service-account JSON for the Firebase project used by the Android app. Keep this file and `.env` local.


## Run it

- **XAMPP:** start Apache and open http://localhost/CashRoute/Cashroute_backend/public/api/health
- **or Laravel's own server:** run `php artisan serve` and open http://127.0.0.1:8000/api/health

Either way you should see `{"status":"ok", ...}`.

The `.htaccess` in this folder blocks browser access to everything except `public/`, so `.env`,
`vendor/` and the source code can't be downloaded even though the folder sits inside `htdocs`.

## How the frontend connects

| Setting | Where | Value |
| --- | --- | --- |
| API address | frontend `.env` → `VITE_API_BASE_URL` | `http://localhost/CashRoute/Cashroute_backend/public/api` |
| Use the real API | frontend `.env` → `VITE_USE_MOCK_API` | `false` (currently `true` = demo data) |
| Allowed frontend addresses (CORS) | this `.env` → `FRONTEND_URLS` | `http://localhost:5173,http://localhost` |
| Cookie login (Sanctum) | this `.env` → `SANCTUM_STATEFUL_DOMAINS` | `localhost:5173,localhost` |

Already configured:

- `config/cors.php` reads `FRONTEND_URLS` and allows credentials (cookies).
- `bootstrap/app.php` turns on Sanctum's stateful API with `$middleware->statefulApi()`.
- The `User` model has `HasApiTokens`.

The frontend first calls `GET /sanctum/csrf-cookie`, then `POST /api/login`, and after that sends
the session cookie with every request.

## Admin API routes

```
POST /api/login            GET  /api/me              POST /api/logout
GET|POST /api/branches     PUT  /api/branches/{id}   GET  /api/branch-directory
GET|POST /api/users        PUT  /api/users/{id}
GET|POST /api/teams        PUT  /api/teams/{id}
GET|POST /api/requests     GET|PUT /api/requests/{id}
POST /api/requests/{id}/approve | reject | assign | cancel
GET  /api/audit-logs       GET  /api/trips
GET  /api/notifications    POST /api/notifications/read-all
```

The JSON shapes are in the frontend's `src/types/index.ts` and `src/services/types.ts`. The business
rules to enforce are in its `src/services/mockApi.ts`:

- Maker-Checker: you can't approve your own request.
- A reason is required to reject.
- Only available teams can be assigned.
- The Maker can edit or cancel only while the request is Pending.
- A Checker can cancel an approved request.
- Every action writes an audit log entry.

Validation errors should use Laravel's default 422 format (`{ message, errors: { field: [...] } }`);
the frontend shows them next to the right form field.

Already available: `GET /api/health` (status check) and `GET /api/me` (signed-in user, needs auth).
