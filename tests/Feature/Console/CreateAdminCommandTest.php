<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * app:create-admin - the only way the first administrator comes to exist,
 * since there is no registration and no invitation. Every option is passed
 * explicitly so no prompt is ever reached.
 */
final class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_an_active_administrator_with_a_hashed_password(): void
    {
        $this->artisan('app:create-admin', [
            '--name' => 'First Admin',
            '--email' => 'First.Admin@Example.test',
            '--password' => 'correct-horse-battery',
        ])
            ->expectsOutputToContain('Administrator created: first.admin@example.test')
            ->assertExitCode(0);

        $this->assertDatabaseCount('users', 1);

        $admin = User::query()->where('email', 'first.admin@example.test')->firstOrFail();

        $this->assertSame('First Admin', $admin->name);
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertSame(UserStatus::Active, $admin->status);
        $this->assertNotSame('correct-horse-battery', $admin->getRawOriginal('password'));
        $this->assertTrue(Hash::check('correct-horse-battery', (string) $admin->getRawOriginal('password')));
    }

    #[Test]
    public function an_email_already_in_use_creates_nothing(): void
    {
        $existing = User::factory()->create(['email' => 'taken@example.test', 'password' => 'first-password']);
        $originalHash = (string) $existing->getRawOriginal('password');

        $this->artisan('app:create-admin', [
            '--name' => 'Second Admin',
            '--email' => 'Taken@Example.test',
            '--password' => 'correct-horse-battery',
        ])->assertExitCode(1);

        $this->assertDatabaseCount('users', 1);

        $fresh = $existing->fresh();

        $this->assertInstanceOf(User::class, $fresh);
        $this->assertSame(UserRole::Employee, $fresh->role);
        $this->assertSame($originalHash, $fresh->getRawOriginal('password'));
    }

    #[Test]
    public function a_short_password_creates_nothing(): void
    {
        $this->artisan('app:create-admin', [
            '--name' => 'First Admin',
            '--email' => 'admin@example.test',
            '--password' => 'short',
        ])->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    #[Test]
    public function an_invalid_email_creates_nothing(): void
    {
        $this->artisan('app:create-admin', [
            '--name' => 'First Admin',
            '--email' => 'not-an-email',
            '--password' => 'correct-horse-battery',
        ])->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }
}
