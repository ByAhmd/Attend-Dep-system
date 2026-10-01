<?php

declare(strict_types=1);

namespace App\Data\Attendance;

/**
 * One employee's month, as the monthly report prints it: counts and sums
 * over records that exist, and one figure - the unrecorded days - that is
 * honest only because the working calendar first said which days anybody
 * was expected at all.
 *
 * Every working day that has passed is exactly one of three things for an
 * employee, decided in this order: attended (a session exists, whatever
 * else is true of the day), on approved leave, or unrecorded. The order
 * matters once: a person on leave who came in anyway counts as attended,
 * because the session is the stronger fact.
 *
 * "Unrecorded", never "absent": the report states that no session and no
 * approved leave covers the day, and nothing more.
 */
final readonly class MonthlyEmployeeSummary
{
    public function __construct(
        public string $employeeName,
        public ?string $jobTitle,
        public int $daysAttended,
        public int $secondsInside,
        public int $lateDays,
        public int $latenessSeconds,
        public int $earlyCheckOuts,
        public int $leaveDays,
        public int $daysUnrecorded,
    ) {}
}
