<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmploymentType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Scopes\AccountSoftDeletingScope;
use App\Support\Filament\PanelAccess;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * An account - an administrator or an employee.
 *
 * FilamentUser is what makes canAccessPanel() run at all. Without the
 * interface Filament's Authenticate middleware never asks, and in a local
 * environment every account would reach every panel.
 *
 * Accounts are soft deleted, never removed. "Delete" here means hide and
 * keep the records: the row stays, so the attendance rows, rejected
 * attempts and presence pings that point at it still read with the person's
 * name on them, while Eloquent's global scope takes the account out of the
 * employee list and out of the authentication provider's reach, which is
 * what stops it signing in.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property UserRole $role
 * @property UserStatus $status
 * @property EmploymentType $employment_type
 * @property ?int $job_title_id
 * @property CarbonInterface|null $deleted_at
 * @property-read ?JobTitle $jobTitle
 * @property-read Collection<int, AttendanceCorrection> $attendanceCorrections
 * @property-read Collection<int, LeaveRequest> $leaveRequests
 */
#[Fillable(['name', 'email', 'password', 'role', 'status', 'employment_type', 'job_title_id'])]
#[Hidden(['password', 'remember_token'])]
final class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;
    use SoftDeletes;

    /**
     * The column default, repeated where a new instance can see it.
     *
     * `employment_type` is NOT NULL with a database default, and a database
     * default only ever reaches the row: the model that wrote it still has
     * no such attribute until somebody re-reads it. Every account is an
     * ordinary employee unless it says otherwise, so the model says so from
     * the moment it is built, and the caption above a name reads the same
     * before and after the round trip.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'employment_type' => EmploymentType::Employee->value,
    ];

    /**
     * The soft-delete scope this model boots is the one that never hides
     * the super administrator.
     *
     * A method on the class wins over the one the trait would contribute,
     * so this replaces `SoftDeletes::bootSoftDeletes()` outright rather
     * than registering a second scope beside it - two would both apply and
     * the standard one would go on hiding the row. Everything else the
     * trait provides, `restore()`, `trashed()`, the `deleted_at` cast, is
     * inherited untouched, and the query macros come with the subclass.
     */
    public static function bootSoftDeletes(): void
    {
        self::addGlobalScope(new AccountSoftDeletingScope);
    }

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
            'employment_type' => EmploymentType::class,
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

    /**
     * @return BelongsTo<JobTitle, $this>
     */
    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class);
    }

    /**
     * @return HasMany<AttendanceCorrection, $this>
     */
    public function attendanceCorrections(): HasMany
    {
        return $this->hasMany(AttendanceCorrection::class);
    }

    /**
     * @return HasMany<LeaveRequest, $this>
     */
    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /**
     * The line printed above the person's name: "Marketing intern",
     * "Marketing", "Intern", or nothing at all.
     *
     * Composed and never stored, so renaming a title renames it on every
     * screen at once, and each language reads in its own word order instead
     * of in a phrase somebody once froze into a column. The Arabic is
     * masculine: this system holds no gender and will not begin collecting
     * one for the sake of a caption.
     *
     * Neither half of this is a permission. An intern signs in, checks in,
     * and is refused outside the radius exactly as anybody else is.
     */
    public function positionLabel(): ?string
    {
        $title = $this->jobTitle?->displayName();

        return match (true) {
            $this->employment_type->isIntern() && $title !== null => __('employees.position.intern_of', ['title' => $title]),
            $this->employment_type->isIntern() => __('employees.position.intern'),
            $title !== null => $title,
            default => null,
        };
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return PanelAccess::canAccess($this, $panel->getId());
    }

    /**
     * The super administrator is an administrator whatever the column says,
     * which is the point of pinning them in .env: flipping `users.role` to
     * 'employee' in the database must not take the system away from its
     * owner.
     */
    public function isAdmin(): bool
    {
        return $this->isSuperAdmin() || $this->role->isAdmin();
    }

    /**
     * Likewise for `users.status`: a super administrator is treated as
     * active however the row is set, so a deactivation - accidental, or
     * written straight into the table - cannot lock them out.
     */
    public function isActive(): bool
    {
        return $this->isSuperAdmin() || $this->status->canAuthenticate();
    }

    /**
     * The one account that may delete accounts and appoint or remove other
     * administrators, and that nobody may deactivate, demote or delete.
     *
     * It is whoever holds the address in SUPER_ADMIN_EMAIL, read through
     * config so `config:cache` keeps working. Not a column, not a third
     * role: a row can be edited by another administrator or by anyone with
     * database access, and the owner asked for something that cannot be
     * undone from anywhere except the server's .env.
     *
     * The trade-off is stated plainly rather than hidden. Because the
     * answer comes from the environment and not from the row, this account
     * cannot be switched off from inside the application at all - not by
     * another administrator, and not by the super administrator themselves.
     * The only way to hand the role over, or to give it up, is to edit
     * SUPER_ADMIN_EMAIL on the server and reload the configuration. That is
     * the price of "cannot be undone by anyone except me", and it is paid
     * knowingly: whoever can reach .env already owns the deployment.
     *
     * Blank setting means there is no super administrator, and every
     * super-administrator-only power is simply unavailable.
     */
    public function isSuperAdmin(): bool
    {
        $configured = self::superAdminEmail();

        if ($configured === null) {
            return false;
        }

        $email = $this->getAttribute('email');

        return is_string($email) && Str::lower(trim($email)) === $configured;
    }

    /**
     * The configured address, trimmed and lower-cased, or null when the
     * setting is blank. One normalisation, used by the model and by the
     * query scope, so both agree on what "the same address" means.
     */
    public static function superAdminEmail(): ?string
    {
        $configured = config('admin.super_admin_email');

        if (! is_string($configured) || trim($configured) === '') {
            return null;
        }

        return Str::lower(trim($configured));
    }
}
