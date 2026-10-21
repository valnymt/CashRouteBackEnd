# Member 1 Runbook — Foundation & Access

Your three branches, in order:

1. `feature/database-setup` — database, models, shared helpers (**everyone waits for this**)
2. `feature/auth-roles` — login / me / logout
3. `feature/users-teams-api` — User Accounts and Delivery Teams

Run every command in **Git Bash** (or the VS Code terminal) from the backend folder unless it says otherwise:

```bash
cd /c/xampp/htdocs/CashRoute/Cashroute_backend
```

Ground rules for the whole team (repeat them in the group chat):

- The database is built from `database/sql/*.sql`. **Never run `php artisan migrate`.**
- A table change = a new file `database/sql/03_<what>.sql` with `ALTER TABLE …`. Never edit `01_schema.sql`
  after it's merged — others already imported it.
- `.env` is never committed. Each person sets their own.
- Don't run the SQL files through a formatter / "beautify" before importing. Formatters capitalise column
  names that are also SQL keywords (`name` → `NAME`, `code`, `status`, `channel`), and Laravel then reads
  them as empty. Check with:
  `SELECT table_name, column_name FROM information_schema.columns WHERE table_schema='cashroute' AND BINARY column_name <> BINARY LOWER(column_name);`
  — it must return nothing.

---

## Part A — `feature/database-setup`

### A1. Get on the branch

```bash
git checkout develop
git pull
git checkout feature/database-setup
git merge develop
git status
```

`git status` should list `database/sql/` and `docs/` as untracked — those are the SQL files and guides.

### A2. Rebuild the database from scratch

1. XAMPP Control Panel → **Start** Apache and MySQL.
2. http://localhost/phpmyadmin → **SQL** tab → run:
   ```sql
   DROP DATABASE IF EXISTS cashroute;
   ```
3. **Import** tab (top menu, with no database selected) → *Choose file* →
   `C:\xampp\htdocs\CashRoute\Cashroute_backend\database\sql\01_schema.sql` → **Import**.
   (The file creates the `cashroute` database itself.)
4. Click **cashroute** in the left panel → **Import** → `02_seed.sql` → **Import**.
5. Check: `cashroute` has **27 tables**; `users` has 16 rows; `branches` has 6.

Command-line alternative (same result):

```bash
/c/xampp/mysql/bin/mysql.exe -u root -e "DROP DATABASE IF EXISTS cashroute;"
/c/xampp/mysql/bin/mysql.exe -u root < database/sql/01_schema.sql
/c/xampp/mysql/bin/mysql.exe -u root < database/sql/02_seed.sql
```

### A3. Point Laravel at MySQL

Open `.env` and set:

```
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cashroute
DB_USERNAME=root
DB_PASSWORD=
```

**`mariadb`, not `mysql`:** XAMPP ships MariaDB 10.4. With `mysql`, `php artisan db:show` fails with
`Table 'performance_schema.session_status' doesn't exist`; the `mariadb` driver avoids that. Models and
queries work the same either way.

Also update `.env.example` the same way (that one **is** committed, so teammates get the right defaults). Then:

```bash
php artisan config:clear
php artisan db:show
```

`db:show` should print `MariaDB 10.4…`, database `cashroute` and the tables (it lists every database on your
server, so the table total can be higher than 27). If it says
"could not find driver", enable `extension=pdo_mysql` in `C:\xampp\php\php.ini` and restart Apache.

### A4. Remove Laravel's default migrations

The SQL files already create those tables; keeping the migration files invites someone to run
`php artisan migrate` and break their database.

```bash
git rm database/migrations/*.php
```

### A5. Create the models (no migrations)

One model per table. **No `-m` flag** — tables already exist.

