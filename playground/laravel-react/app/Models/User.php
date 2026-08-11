<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Inlay\Concerns\InteractsWithPanelAccount;
use Inlay\Contracts\PanelAccount;
use Inlay\Contracts\PanelUser;
use Inlay\Panel;
use Inlay\TwoFactorAuthentication\Concerns\HasTwoFactorAuthentication;
use Inlay\TwoFactorAuthentication\Contracts\TwoFactorAuthenticatable;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $role
 * @property string $status
 * @property bool $active
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class User extends Authenticatable implements PanelAccount, PanelUser, TwoFactorAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasTwoFactorAuthentication, InteractsWithPanelAccount, Notifiable, SoftDeletes;

    use HasRoles {
        roles as protected permissionRoles;
    }

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'password', 'role', 'status', 'active'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'active' => 'boolean',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_recovery_codes' => 'array',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->role === 'admin' && $this->active;
    }

    /** @return HasMany<UserNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(UserNote::class);
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->permissionRoles()->withPivot('assignment_note');
    }
}
