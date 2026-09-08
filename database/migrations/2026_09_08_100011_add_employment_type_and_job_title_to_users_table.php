<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who a person is, beside what their account may do.
 *
 * Both columns describe the human being and neither is ever read as a
 * permission: role still decides what an account reaches, and an intern
 * signs in, checks in and is refused outside the radius in exactly the same
 * way as anybody else. They sit after `status` because that is where the
 * description of the person ends and nothing about access begins.
 *
 * employment_type is NOT NULL with a default, so every existing account
 * becomes an ordinary employee without a single row being rewritten - no
 * UPDATE runs here. job_title_id is nullable because an account may exist
 * before its title does, and NULL is honest where an invented title is not.
 * The foreign key restricts deletion: a title somebody holds cannot be
 * deleted out from under them, which is what makes "retire it instead" the
 * real answer.
 *
 * constrained() creates the index the foreign key needs, so no second index
 * on job_title_id is declared here. The CHECK mirrors the EmploymentType
 * enum: a value the code does not know cannot be stored, whatever writes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('employment_type', 20)->default('employee')->after('status');

            $table->foreignId('job_title_id')
                ->nullable()
                ->after('employment_type')
                ->constrained()
                ->restrictOnDelete();
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_employment_type_check CHECK (employment_type IN ('employee', 'intern'))");
    }

    /**
     * The CHECK goes first, then the foreign key, then the columns.
     *
     * DDL is not transactional on MySQL or MariaDB, so a step that is
     * refused leaves everything before it in place. Dropping the constraint
     * ahead of the column it guards means a refused rollback can never end
     * with `users` holding a value its own CHECK forbids.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_employment_type_check');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['job_title_id']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['employment_type', 'job_title_id']);
        });
    }
};