```bash
php artisan make:model Role
php artisan make:model Permission
php artisan make:model Branch
php artisan make:model BranchDirectory
php artisan make:model Team
php artisan make:model CashRequest
php artisan make:model Trip
php artisan make:model TripLocation
php artisan make:model Otp
php artisan make:model OtpAttempt
php artisan make:model HandoverProof
php artisan make:model HandoverPhoto
php artisan make:model AuditLog
php artisan make:model UserNotification
php artisan make:model DeviceToken
php artisan make:model Setting
```

`UserNotification`, not `Notification`: the `User` model's built-in `Notifiable` trait already owns the name
`notifications()`.

Fill them in. The pattern for most models (example `Branch`):

```php
class Branch extends Model
{
    protected $guarded = ['id'];                     // everything else is mass-assignable
    protected $casts = ['is_hq' => 'boolean', 'is_active' => 'boolean', 'lat' => 'float', 'lng' => 'float'];

    public function users() { return $this->hasMany(User::class); }
    public function cashRequests() { return $this->hasMany(CashRequest::class); }
}
```

Models whose table name doesn't follow Laravel's guess need `$table`:

| Model | Add |
| --- | --- |
| `BranchDirectory` | `protected $table = 'branch_directory';` |
| `UserNotification` | `protected $table = 'notifications';` |
| `Setting` | `protected $primaryKey = 'key'; public $incrementing = false; protected $keyType = 'string'; const CREATED_AT = null;` |
| `TripLocation`, `OtpAttempt` | `public $timestamps = false;` |
| `AuditLog` | `const UPDATED_AT = null;` and `protected $casts = ['details' => 'array', 'created_at' => 'datetime'];` |
| `CashRequest`, `HandoverProof` | `protected $casts = ['denominations' => 'array', ...];` |

Key relationships (add these, the rest can come later when a module needs them):

```php
// app/Models/User.php — add to the existing class
protected $fillable = ['employee_id', 'name', 'username', 'email', 'phone', 'password', 'must_change_password',
    'role_id', 'branch_id', 'is_delivery_member', 'is_active', 'is_locked', 'failed_attempts', 'locked_at'];
protected $hidden = ['password', 'remember_token'];
protected function casts(): array
{
    return ['password' => 'hashed', 'is_active' => 'boolean', 'is_locked' => 'boolean',
            'is_delivery_member' => 'boolean', 'must_change_password' => 'boolean', 'locked_at' => 'datetime'];
}
public function role()   { return $this->belongsTo(Role::class); }
public function branch() { return $this->belongsTo(Branch::class); }
public function teams()  { return $this->belongsToMany(Team::class, 'team_members')->withTimestamps(); }
public function hasPermission(string $code): bool
{
    return $this->role->permissions()->where('code', $code)->exists();
}

// app/Models/Role.php
public function permissions() { return $this->belongsToMany(Permission::class, 'role_permission'); }

// app/Models/Team.php
public function members() { return $this->belongsToMany(User::class, 'team_members')->withTimestamps(); }

// app/Models/CashRequest.php
public function branch()  { return $this->belongsTo(Branch::class); }
public function creator() { return $this->belongsTo(User::class, 'created_by'); }
public function trip()    { return $this->hasOne(Trip::class); }
```

`'password' => 'hashed'` means `$user->password = 'plain'` is hashed automatically — nobody can save a plain
password by accident.

### A6. Shared audit helper

Everyone writes audit rows the same way. Create `app/Support/Audit.php`:

```php
<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\CashRequest;

class Audit
{
    /** Audit::log('approve', $cashRequest, ['reason' => '...']); */
    public static function log(string $action, ?CashRequest $request = null, array $details = [],
                               string $source = 'web', ?float $lat = null, ?float $lng = null): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'cash_request_id' => $request?->id,
            'action' => $action,
            'details' => $details ?: null,
            'source' => $source,
            'lat' => $lat,
            'lng' => $lng,
            'ip_address' => request()->ip(),
        ]);
    }
}
```

### A7. Prove the models work

```bash
php artisan tinker
```

Inside tinker, type each line and press Enter:

