<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The production-safe seeder: the settings row always, the bootstrap
 * administrator only when the environment names one, and never a change
 * to an account that already exists.
 */
final class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The developer's .env may carry ADMIN_* values; the tests decide.
        config(['admin.name' => null, 'admin.email' => null, 'admin.password' => null]);
    }

    #[Test]
    public function it_creates_the_settings_row_and_no_account_when_no_administrator_is_configured(): void
    {
        $this->artisan('db:seed')
            ->expectsOutputToContain('Configured admin not created')
            ->assertExitCode(0);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseHas('attendance_settings', ['id' => 1, 'radius_meters' => 150, 'latitude' => null, 'longitude' => null]);
    }

    #[Test]
    public function it_creates_an_active_administrator_from_the_configured_credentials(): void
    {
        config(['admin.name' => 'Bootstrap Admin', 'admin.email' => 'Admin@Example.test', 'admin.password' => 'topsecret-password']);

        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@example.test')->first();

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('Bootstrap Admin', $user->name);
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertNotSame('topsecret-password', $user->getRawOriginal('password'));
        $this->assertTrue(Hash::check('topsecret-password', (string) $user->getRawOriginal('password')));
        $this->assertDatabaseCount('users', 1);
    }

    #[Test]
    public function the_name_falls_back_to_the_mailbox_when_none_is_configured(): void
    {
        config(['admin.email' => 'ops@example.test', 'admin.password' => 'topsecret-password']);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', ['email' => 'ops@example.test', 'name' => 'ops']);
    }

    #[Test]
    public function a_password_alone_creates_nothing(): void
    {
        config(['admin.password' => 'topsecret-password']);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 0);
    }

    #[Test]
    public function it_never_touches_an_account_that_already_uses_the_configured_email(): void
    {
        $existing = User::factory()->create([
            'email' => 'admin@example.test',
            'password' => 'first-password',
            'role' => UserRole::Employee,
        ]);
        $originalHash = (string) $existing->getRawOriginal('password');

        config(['admin.email' => 'admin@example.test', 'admin.password' => 'second-password']);

        $this->artisan('db:seed')
            ->expectsOutputToContain('already exists')
            ->assertExitCode(0);

        $fresh = User::query()->where('email', 'admin@example.test')->firstOrFail();

        $this->assertDatabaseCount('users', 1);
        $this->assertSame($originalHash, $fresh->getRawOriginal('password'));
        $this->assertSame(UserRole::Employee, $fresh->role);
        $this->assertTrue(Hash::check('first-password', $originalHash));
    }

    #[Test]
    public function it_can_be_run_again_without_a_second_settings_row(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('attendance_settings', 1);
    }
}
