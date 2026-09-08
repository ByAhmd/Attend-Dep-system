<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The job titles an account may be given.
 *
 * A table and not an enum, because this is the one list in the product the
 * owner extends themselves: the next title is added from a screen, not from
 * a deployment. Both names are required and each is unique on its own, so
 * one title cannot be entered twice under either language and no account
 * can end up carrying a title that is blank for half the readers - every
 * other user-facing string in this product exists in both languages and a
 * title printed under somebody's name is no different.
 *
 * is_active retires a title instead of deleting it. A title somebody holds
 * cannot be deleted at all (the foreign key on users.job_title_id
 * restricts), and retiring says the honest thing: stop offering it, leave it
 * on the people who have it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_titles', function (Blueprint $table): void {
            $table->id();

            $table->string('name_ar', 100);
            $table->string('name_en', 100);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique('name_ar');
            $table->unique('name_en');
            $table->index('is_active');
        });

        // A title with no name is not a title. The unique index would let a
        // single empty string through, and the form's `required` lives in
        // PHP; this is the rule whatever writes the row.
        DB::statement("ALTER TABLE job_titles ADD CONSTRAINT job_titles_name_ar_check CHECK (name_ar <> '')");
        DB::statement("ALTER TABLE job_titles ADD CONSTRAINT job_titles_name_en_check CHECK (name_en <> '')");
    }

    public function down(): void
    {
        Schema::dropIfExists('job_titles');
    }
};