```php
App\Models\User::count();                                   // 16
App\Models\User::where('username','admin.maker')->first()->role->name;   // "Super Admin"
App\Models\Team::where('name','Team A')->first()->members->pluck('name'); // Raj Kumar, Lena Ho, Tom Chew
App\Models\User::find(1)->hasPermission('requests.create'); // true (Amy is a Maker)
Hash::check('password', App\Models\User::find(1)->password); // true
exit
```

### A8. API docs page (for the whole team)

```bash
composer require dedoc/scramble
```

Open **http://127.0.0.1/CashRoute/Cashroute_backend/public/docs/api** → you should see `GET /api/health`.
Use `127.0.0.1`, not `localhost` (see B6 for why).

### A9. Commit, push, Pull Request

```bash
git add -A
git status
git commit -m "Database setup: SQL schema + seed, models, audit helper, API docs"
git push
```

GitHub → repo `valnymt/CashRouteBackEnd` → **Compare & pull request** → base **develop** ← compare
**feature/database-setup** → describe what's in it → **Create pull request**. A teammate approves → **Merge**.

### A10. Message for teammates (paste into the group chat)

> database-setup is merged. Do this once:
> 1. `git checkout develop && git pull`
> 2. `composer install`
> 3. phpMyAdmin → `DROP DATABASE IF EXISTS cashroute;` → Import `database/sql/01_schema.sql`, then `02_seed.sql`
> 4. In your `.env`: `DB_CONNECTION=mariadb`, `DB_DATABASE=cashroute`, `DB_USERNAME=root`, empty password → `php artisan config:clear`
> 5. Merge develop into your feature branch: `git checkout feature/<yours> && git merge develop`
> 6. Rules: never run `php artisan migrate`; table changes go in a new `database/sql/03_….sql` file; use `App\Support\Audit::log()` for every action.
> Demo logins: `admin.maker` (Super Admin), `amy.tan` (Maker), `daniel.lim` (Checker), drivers e.g. `raj.kumar` — password `password`.

---

## Part B — `feature/auth-roles`

### B1. Branch

```bash
git checkout feature/auth-roles
git merge develop
```

### B2. The JSON contract (agree on this — the whole team uses it)

The portal expects the shapes in `CashRoute_Admin_Portal/src/types/index.ts`. Use **API Resources** to convert
database rows into that shape. Rule: **IDs in JSON are the public codes** — `U-001`, `BR-001`, `REQ-1048`;
statuses are the portal's labels (`Active`, `Locked`, `Pending`, `In Transit`…).

```bash
php artisan make:resource UserResource
```

```php
// app/Http/Resources/UserResource.php
public function toArray($request): array
{
    return [
        'id'       => $this->employee_id,
        'name'     => $this->name,
        'username' => $this->username,
        'role'     => $this->role->name,
        'mobile'   => $this->phone ?? '',
        'email'    => $this->email,
        'status'   => $this->is_locked ? 'Locked' : 'Active',
        'branchId' => $this->branch?->code,
        'teamId'   => $this->teams->first() ? (string) $this->teams->first()->id : null,
    ];
}
```

### B3. Controller

