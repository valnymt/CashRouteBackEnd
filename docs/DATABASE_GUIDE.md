# CashRoute — Database Guide

What every part of [`database/sql/01_schema.sql`](../database/sql/01_schema.sql) is for, which
tables are essential vs optional, and how to test the database and the APIs step by step.

---

## Part 1 — The big picture

### Three layers, and where the database sits

```
 Admin Portal (React)  ─┐
 Delivery App (mobile) ─┼──►  Laravel backend (APIs)  ──►  MySQL database
 Swagger / Postman     ─┘        routes → controllers         tables, rows
                                 → models
```

- The **database** only *stores* data (tables and rows). It has no screens and no URLs.
- The **backend** (Laravel) exposes **APIs** — URLs such as `GET /api/branches` — that read and write
  the database and enforce the business rules (maker–checker, team must be confirmed, etc.).
- The **portal**, the **mobile app**, and testing tools like **Swagger** and **Postman** all talk to the
  backend APIs. None of them talk to the database directly.

So: you test the database with **phpMyAdmin / SQL**, and you test the backend with **Swagger / Postman**
— after the APIs are written.

### What is in `01_schema.sql`

| Group | Tables | Count | Who needs it |
| --- | --- | --- | --- |
| **A. CashRoute tables** (our design) | roles, permissions, role_permission, branches, branch_directory, users, teams, team_members, cash_requests, trips, trip_locations, otps, otp_attempts, handover_proofs, handover_photos, audit_logs, notifications, device_tokens, settings | 19 | Us |
| **B. Laravel framework tables** ("section 0") | password_reset_tokens, sessions, personal_access_tokens, cache, cache_locks, jobs, job_batches, failed_jobs | 8 | Laravel itself |
| **C. Database rules & shortcuts** | 2 triggers (audit is read-only), 2 views (trip history, live trips) | 4 | Us |

### Must-have vs optional (use this with the mentor)

