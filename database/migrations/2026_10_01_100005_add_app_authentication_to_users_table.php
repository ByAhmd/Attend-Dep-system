<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Authenticator-app two-factor columns, Filament's own shape: the TOTP
 * secret and the recovery codes, both encrypted casts on the model and
 * therefore TEXT here - an encrypted payload is several times its
 * plaintext and a fixed-width column would truncate it into garbage.
 *
 * Nullable, because enrolment is the exception: employees never enrol
 * (nothing offers them the screen), and an administrator's secret exists
 * only once they have walked through the required set-up at sign-in.
 * NULL simply means "no second factor", which is every account the day
 * this lands.
 *
 * ON THE LIVE DATA: two nullable ADD COLUMNs; no UPDATE runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('app_authentication_secret')
                ->nullable()
                ->after('annual_leave_override');

            $table->text('app_authentication_recovery_codes')
                ->nullable()
                ->after('app_authentication_secret');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['app_authentication_secret', 'app_authentication_recovery_codes']);
        });
    }
};
