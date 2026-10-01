<?php

declare(strict_types=1);

namespace App\Support\Attendance;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * The official working day, as three facts and the arithmetic they carry.
 *
 * The two ends are wall-clock times with no date and no timezone of their
 * own: the settings row stores "09:00", and what moment that is depends on
 * which day is being asked about. Every question is therefore asked with a
 * moment - lateThresholdOn() and endOn() pin the wall-clock time to that
 * moment's own calendar day - so the same settings row answers for today,
 * for a historical row on a list, and for a test on a frozen clock alike.
 * Riyadh is +03 all year, so pinning a wall-clock time to a day can never
 * be ambiguous; that is the same assumption the correction workflow leans
 * on.
 *
 * Lateness is measured from the START of the working day, not from the end
 * of the grace: the grace decides whether an arrival is called out at all,
 * and once it is, the figure reported is the working time actually missed.
 * Arriving at 09:45 against a 09:00 start is 45 minutes late, not 15.
 */
final readonly class WorkingHours
{
    /**
     * @param  string  $startsAt  wall-clock 'H:i' or 'H:i:s'
     * @param  string  $endsAt  wall-clock 'H:i' or 'H:i:s', after $startsAt
     */
    public function __construct(
        public string $startsAt,
        public string $endsAt,
        public int $lateGraceMinutes,
    ) {
        // The database CHECKs enforce both of these on the settings row;
        // repeating them here keeps a hand-built instance in a test from
        // describing a day the schema would refuse.
        if ($this->endOn(CarbonImmutable::today())->lte($this->startOn(CarbonImmutable::today()))) {
            throw new InvalidArgumentException('The working day must end after it starts.');
        }

        if ($lateGraceMinutes < 0) {
            throw new InvalidArgumentException('The late grace cannot be negative.');
        }
    }

    /**
     * When the working day starts on the given day.
     */
    public function startOn(CarbonInterface $day): CarbonImmutable
    {
        return CarbonImmutable::instance($day)->startOfDay()->setTimeFromTimeString($this->startsAt);
    }

    /**
     * When the working day ends on the given day. A check-out strictly
     * before this moment is an early check-out and needs a reason.
     */
    public function endOn(CarbonInterface $day): CarbonImmutable
    {
        return CarbonImmutable::instance($day)->startOfDay()->setTimeFromTimeString($this->endsAt);
    }

    /**
     * The last instant of the given day that is still on time: an arrival
     * strictly after this is late.
     */
    public function lateThresholdOn(CarbonInterface $day): CarbonImmutable
    {
        return $this->startOn($day)->addMinutes($this->lateGraceMinutes);
    }

    public function isLateArrival(CarbonInterface $checkInAt): bool
    {
        return $checkInAt->greaterThan($this->lateThresholdOn($checkInAt));
    }

    public function isEarlyCheckOut(CarbonInterface $checkOutAt): bool
    {
        return $checkOutAt->lessThan($this->endOn($checkOutAt));
    }

    /**
     * How much of the working day the arrival missed, in the unit sums and
     * the display formatter both take. Zero for anyone who arrived at or
     * before the start - time before the day began is not banked.
     */
    public function latenessSeconds(CarbonInterface $checkInAt): int
    {
        return max(0, (int) $this->startOn($checkInAt)->diffInSeconds($checkInAt));
    }
}
