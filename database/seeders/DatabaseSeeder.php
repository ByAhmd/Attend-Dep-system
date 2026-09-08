<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AttendanceSetting;
use App\Models\JobTitle;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Safe to run on any environment, including production, as often as needed.
 *
 * Creates the settings row with the default 150 m radius, a starter list of
 * job titles on a table that has none, and - when the ADMIN_* environment
 * values are present - the first administrator. Existing accounts and
 * existing titles are never modified. Demonstration data lives in
 * DemoDataSeeder and is never called from here.
 */
final class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * The titles a company of this kind starts with, so the employee form
     * has something to offer on the first day rather than an empty select
     * and a trip to another screen.
     *
     * @var list<array{name_ar: string, name_en: string}>
     */
    private const array STARTER_JOB_TITLES = [
        ['name_ar' => 'الموارد البشرية', 'name_en' => 'HR'],
        ['name_ar' => 'التسويق', 'name_en' => 'Marketing'],
        ['name_ar' => 'تقنية المعلومات', 'name_en' => 'IT'],
        ['name_ar' => 'هندسة البرمجيات', 'name_en' => 'Software Engineering'],
        ['name_ar' => 'المالية', 'name_en' => 'Finance'],
        ['name_ar' => 'العمليات', 'name_en' => 'Operations'],
    ];

    public function run(): void
    {
        AttendanceSetting::current();

        $this->seedStarterJobTitles();
        $this->seedConfiguredAdmin();
    }

    /**
     * Planted once, on a table that holds nothing.
     *
     * Guarded on "is the table empty" rather than matched row by row,
     * because scripts/deploy.sh runs `db:seed --force` on every deploy: a
     * firstOrCreate keyed on the name would recreate "Marketing" beside the
     * administrator's renamed version on the very next push, and the owner
     * would be deleting it again after every deployment.
     */
    private function seedStarterJobTitles(): void
    {
        if (JobTitle::query()->exists()) {
            return;
        }

        foreach (self::STARTER_JOB_TITLES as $title) {
            JobTitle::query()->create($title);
        }

        $this->command->info('Starter job titles created: '.count(self::STARTER_JOB_TITLES).'.');
    }

    private function seedConfiguredAdmin(): void
    {
        $email = $this->configuredEmail();
        $password = (string) config('admin.password');

        if ($email === null || $password === '') {
            $this->command->warn(
                'Configured admin not created: ADMIN_EMAIL and ADMIN_PASSWORD are not set. Use `php artisan app:create-admin` instead.',
            );

            return;
        }

        $existing = User::query()->where('email', $email)->first();

        if ($existing instanceof User) {
            $this->command->info("Configured admin already exists: {$existing->email}. Existing credentials were left unchanged.");

            return;
        }

        $user = User::query()->create([
            'name' => $this->configuredName($email),
            'email' => $email,
            'password' => $password,
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);

        $this->command->info("Configured admin created: {$user->email}");
    }

    private function configuredEmail(): ?string
    {
        $email = trim((string) config('admin.email'));

        return $email === '' ? null : Str::lower($email);
    }

    private function configuredName(string $email): string
    {
        $name = trim((string) config('admin.name'));

        return $name !== '' ? $name : Str::before($email, '@');
    }
}
