<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Official holidays: named ranges of days on which nobody is expected.
 *
 * A range and not a single day, because an Eid is a week and nine rows
 * named "عيد الفطر" would be the same fact filed as clutter. Both ends are
 * inclusive, exactly as a leave request's are, and a one-day holiday has
 * starts_on equal to ends_on.
 *
 * Two names, as everywhere a word is printed to a reader who chose their
 * language. No is_active and no soft delete: a holiday that was entered
 * wrong is deleted outright, because unlike an attendance row it records a
 * decision about the future, not evidence about the past.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();

            // Overlap questions lead with starts_on; the table stays small
            // (a country has a dozen holidays a year), so one index serves.
            $table->index(['starts_on', 'ends_on']);
        });

        DB::statement('ALTER TABLE holidays ADD CONSTRAINT holidays_range_ordered_check CHECK (ends_on >= starts_on)');
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
