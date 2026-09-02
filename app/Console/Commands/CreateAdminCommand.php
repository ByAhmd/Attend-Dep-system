<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password as promptPassword;
use function Laravel\Prompts\text;

/**
 * Creates the first administrator.
 *
 * There is no public registration and no invitation e-mail in this system,
 * so the first account has to come from the server. Every later account is
 * created by that administrator from the panel.
 */
final class CreateAdminCommand extends Command
{
    protected $signature = 'app:create-admin
        {--name= : Administrator full name}
        {--email= : Administrator email}
        {--password= : Administrator password (prompted when omitted)}';

    protected $description = 'Create an administrator account';

    public function handle(): int
    {
        $name = $this->option('name') ?? text(label: 'Administrator name', required: true);
        $email = Str::lower((string) ($this->option('email') ?? text(label: 'Administrator email', required: true)));
        $password = $this->option('password') ?? promptPassword(label: 'Password', required: true);

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'min:2', 'max:100'],
                'email' => ['required', 'email', 'max:150', 'unique:users,email'],
                'password' => ['required', 'string', Password::min(8)],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);

        $this->components->info("Administrator created: {$user->email}");
        $this->components->info('Sign in at /admin/login');

        return self::SUCCESS;
    }
}
