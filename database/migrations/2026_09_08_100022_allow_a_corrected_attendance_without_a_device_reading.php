<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A moment an approved correction supplied has no coordinates, and must not
 * pretend to have any.
 *
 * Until now every recorded moment carried a position, because the only
 * writer was AttendanceWorkflow and it only ever wrote what a device
 * reported. A correction fills in a moment the device never saw, and there
 * are exactly two honest things to store in the four geo columns for it:
 * nothing, or an invention. This migration makes "nothing" legal and keeps
 * it legal only there.
 *
 * The guarantee traded is "every recorded moment has coordinates". The
 * guarantee gained is stronger: a recorded moment WITHOUT coordinates could
 * only have come from an approved correction, and the row names the request
 * that supplied it.
 *
 * ON THE LIVE DATA: the twenty-two rows here, and the production table, all
 * carry a full device reading and a NULL correction id, so both new rules
 * are satisfied by the first branch of each and no row is rewritten. The
 * four MODIFY statements may run with ALGORITHM=COPY and rebuild the table;
 * on this many rows that is instantaneous, and scripts/deploy.sh has the
 * site in maintenance while migrations run.
 *
 * MODIFY is written out rather than expressed with ->change(), because
 * DB_CONNECTION is mysql while production is MariaDB and the grammar
 * Laravel picks at runtime is a hazard this project has already met.
 *
 * The statement ORDER is the whole safety argument, and DDL is not
 * transactional on either engine: every step is arranged so that a failure
 * at that step leaves the table enforcing at least what it enforced before.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. The check-in rule goes on while the columns are still NOT NULL,
        //    so it is trivially satisfied and there is never an instant at
        //    which a NULL coordinate would have been legal.
        DB::statement(<<<'SQL'
            ALTER TABLE attendances ADD CONSTRAINT attendances_check_in_recorded_or_corrected CHECK (
                (check_in_latitude IS NOT NULL AND check_in_longitude IS NOT NULL
                 AND check_in_accuracy IS NOT NULL AND check_in_distance_from_company IS NOT NULL)
                OR
                (check_in_correction_id IS NOT NULL
                 AND check_in_latitude IS NULL AND check_in_longitude IS NULL
                 AND check_in_accuracy IS NULL AND check_in_distance_from_company IS NULL)
            )
        SQL);

        // 2. Relax the columns, with the rule already standing over them.
        DB::statement('ALTER TABLE attendances MODIFY check_in_latitude DECIMAL(10, 7) NULL');
        DB::statement('ALTER TABLE attendances MODIFY check_in_longitude DECIMAL(10, 7) NULL');
        DB::statement('ALTER TABLE attendances MODIFY check_in_accuracy DECIMAL(8, 2) NULL');
        DB::statement('ALTER TABLE attendances MODIFY check_in_distance_from_company DECIMAL(10, 2) NULL');

        // 3. The looser check-out rule goes on BESIDE the old one. It is
        //    strictly looser, so both hold at once for the one statement
        //    between here and step 4.
        DB::statement(<<<'SQL'
            ALTER TABLE attendances ADD CONSTRAINT attendances_check_out_recorded_or_corrected CHECK (
                (check_out_at IS NULL AND check_out_latitude IS NULL AND check_out_longitude IS NULL
                 AND check_out_accuracy IS NULL AND check_out_distance_from_company IS NULL)
                OR
                (check_out_at IS NOT NULL AND check_out_latitude IS NOT NULL AND check_out_longitude IS NOT NULL
                 AND check_out_accuracy IS NOT NULL AND check_out_distance_from_company IS NOT NULL)
                OR
                (check_out_at IS NOT NULL AND check_out_correction_id IS NOT NULL
                 AND check_out_latitude IS NULL AND check_out_longitude IS NULL
                 AND check_out_accuracy IS NULL AND check_out_distance_from_company IS NULL)
            )
        SQL);

        // 4. The old rule comes off LAST. A failure here leaves the table
        //    enforcing exactly what it enforced before this migration ran.
        DB::statement('ALTER TABLE attendances DROP CONSTRAINT attendances_check_out_complete');
    }

    /**
     * The old rules are restored BEFORE the new ones come off, for the same
     * reason the drop came last on the way up.
     *
     * This FAILS CLEANLY once any correction has supplied a coordinate-free
     * moment: restoring attendances_check_out_complete is refused by a
     * corrected check-out, and restoring NOT NULL is refused by a corrected
     * check-in. That is correct. Inventing coordinates to make a rollback
     * pass would be inventing a location reading, which is the one thing
     * this table exists not to do.
     */
    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE attendances ADD CONSTRAINT attendances_check_out_complete CHECK (
                (check_out_at IS NULL AND check_out_latitude IS NULL AND check_out_longitude IS NULL AND check_out_accuracy IS NULL AND check_out_distance_from_company IS NULL)
                OR
                (check_out_at IS NOT NULL AND check_out_latitude IS NOT NULL AND check_out_longitude IS NOT NULL AND check_out_accuracy IS NOT NULL AND check_out_distance_from_company IS NOT NULL)
            )
        SQL);

        DB::statement('ALTER TABLE attendances MODIFY check_in_latitude DECIMAL(10, 7) NOT NULL');
        DB::statement('ALTER TABLE attendances MODIFY check_in_longitude DECIMAL(10, 7) NOT NULL');
        DB::statement('ALTER TABLE attendances MODIFY check_in_accuracy DECIMAL(8, 2) NOT NULL');
        DB::statement('ALTER TABLE attendances MODIFY check_in_distance_from_company DECIMAL(10, 2) NOT NULL');

        DB::statement('ALTER TABLE attendances DROP CONSTRAINT attendances_check_out_recorded_or_corrected');
        DB::statement('ALTER TABLE attendances DROP CONSTRAINT attendances_check_in_recorded_or_corrected');
    }
};
