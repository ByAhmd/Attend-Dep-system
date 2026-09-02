<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Support\Filament\PanelAccess;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * An account - an administrator or an employee.
 *
 * FilamentUser is what makes canAccessPanel() run at all. Without the
 * interface Filament's Authenticate middleware never asks, and in a local
 * environment every account would reach every panel.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property UserRole $role
 * @property UserStatus $status
 */
#[Fillable(['name', 'email', 'password', 'role', 'status'])]
#[Hidden(['password', 'remember_token'])]
final class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
        ];
    }

    /**
     * @return HasMany<Attendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * @return HasMany<AttendanceRejection, $this>
     */
    public function attendanceRejections(): HasMany
    {
        return $this->hasMany(AttendanceRejection::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return PanelAccess::canAccess($this, $panel->getId());
    }

    public function isAdmin(): bool
    {
        return $this->role->isAdmin();
    }

    public function isActive(): bool
    {
        return $this->status->canAuthenticate();
    }
}