```bash
php artisan make:controller Api/AuthController
```

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    /** Portal login (session cookie). */
    public function login(Request $request)
    {
        $data = $request->validate(['username' => 'required|string', 'password' => 'required|string']);
        $user = $this->checkCredentials($data['username'], $data['password']);

        if ($user->role->channel !== 'portal') {
            return response()->json(['message' => 'Delivery staff sign in with the CashRoute mobile app.'], 403);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        Audit::log('login');

        return new UserResource($user);
    }

    /** Token login for the mobile app and API tools (Swagger / Postman). */
    public function token(Request $request)
    {
        $data = $request->validate([
            'username' => 'required|string', 'password' => 'required|string', 'device_name' => 'required|string',
        ]);
        $user = $this->checkCredentials($data['username'], $data['password']);
        Auth::setUser($user);
        Audit::log('login', null, [], 'mobile');

        return ['token' => $user->createToken($data['device_name'])->plainTextToken, 'user' => new UserResource($user)];
    }

    public function me(Request $request)
    {
        return new UserResource($request->user());
    }

    public function logout(Request $request)
    {
        Audit::log('logout');
        $token = $request->user()->currentAccessToken();
        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $token->delete();                                 // mobile / Swagger token
        }
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
        return response()->noContent();
    }

    /** Shared checks: active, not locked, right password; locks after 5 failures. */
    private function checkCredentials(string $username, string $password): User
    {
        $user = User::where('username', $username)->first();

        if (! $user || ! $user->is_active) {
            abort(response()->json(['message' => 'Incorrect username or password.'], 401));
        }
        if ($user->is_locked) {
            abort(response()->json(['message' => 'Account locked'], 423));
        }
        if (! Hash::check($password, $user->password)) {
            $user->increment('failed_attempts');
            if ($user->failed_attempts >= self::MAX_ATTEMPTS) {
                $user->update(['is_locked' => true, 'locked_at' => now()]);
                abort(response()->json(['message' => 'Account locked'], 423));
            }
            $left = self::MAX_ATTEMPTS - $user->failed_attempts;
            abort(response()->json(['message' => "Incorrect username or password. {$left} attempts left."], 401));
        }

        $user->update(['failed_attempts' => 0, 'last_login_at' => now()]);
        return $user;
    }
}
```

### B4. Routes — `routes/api.php`

```php
use App\Http\Controllers\Api\AuthController;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/token', [AuthController::class, 'token']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    // every other module's routes go inside this group too
});
```

Delete the old `Route::get('/user', …)` route.

### B5. Let Swagger send a token

In `app/Providers/AppServiceProvider.php`, inside `boot()`:

```php
\Dedoc\Scramble\Scramble::configure()
    ->withDocumentTransformers(function (\Dedoc\Scramble\Support\Generator\OpenApi $openApi) {
        $openApi->secure(\Dedoc\Scramble\Support\Generator\SecurityScheme::http('bearer'));
    });
```

### B6. Test it

**In Swagger** — open it at **http://127.0.0.1/…/docs/api** (not `localhost`). Requests from `localhost` count
as "the portal" for Sanctum, which then demands a CSRF cookie and answers **419**. `127.0.0.1` avoids that.

| # | Request | Body | Expect |
| --- | --- | --- | --- |
| 1 | `POST /api/token` | `admin.maker` / `password` / `device_name: swagger` | 200 + `token` |
| 2 | Paste the token in the 🔒 auth box → `GET /api/me` | — | 200, `"role": "Super Admin"` |
| 3 | `POST /api/token` with a wrong password | | 401 "4 attempts left." |
| 4 | Repeat wrong password until locked | | 423 "Account locked"; phpMyAdmin → `users.is_locked = 1` |
| 5 | `POST /api/token` as `sam.yeo` (seeded as locked) | | 423 |
| 6 | `POST /api/logout` with the token | | 204, then `/api/me` → 401 |

Unlock the test user afterwards in phpMyAdmin:
`UPDATE users SET is_locked = 0, failed_attempts = 0, locked_at = NULL WHERE username = 'amy.tan';`

**In the real portal** (the session login):
1. Frontend `.env`: `VITE_USE_MOCK_API=false`, `VITE_REQUIRE_LOGIN=true`, then `npm run dev`.
2. Open http://localhost:5173 → log in as `admin.maker` / `password`.
3. Log in as `raj.kumar` → must be refused ("sign in with the mobile app").
4. Other pages will error — their APIs don't exist yet. That's expected.

### B7. Commit → push → PR into `develop` (same as A9).

---

## Part C — `feature/users-teams-api`

```bash
git checkout feature/users-teams-api
git merge develop
php artisan make:controller Api/UserController --api
php artisan make:controller Api/TeamController --api
php artisan make:resource TeamResource
php artisan make:request StoreUserRequest
```

### C1. Endpoints the portal calls

| Method + URL | Does | Rules (copy from the portal's `src/services/mockApi.ts`) |
| --- | --- | --- |
| `GET /api/users` | List users | `UserResource::collection(User::with('role','branch','teams')->get())` |
| `POST /api/users` | Add User | name, email (unique), role required; **password required, min 8** (hash it); `employee_id` = next `U-###`; username from the name if empty; delivery roles get `branch_id = null`, `is_delivery_member = true`; `Audit::log('user.create')` |
| `PUT /api/users/{id}` | Edit User / Unlock / Change password | `{id}` is `U-001` → find by `employee_id`. Setting status `Active` clears `is_locked` + `failed_attempts`. If `password` is sent (Change password screen), hash it and set `must_change_password = false`. |
| `GET /api/teams` | List teams | `TeamResource`: `id` (`(string) $this->id` — must match `teamId` in `UserResource`), `name`, `status` (`Available` / `On trip`), `confirmed`, `memberIds` (employee ids), `currentRequestId` |
| `POST /api/teams` | Create Team | name unique; members must be Driver/Custodian and not in another team; `confirmed: true` only if ≥ 1 driver + ≥ 2 custodians |
| `PUT /api/teams/{id}` | Edit members / Confirm | refuse (409) if the team is `on_trip`; any member change sets `is_confirmed = false` unless `confirmed: true` is sent and the rule passes |

