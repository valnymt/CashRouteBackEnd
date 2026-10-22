# Local setup — portal + backend + MySQL, without delays

Do this once on your laptop. Afterwards the Admin Portal talks to your own Laravel backend and your own
`cashroute` database (no demo data), and clicks respond quickly.

---

## 1. PHP speed settings (`php.ini`) — one time

XAMPP ships with PHP's **OPcache** switched off, so every request recompiles all of Laravel (≈ 1–5 s per
click). Turn it on:

1. Back it up first: copy `C:\xampp\php\php.ini` to `C:\xampp\php\php.ini.backup`.
2. Open `C:\xampp\php\php.ini` in VS Code. Use **Ctrl + F** to find each line below, remove the `;` at the
   start, and set the value:

   | Find | Change to |
   | --- | --- |
   | `;zend_extension=opcache` | `zend_extension=opcache` |
   | `;opcache.enable=1` | `opcache.enable=1` |
   | `;opcache.memory_consumption=128` | `opcache.memory_consumption=128` |
   | `;opcache.interned_strings_buffer=8` | `opcache.interned_strings_buffer=16` |
   | `;opcache.max_accelerated_files=10000` | `opcache.max_accelerated_files=20000` |
   | `;opcache.validate_timestamps=1` | `opcache.validate_timestamps=1` |
   | `;opcache.revalidate_freq=2` | `opcache.revalidate_freq=0` |
   | `;realpath_cache_size = 4096k` | `realpath_cache_size = 4096k` |
   | `;realpath_cache_ttl = 120` | `realpath_cache_ttl = 600` |

   `validate_timestamps=1` + `revalidate_freq=0` = code you edit still applies immediately.
3. Make sure `extension=pdo_mysql` has **no** `;` in front of it.
4. Save, then **restart Apache**: XAMPP Control Panel → Apache → **Stop** → **Start**.
   (If Stop doesn't work, reopen the Control Panel with right-click → *Run as administrator*.)
5. Check in a terminal: `php -v` must show a line `with Zend OPcache`.

---

## 2. Backend (`Cashroute_backend`)

```bash
cd C:\xampp\htdocs\CashRoute\Cashroute_backend
git checkout develop
git pull
composer install
copy .env.example .env
php artisan key:generate
php artisan config:clear
```

`.env.example` already has the right values (`DB_CONNECTION=mariadb`, `DB_DATABASE=cashroute`, `root` with
no password, the portal URLs for CORS / Sanctum). Skip `copy` if you already have a `.env` — just make sure
these lines match:

```
APP_URL=http://localhost/CashRoute/Cashroute_backend/public
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cashroute
DB_USERNAME=root
DB_PASSWORD=
SESSION_DOMAIN=localhost
FRONTEND_URLS=http://localhost:5173,http://localhost
SANCTUM_STATEFUL_DOMAINS=localhost:5173,localhost
```

Optional, only on your laptop: `BCRYPT_ROUNDS=10` makes login / Add User faster (password hashing is slow on
purpose). Leave 12 on the real server.

## 3. Database

XAMPP Control Panel → **MySQL** must be running. Then:

```bash
C:\xampp\mysql\bin\mysql.exe -u root -e "DROP DATABASE IF EXISTS cashroute;"
Get-Content database\sql\01_schema.sql | C:\xampp\mysql\bin\mysql.exe -u root
Get-Content database\sql\02_seed.sql | C:\xampp\mysql\bin\mysql.exe -u root
php artisan db:show
```

`db:show` should list the `cashroute` tables. Never run `php artisan migrate` — tables come from the SQL files.
Run the same three mysql lines any time you want clean demo data back.

---

## 4. Portal (`CashRoute_Admin_Portal`)

In `CashRoute_Admin_Portal\.env` set:

```
VITE_USE_MOCK_API=false
VITE_API_BASE_URL=http://localhost/CashRoute/Cashroute_backend/public/api
VITE_REQUIRE_LOGIN=true
```

- `VITE_USE_MOCK_API=false` = use the real backend. `true` = the built-in demo data (nothing reaches MySQL).
- `VITE_REQUIRE_LOGIN=true` = show the login page (with `false` it auto-logs in as `admin.maker`).

Then:

```bash
cd C:\xampp\htdocs\CashRoute\CashRoute_Admin_Portal
npm install
npm run dev
```

Open **http://localhost:5173** (the dev server). The XAMPP address
`http://localhost/CashRoute/CashRoute_Admin_Portal/` serves the pre-built `dist/` folder, which stays on demo
data — don't rebuild `dist/` with the mock switched off.

Changed `.env` while `npm run dev` is running? Stop it (Ctrl + C) and start it again.

---

## 5. Log in

| Username | Role |
| --- | --- |
| `admin.maker` | Super Admin |
| `amy.tan`, `priya.nair` | Maker |
| `daniel.lim` | Checker |
| `raj.kumar` (etc.) | Driver — refused by the portal (mobile app only) |

Password for all: `password`.

Pages whose APIs aren't built yet show errors (404) — that's expected until each module is merged into
`develop`.

## 6. API docs (Swagger)

**http://127.0.0.1/CashRoute/Cashroute_backend/public/docs/api** — use `127.0.0.1`, not `localhost`
(otherwise requests fail with 419). Get a token from **Token login**, paste it in **Auth → Token**, then try
any endpoint with **Send API Request**.

---

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| Every click takes seconds | OPcache off → section 1, and really restart Apache |
| Added data isn't in phpMyAdmin | Portal still on demo data → `VITE_USE_MOCK_API=false`, restart `npm run dev` |
| `Table 'performance_schema.session_status' doesn't exist` | `DB_CONNECTION=mariadb`, not `mysql` |
| `could not find driver` | `extension=pdo_mysql` in `php.ini`, restart Apache |
| Login page spins, then nothing | Backend `.env` CORS / Sanctum lines from section 2; `php artisan config:clear` |
| 419 in Swagger | Open Swagger on `127.0.0.1` |
| Roles / names show empty | SQL files were run through a formatter → re-import the original files |
