<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** User Accounts: list, Add User, Edit User / Unlock, Change password. */
class UserController extends Controller
{
    /** GET /api/users — any signed-in user (other screens show creators' names). */
    public function index()
    {
        return UserResource::collection(
            User::with(['role', 'branch', 'teams'])->orderBy('employee_id')->get()
        );
    }

    /** POST /api/users — Add User (needs users.manage). */
    public function store(Request $request)
    {
        abort_unless($request->user()->hasPermission('users.manage'), 403, 'Only a Super Admin can add users.');
        $data = $this->validated($request);
        $role = Role::where('name', $data['role'])->firstOrFail();

        $user = User::create([
            'employee_id' => $this->nextEmployeeId(),
            'name' => $data['name'],
            'username' => $this->uniqueUsername($data['username'] ?? $data['name']),
            'email' => $data['email'],
            'phone' => $data['mobile'] ?? null,
            'password' => $data['password'],            // hashed automatically (User casts)
            'must_change_password' => true,
            'role_id' => $role->id,
            'branch_id' => $this->branchIdFor($role, $data['branchId'] ?? null),
            'is_delivery_member' => $role->channel === 'mobile',
            'is_locked' => ($data['status'] ?? 'Active') === 'Locked',
        ]);

        Audit::log('user.create', null, ['user' => $user->employee_id, 'role' => $role->name]);

        return (new UserResource($user->load(['role', 'branch', 'teams'])))->response()->setStatusCode(201);
    }

    /**
     * PUT /api/users/{id} — {id} is the employee id, e.g. U-001.
     * Super Admin: edit everything, unlock, reset password.
     * Anyone else: only their own password (Change password screen).
     */
    public function update(Request $request, string $id)
    {
        $user = User::where('employee_id', $id)->firstOrFail();
        $me = $request->user();
        $canManage = $me->hasPermission('users.manage');
        abort_unless($canManage || $me->is($user), 403, 'You can only change your own password.');

        $data = $this->validated($request, $user);

        if ($canManage) {
            $role = Role::where('name', $data['role'])->firstOrFail();
            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['mobile'] ?? null,
                'role_id' => $role->id,
                'branch_id' => $this->branchIdFor($role, $data['branchId'] ?? null),
                'is_delivery_member' => $role->channel === 'mobile',
            ]);

            // Status Active = unlock (clears failed attempts); Locked = lock now.
            if (($data['status'] ?? null) === 'Active') {
                $user->fill(['is_locked' => false, 'failed_attempts' => 0, 'locked_at' => null]);
            } elseif (($data['status'] ?? null) === 'Locked' && ! $user->is_locked) {
                $user->fill(['is_locked' => true, 'locked_at' => now()]);
            }
        }

        if (! empty($data['password'])) {
            $user->password = $data['password'];
            $user->must_change_password = ! $me->is($user);   // own change = done; admin reset = change again
        }

        $user->save();
        Audit::log('user.update', null, ['user' => $user->employee_id, 'changed' => array_keys($user->getChanges())]);

        return new UserResource($user->load(['role', 'branch', 'teams']));
    }

    /** Field names match the portal's form (src/services/types.ts → UserInput). */
    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')->ignore($user?->id)],
            'role' => ['required', Rule::exists('roles', 'name')],
            'mobile' => ['nullable', 'string', 'max:30', 'regex:/^\+?[\d\s-]{6,}$/'],
            'status' => ['nullable', Rule::in(['Active', 'Locked'])],
            'branchId' => ['nullable', Rule::exists('branches', 'code')],
            'username' => ['nullable', 'string', 'max:60'],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8'],
        ]);
    }

    /** U-001, U-002 … → next free number. */
    private function nextEmployeeId(): string
    {
        $max = User::pluck('employee_id')->map(fn ($e) => (int) preg_replace('/\D/', '', $e))->max() ?? 0;

        return 'U-' . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    /** "Test Driver" → test.driver (test.driver2 if taken). */
    private function uniqueUsername(string $name): string
    {
        $base = Str::of($name)->lower()->replaceMatches('/[^a-z0-9]+/', '.')->trim('.')->value() ?: 'user';
        $username = $base;
        for ($i = 2; User::where('username', $username)->exists(); $i++) {
            $username = $base . $i;
        }

        return $username;
    }

    /** Delivery staff have no branch; office staff get the picked branch code → id. */
    private function branchIdFor(Role $role, ?string $code): ?int
    {
        if ($role->channel === 'mobile' || ! $code) {
            return null;
        }

        return Branch::where('code', $code)->value('id');
    }
}
