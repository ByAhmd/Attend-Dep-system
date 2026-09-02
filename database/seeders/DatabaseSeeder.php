<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AttendanceSetting;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Safe to run on any environment, including production, as often as needed.
 *
 * Creates the settings row with the default 150 m radius and, when the
 * ADMIN_* environment values are present, the first administrator. Existing
 * accounts are never modified. Demonstration data lives in DemoDataSeeder
 * and is never called from here.
 */
final class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        AttendanceSetting::current();

        $this->seedConfiguredAdmin();
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
