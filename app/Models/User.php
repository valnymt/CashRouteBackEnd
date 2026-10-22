<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/** Portal staff and delivery team members; role_id decides which app they use. Table: users */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'name',
        'username',
        'email',
        'phone',
        'password',
        'must_change_password',
        'role_id',
        'branch_id',
        'is_delivery_member',
        'is_active',
        'is_locked',
        'failed_attempts',
        'locked_at',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',   // assigning a plain password stores its hash automatically
            'must_change_password' => 'boolean',
            'is_delivery_member' => 'boolean',
            'is_active' => 'boolean',
            'is_locked' => 'boolean',
            'failed_attempts' => 'integer',
            'locked_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /** At most one team (team_members.user_id is unique); use ->teams->first(). */
    public function teams()
    {
        return $this->belongsToMany(Team::class, 'team_members')->withTimestamps();
    }

    public function createdRequests()
    {
        return $this->hasMany(CashRequest::class, 'created_by');
    }

    public function userNotifications()
    {
        return $this->hasMany(UserNotification::class);
    }

    public function deviceTokens()
    {
        return $this->hasMany(DeviceToken::class);
    }

    /** e.g. $user->hasPermission('requests.approve') */
    public function hasPermission(string $code): bool
    {
        return $this->role->permissions()->where('code', $code)->exists();
    }
}
