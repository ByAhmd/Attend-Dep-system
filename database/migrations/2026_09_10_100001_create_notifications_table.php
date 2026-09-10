<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's own notifications table, which is what the two bells read.
 *
 * The framework ships this table but not its migration - `notifications:table`
 * publishes one - so it is written out here rather than generated, with the
 * one change this product needs.
 *
 * That change is the index. The stock table indexes (notifiable_type,
 * notifiable_id), and every read of it is `where notifiable = ? order by
 * created_at desc`: the bell's list, its unread count, and the count again on
 * every poll. Carrying created_at as the third column means the sort is the
 * index order rather than a filesort over one person's rows. It costs nothing
 * to add now and cannot be added cheaply once the table has a year of rows in
 * it, which is the only reason it is here for fifteen people.
 *
 * `data` is TEXT and holds JSON, which is what Laravel's cast expects; the
 * bell filters on `data->format`, and MariaDB 10.4 reads a JSON path out of a
 * TEXT column exactly as MySQL 8 does. Nothing here needs a JSON column type,
 * a generated column or a CHECK, so nothing here is version-dependent: the
 * table is CHAR(36), VARCHAR, BIGINT, TEXT and two DATETIMEs, and the index
 * key is 1,033 bytes against the 3,072 that InnoDB's DYNAMIC row format - the
 * default on both engines - allows.
 *
 * Rows are deleted by the reader, from the bell's own "Clear" button, and by
 * nothing else. There is no cron on this host and no scheduled prune: fifteen
 * people filing a few requests a week produce a few thousand rows a year, and
 * a few thousand rows behind a covering index is not a problem worth a
 * command nobody would run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(
                ['notifiable_type', 'notifiable_id', 'created_at'],
                'notifications_notifiable_created_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