Next employee id:

```php
$next = 'U-' . str_pad((int) User::max(DB::raw('CAST(SUBSTRING(employee_id, 3) AS UNSIGNED)')) + 1, 3, '0', STR_PAD_LEFT);
```

Team rule:

```php
$roles = User::whereIn('employee_id', $memberIds)->with('role')->get()->pluck('role.name');
$ok = $roles->filter(fn ($r) => $r === 'Driver')->count() >= 1
   && $roles->filter(fn ($r) => $r === 'Custodian')->count() >= 2;
```

Validation errors must come back as Laravel's standard **422** `{ "message": …, "errors": { "field": [...] } }`
— the portal shows them under the right input automatically. Form Requests do this for you.

### C2. Test in Swagger (token from `POST /api/token` as `admin.maker`)

| # | Request | Expect |
| --- | --- | --- |
| 1 | `GET /api/users` | 16 users, IDs `U-001`… |
| 2 | `POST /api/users` — a Driver with password `driver123` | 201, `id: "U-017"`; phpMyAdmin shows a `$2y$…` hash, not `driver123` |
| 3 | `POST /api/users` — same email again | 422 with `errors.email` |
| 4 | `POST /api/users` — password `123` | 422 with `errors.password` |
| 5 | `PUT /api/users/U-006` with `status: Active` | Sam Yeo unlocked (`is_locked = 0`) |
| 6 | `POST /api/teams` — 2 members, `confirmed: true` | 422 "needs 1 driver and 2 custodians" |
| 7 | `POST /api/teams` — Dina Wu + Hari Raj + U-017 (if a custodian) as draft | 201, `confirmed: false` |
| 8 | `PUT /api/teams/{Team B}` | 409 — on a trip |
| 9 | `POST /api/teams` with `U-004` (already in Team A) | 422 |

Then in the portal (`VITE_USE_MOCK_API=false`): Users & Teams → add a user, edit, unlock, create and confirm a team.

### C3. Commit → push → PR into `develop`.

---

## Reset anytime

Broke your data while testing? Rebuild in 10 seconds:

```bash
/c/xampp/mysql/bin/mysql.exe -u root -e "DROP DATABASE IF EXISTS cashroute;"
/c/xampp/mysql/bin/mysql.exe -u root < database/sql/01_schema.sql
/c/xampp/mysql/bin/mysql.exe -u root < database/sql/02_seed.sql
```

(Plus any `03_…`, `04_…` change files, in order.)