| Priority | Tables | Why |
| --- | --- | --- |
| **Core — the system can't work without these** | users, roles, permissions, role_permission, branches, teams, team_members, cash_requests, trips, otps, handover_proofs, audit_logs, notifications | Every one maps directly to a box in the original ERD and to a "Must have" feature (AP-01…AP-09, DA-01…DA-07). |
| **Needed by a specific requirement** | trip_locations (AP-08 live map / DA-05 GPS), otp_attempts (DA-06 "each attempt is logged"), handover_photos (DA-07 "at least one photo"), branch_directory (Add Branch picks from the bank's list) | Small helper tables. Each one exists because a requirement asks for "many" of something (many GPS points, many attempts, many photos). |
| **Kept, but secondary** | settings, device_tokens, the 2 views | settings → values agreed in the design workshop, editable without redeploying. device_tokens → required for push notifications (DA-02 is a Must have). Views → saved queries the reports / live-monitoring APIs can reuse. |
| **Laravel's, but required** | The 8 Laravel tables | We build the database from SQL files instead of `php artisan migrate`, so the SQL file must create Laravel's own tables too (logins, tokens, cache, queue). |

Removed on purpose: `sos_alerts` (SOS button is a stretch goal — add it later with a `03_…` change file if needed).

---

## Part 2 — SQL words you'll see everywhere

| Word | Meaning |
| --- | --- |
| `CREATE DATABASE … utf8mb4` | Creates the `cashroute` database. `utf8mb4` = full Unicode (Myanmar script, emoji). |
| `ENGINE=InnoDB` | MySQL's storage engine that supports foreign keys and transactions. |
| `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY` | `id` column: a whole number that goes 1, 2, 3… automatically. Laravel's standard ID. |
| **PK** (primary key) | The column that uniquely identifies a row (`id`). |
| **FK** (foreign key) `REFERENCES users(id)` | "This column must contain an `id` that exists in that other table." Stops orphan data. |
| `ON DELETE CASCADE` | If the parent row is deleted, delete these child rows too (e.g. delete a team → its member links go). |
| `ON DELETE SET NULL` | If the parent is deleted, set this column to empty instead (e.g. delete a branch → users keep existing). |
| `UNIQUE` | No two rows can have the same value (usernames, branch codes, `REQ-…` references). |
| `NOT NULL` / `NULL` | Required / optional column. |
| `DEFAULT x` | Value used when none is given. |
| `VARCHAR(n)` / `TEXT` | Short text (max n characters) / long text. |
| `DECIMAL(15,2)` | Exact money: up to 13 digits + 2 decimals. Never use FLOAT for money. |
| `DECIMAL(10,7)` | GPS coordinate, e.g. `16.7745000` (7 decimals ≈ 1 cm). |
| `BOOLEAN` | True/false (stored as 1/0). |
| `ENUM('a','b')` | Only these exact values are allowed (e.g. request status). |
| `JSON` | Free-form structured data, e.g. `{"10000": 15, "5000": 20}` for denominations. |
| `TIMESTAMP` / `DATE` / `DATETIME` | Date + time / date only / date + time without timezone conversion. |
| `created_at`, `updated_at` | Laravel fills these automatically on insert / update. |
| `KEY idx_… (col)` | An **index**: makes searching/filtering by that column fast. |
| `CHECK (…)` | A rule each row must pass, e.g. `amount > 0`. |
| `TRIGGER` | Code the database runs automatically before/after an insert/update/delete. |
| `VIEW` | A saved `SELECT` query you can use like a read-only table. |

---

## Part 3 — Every CashRoute table, explained

Format for each: **what it is → feature it supports → columns.** Columns marked *(ERD)* come straight
from the original diagram; the rest were added and the reason is given.

### 3.1 Roles & permissions — AP-03

#### `roles` — the job types
Five default rows: **Super Admin, Maker, Checker** (portal) and **Driver, Custodian** (mobile app).

| Column | Meaning |
| --- | --- |
| id *(ERD role_id)* | Role number |
| name *(ERD role_name)* | `Super Admin`, `Maker`… Unique. |
| channel | `portal` or `mobile` — which app this role may sign in to. Drivers/Custodians get `mobile`, so the portal login rejects them. |
| is_system | `TRUE` for the 5 defaults so they can't be deleted. The spec says Super Admin can add **custom roles** — those would be `FALSE`. |

#### `permissions` — single actions
17 rows such as `requests.create`, `requests.approve`, `branches.manage`, `audit.view`.

| Column | Meaning |
| --- | --- |
| id *(ERD permission_id)* | |
| code *(ERD permission_code)* | Short machine name used in backend checks, e.g. `requests.approve` |
| name *(ERD permission_name)* | Human label, e.g. "Approve or reject requests" |

#### `role_permission` — which role can do what *(ERD ROLE_PERMISSION)*
Just two columns, `role_id` + `permission_id`, together the primary key. Example: Maker has 5 permissions,
Checker 7, Super Admin 14. Changing a role's rights = adding/removing rows here, no code change.

### 3.2 Branches — AP-02

#### `branches` — branch offices + HQ *(ERD BRANCH)*

| Column | Meaning |
| --- | --- |
| id *(ERD branch_id)* | |
| code | **Added.** `BR-001`, `HQ-001` — the portal shows and searches by code. |
| name, address *(ERD)* | |
| lat, lng *(ERD lat, long)* | Map pin. `long` renamed to `lng` because `LONG` is a MySQL keyword. |
| handover_radius *(ERD)* | Geo-fence in metres (the circle on the map). CHECK keeps it 10–2000. |
| contact_name, contact_phone, contact_email *(ERD contact_name, mobile_number, email)* | Who receives the handover OTP. All required (the form requires every field). |
| is_hq *(ERD)* | `TRUE` for the HQ cash centre — the other end of every trip. |
| is_active *(ERD status)* | `FALSE` = deactivated: kept for history but not offered for new requests. |

#### `branch_directory` — the bank's master list
**Added.** The "Branch Code" dropdown in Add Branch only lists branches that are *in the bank's list but not
yet in the portal*. This table is that list (code, name, address, lat, lng). In a real bank it would come
from core banking — out of scope, so we store it ourselves.

### 3.3 Users — AP-01, AP-03, DA-01

#### `users` — everyone who logs in *(ERD USER)*
One table for portal staff **and** delivery team members; `role_id` tells them apart.

| Column | Meaning |
| --- | --- |
| id *(ERD user_id)* | |
| employee_id *(ERD)* | `U-017`, shown in User Accounts. Unique. |
| name, email | **Added** — the UI shows full names and emails. |
| username *(ERD)* | Login name, unique. |
| phone *(ERD)* | |
| password *(ERD)* | **Hashed** (bcrypt, `$2y$12$…`). The real password is never stored, so it can't be shown in a table. |
| must_change_password | **Added.** `TRUE` when an admin set a default password in Add User → user should change it. |
| role_id *(ERD)* | FK → roles |
| branch_id | **Added.** Office staff's branch (NULL for delivery staff). |
| is_delivery_member *(ERD member)* | Driver / Custodian flag. |
| is_active | **Added.** "Deactivate user" (spec AP-03). |
| is_locked *(ERD)*, failed_attempts *(ERD failed-attempt)*, locked_at | Lock after 5 wrong passwords (AP-01). Hyphen removed: not allowed in column names. |
| last_login_at | **Added.** Useful for auditing. |
| email_verified_at, remember_token | Laravel's standard user columns ("remember me"). |

### 3.4 Delivery teams — AP-03, AP-07

#### `teams` *(ERD TEAM)*

| Column | Meaning |
| --- | --- |
| id *(ERD team_id)*, name *(ERD team_name)* | `Team A` |
| status *(ERD is_available)* | `available` / `on_trip` / `offline`. On-trip teams can't be edited or assigned. (Offline = spec stretch goal "team availability status".) |
| is_confirmed, confirmed_at, confirmed_by | **Added** for the "Confirm team" button: a team needs 1 driver + 2 custodians and must be confirmed before it can be assigned. Changing members resets it. |

#### `team_members` *(ERD TEAM MEMBERS)*
`team_id` + `user_id`. **`user_id` is UNIQUE** → a person can only be in one team (matches the portal: people
already in a team aren't offered in Create Team).

### 3.5 Cash requests — AP-04 … AP-07

#### `cash_requests` *(ERD REQUEST)*
Named `cash_requests` because `Request` is already a class name in Laravel.

| Column | Meaning |
| --- | --- |
| id *(ERD request_id)* | |
| reference | **Added.** `REQ-1048` shown everywhere in the UI. |
| type *(ERD request_type)* | `deposit` (branch → HQ) or `withdrawal` (HQ → branch). |
| branch_id *(ERD)* | |
| amount *(ERD)*, currency *(ERD)* | `DECIMAL(15,2)`, default `MMK`. CHECK amount > 0. |
| denominations | **Added.** Optional note breakdown (spec AP-04). |
| requested_date, complete_within | **Added.** The form's "Requested date" and "Complete within" (1h … 2 days). |
| due_at | **Added.** Deadline = date + window → used to mark trips on time / late in Reports. |
| remarks *(ERD)* | |
| status *(ERD)* | `pending → approved → assigned → in_transit → completed`, or `rejected` / `cancelled` (spec section 4). |
| created_by *(ERD)* | The Maker. |
| approved_by, approved_at *(ERD)* | The Checker. |
| rejected_by, rejected_at, reject_reason | **Added** (ERD had one `reason`). Reject needs a reason. |
| cancelled_by, cancelled_at, cancel_reason, cancelled_by_role | **Added.** `maker` = withdrew own Pending request (shows only in All Requests); `checker` = cancelled an Approved/Assigned one (shows in Approvals → Cancelled). |
| completed_at | **Added.** When proof of delivery was submitted. |
| ~~duration~~ *(ERD)* | **Removed** — it was a timestamp; duration is calculated from the trip's start/end. |

**Rule in the database:** `chk_cr_maker_checker` — `approved_by` and `rejected_by` can never equal
`created_by`. Even if the backend has a bug, MySQL refuses a maker approving their own request.

### 3.6 Trips & tracking — AP-07, AP-08, DA-03 … DA-05

#### `trips` *(ERD TRIP)*
One per request (`cash_request_id` is UNIQUE). Reassigning before the trip starts just changes `team_id`.

| Column | Meaning |
| --- | --- |
| id *(ERD trip_id)*, cash_request_id *(ERD request_id)*, team_id *(ERD)* | |
| assigned_by | **Added.** Which Checker assigned it. |
| status *(ERD)* | `assigned` / `in_transit` / `completed` / `cancelled` |
| current_lat, current_lng *(ERD current_lat, current_long)* | Latest position, drawn on the live map. |
| last_ping_at | **Added.** Last GPS update. Older than the threshold → "Stale GPS" warning (AP-08). |
| assigned_at, accepted_at, arrived_pickup_at, arrived_dropoff_at | **Added.** The app's steps (accept, arrived) from DA-03/DA-05. |
| started_at, completed_at *(ERD)* | Duration in Reports = completed − started. |
| is_on_time | **Added.** For the "on-time vs late" chart. |

#### `trip_locations` — GPS history
**Added.** The app sends its position every few seconds (DA-05). `trips` only holds the latest point; this
table keeps every point (`lat`, `lng`, `accuracy_m`, `recorded_at`) so the route line can be drawn and
replayed by the GPS simulator (SYS-01).

### 3.7 OTP handover — DA-06

#### `otps` *(ERD OTP)*

| Column | Meaning |
| --- | --- |
| id *(ERD otp_id)*, cash_request_id *(ERD request_id)* | |
| stage *(ERD type pick-up/drop)* | `pickup` or `dropoff` |
| code_hash *(ERD otp_code)* | **Hashed**, like a password — whoever reads the database can't see live codes. |
| sent_to, channel | **Added.** Phone/email it went to, SMS or email. |
| expires_at *(ERD otp_expire_at)* | |
| attempts *(ERD attempt)* | Wrong tries so far; locked after the limit. |
| status *(ERD)* | `pending` / `verified` / `expired` / `failed` |
| requested_by, verified_at | **Added.** Who tapped "Request OTP", when it was verified. |

#### `otp_attempts`
**Added** because the spec says "each attempt is logged": one row per typed code (who, success or not, GPS, time).

### 3.8 Proof of delivery — DA-07

#### `handover_proofs` *(ERD HANDOVER_PROOF)*

| Column | Meaning |
| --- | --- |
| id *(ERD handover_id)*, cash_request_id *(ERD request_id)* | |
| stage | **Added.** Normally `dropoff` (the final handover). |
| confirmed_amount *(ERD)* | Amount counted at handover. |
| denominations | **Added.** Spec: "confirms amount (and denominations if provided)". |
| signature_path *(ERD signature_url)* | Optional signature image. "path" = where Laravel stored the file. |
| receiver_name, lat, lng | **Added.** Who received it and where. |
| submitted_by, submitted_at *(ERD)* | |

#### `handover_photos`
**Added.** The ERD had one `photo_url`, but the spec says "at least one photo" → can be many → separate
table, one row per photo `path`.

### 3.9 Audit trail — AP-09

#### `audit_logs` *(ERD AUDIT_TRAIL)*

| Column | Meaning |
| --- | --- |
| id *(ERD event_id)* | |
| user_id *(ERD)* | Who did it |
| cash_request_id *(ERD request_id)* | NULL for non-request actions (login, user created…) |
| action *(ERD action_type)* | `create`, `approve`, `reject`, `assign`, `cancel`, `accept`, `otp.verified`, `completed`, `login`… |
| details | **Added.** JSON extras: reason, team name, old/new values. |
| source | **Added.** `web` / `mobile` / `system` |
| lat, lng | **Added.** GPS stamp for app events (spec AP-09). |
| ip_address | **Added.** Standard for audit. |
| created_at *(ERD timestamp)* | With milliseconds. No `updated_at` — audit rows never change. |

The request **timeline** in the portal is built from these rows — no extra timeline table.

**Triggers `audit_logs_no_update` / `audit_logs_no_delete`:** the spec says audit records "cannot be edited
or deleted". These make MySQL itself refuse any UPDATE or DELETE on this table.

### 3.10 Notifications — DA-02

#### `notifications` *(ERD NOTIFICATION)*

| Column | Meaning |
| --- | --- |
| id *(ERD noti_id)*, user_id *(ERD)*, cash_request_id *(ERD request_id)* | Recipient and related request |
| type, title, body *(ERD)* | e.g. `team.assigned`, "New assignment", "REQ-1043 · Hlaing → HQ" |
| link | **Added.** Where clicking it goes, e.g. `/approvals/REQ-1048` |
| read_at *(ERD is_read)* | NULL = unread. A time instead of true/false also tells *when* it was read. |

#### `device_tokens` — *optional*
Push notifications need each phone's token (from Firebase). One row per phone. Only needed when real push
is implemented.

### 3.11 Settings — *optional*

#### `settings`
Key/value pairs for numbers the spec says will be "agreed in the Week 2 design workshop": OTP expiry (300 s),
OTP attempts (3), GPS interval (10 s), stale GPS threshold (120 s), idle timeout (15 min), max failed logins (5).
Storing them here means changing them without redeploying. Could be replaced with `.env` values.

### 3.12 Views (saved queries) — *optional*

| View | What it returns | Used by |
| --- | --- | --- |
| `v_trip_history` | One row per finished trip: reference, date, branch, team, amount, duration in minutes, on time | Trip History page, Reports, CSV export |
| `v_live_trips` | Trips on the road with their latest position and an `is_stale` flag | Live Monitoring |

They store no data; the backend could write the same `SELECT` in PHP instead.

---

## Part 4 — The Laravel tables ("section 0"), explained

You did not design these and don't have to defend them — they're part of the framework. One line each:

| Table | What Laravel uses it for | Replaces in ERD |
| --- | --- | --- |
| `sessions` | Logged-in portal sessions (cookie → user). Idle timeout works through this. | **SESSION** |
| `personal_access_tokens` | Login tokens for the **mobile app** (Laravel Sanctum). Logout deletes the token. | **SESSION** (app side) |
| `password_reset_tokens` | "Forgot password" links (not used yet). | — |
| `cache`, `cache_locks` | Laravel's cache storage (speed-ups, rate limits). | — |
| `jobs`, `job_batches`, `failed_jobs` | Background job queue — e.g. sending OTP emails and push notifications without making the user wait. | — |

**Why your ERD's SESSION table was dropped:** Laravel already creates a table called `sessions`; a second one
would clash, and Laravel's version already does expiry and logout.

**Never run `php artisan migrate` in this project.** We create every table — including these 8 — from the SQL
files. Running migrate would try to create `users`, `cache`, … a second time and fail.

---

## Part 5 — Step-by-step testing

Three stages, in order. You can do stage 1 today; stages 2–3 need backend code first.

### Stage 1 — Test the database on its own (no backend needed)

1. Open **XAMPP Control Panel** → **Start** Apache and MySQL.
2. Go to **http://localhost/phpmyadmin**.
3. Click the **SQL** tab, paste all of `database/sql/01_schema.sql`, click **Go**.
   (If a `cashroute` database already exists with tables, drop it first or the CREATEs fail.)
4. Click **SQL** again, paste `database/sql/02_seed.sql`, **Go**.
5. In the left panel click **cashroute** → you should see the tables. Click `users` → **Browse** → 16 users.
6. Try real queries in the **SQL** tab (select the `cashroute` database first):

   ```sql
   -- Who can log in to the portal?
   SELECT u.employee_id, u.name, r.name AS role
   FROM users u JOIN roles r ON r.id = u.role_id
   WHERE r.channel = 'portal';

   -- Pending requests waiting for a checker
   SELECT reference, type, amount FROM cash_requests WHERE status = 'pending';

   -- Trip history / live trips (the views)
   SELECT * FROM v_trip_history;
   SELECT * FROM v_live_trips;

   -- Timeline of one request
   SELECT a.created_at, u.name, a.action, a.details
   FROM audit_logs a JOIN users u ON u.id = a.user_id
   JOIN cash_requests c ON c.id = a.cash_request_id
   WHERE c.reference = 'REQ-1042' ORDER BY a.created_at;
   ```

7. Prove the safety rules work — each of these **should fail with an error**:

   ```sql
   UPDATE audit_logs SET action = 'x' WHERE id = 1;          -- audit is read-only
   DELETE FROM audit_logs WHERE id = 1;                      -- audit is read-only
   UPDATE cash_requests SET approved_by = created_by
     WHERE reference = 'REQ-1048';                           -- maker can't approve own request
   INSERT INTO team_members (team_id, user_id)
     SELECT (SELECT id FROM teams WHERE name='Team C'),
            (SELECT id FROM users WHERE employee_id='U-004'); -- person already in Team A
   ```

That's a complete database test to show the mentor: data loads, queries answer real questions, and the rules
are enforced by the database.

### Stage 2 — Connect Laravel to MySQL and build an API

The APIs don't exist yet (only `/api/health`). Each module owner builds theirs on their `feature/*` branch.
The pattern is always the same — example for branches:

1. Point Laravel at MySQL — in `Cashroute_backend/.env`:
   ```
   DB_CONNECTION=mariadb
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=cashroute
   DB_USERNAME=root
   DB_PASSWORD=
   ```
   then `php artisan config:clear`.
2. **Model** (the PHP class for a table): `php artisan make:model Branch`
3. **Controller** (the code that answers a URL): `php artisan make:controller Api/BranchController --api`
   ```php
   public function index()
   {
       return \App\Models\Branch::orderBy('code')->get();
   }
   ```
4. **Route** (the URL) — in `routes/api.php`:
   ```php
   Route::get('/branches', [\App\Http\Controllers\Api\BranchController::class, 'index']);
   ```
5. Quick check in the browser: http://localhost/CashRoute/Cashroute_backend/public/api/branches
   → you should see the 6 seeded branches as JSON.

### Stage 3 — Test the APIs with Swagger (and Postman)

Yes — **Swagger comes after the backend**, because it tests APIs, and an API must exist first.

1. Install once: `composer require dedoc/scramble` (in `Cashroute_backend`).
2. Open http://localhost/CashRoute/Cashroute_backend/public/docs/api
3. Every route in `routes/api.php` is listed automatically. Click one → **Send API Request** → see the response.
4. For `POST`/`PUT` endpoints Swagger shows a form built from your validation rules — fill it, send, then check
   phpMyAdmin to see the row was really saved.
5. Test the rules through the API too: e.g. approve your own request → expect **403**; reject without a
   reason → expect **422** with a field error.

**Postman** is still useful for saved test cases (a "happy path" collection: login → create request → approve
→ assign) that you re-run after every change.

### Summary of "what do I test with what"

| I want to check… | Use |
| --- | --- |
| Tables, data, constraints | phpMyAdmin (Stage 1) |
| One API returns the right data | Browser (GET only) or Swagger |
| Sending data, errors, logins | Swagger or Postman |
| A whole workflow repeatedly | Postman collection |
| The real user experience | The portal with `VITE_USE_MOCK_API=false` |
