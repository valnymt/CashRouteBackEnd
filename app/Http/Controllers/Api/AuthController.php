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
