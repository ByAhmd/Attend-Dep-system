<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which days of the week are the weekend.
 *
 * Until now every day was an ordinary day, which was fine while the
 * working day only judged people who showed up. The moment the dashboard
 * says who has NOT checked in, it must know which days nobody was expected
 * at all - and in Saudi Arabia that is Friday and Saturday, the column
 * default.
 *
 * Stored as a comma-separated list of lowercase English day names rather
 * than JSON: seven known words with a model accessor on either side do not
 * need a document type, and a plain varchar takes a plain default on every
 * MySQL this project meets. The application validates the words; a CHECK
 * over a list inside one column would be theatre.
 *
 * ON THE LIVE DATA: one ADD COLUMN with a default. The single settings row
 * takes Friday–Saturday from the default; no UPDATE runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table): void {
            $table->string('weekend_days', 100)
                ->default('friday,saturday')
                ->after('late_grace_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table): void {
            $table->dropColumn('weekend_days');
        });
    }
};
