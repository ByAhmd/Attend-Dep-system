<?php

declare(strict_types=1);

namespace App\Data\Attendance;

use App\Enums\AttendanceRejectionReason;

/**
 * The backend's verdict on one location reading.
 *
 * Carries every number the verdict was built from, so the employee can be
 * told "82 metres, limit 150" and the audit table can store what was seen.
 * A null distance means the company location has not been configured yet;
 * nothing can be inside a radius that has no centre.
 */
final readonly class LocationVerification
{
    public function __construct(
        public ?float $distanceMeters,
        public int $allowedRadiusMeters,
        public float $accuracyMeters,
        public float $maxAccuracyMeters,
    ) {}

    public function isConfigured(): bool
    {
        return $this->distanceMeters !== null;
    }

    /**
     * The browser reports the radius it is 95% confident about. Beyond the
     * configured ceiling the fix says nothing useful about a 150 m boundary,
     * so it is refused rather than guessed at.
     */
    public function isAccurateEnough(): bool
    {
        return $this->accuracyMeters <= $this->maxAccuracyMeters;
    }

    /**
     * Inclusive: a distance equal to the radius is inside. Compared at the
     * precision that is stored (centimetres) so the record and the verdict
     * can never disagree.
     */
    public function isWithinRadius(): bool
    {
        $distance = $this->roundedDistance();

        return $distance !== null && $distance <= (float) $this->allowedRadiusMeters;
    }

    /**
     * Distance to the centimetre, as persisted on the attendance record.
     */
    public function roundedDistance(): ?float
    {
        return $this->distanceMeters === null ? null : round($this->distanceMeters, 2);
    }

    /**
     * The first rule that fails, in the order they are checked: no location
     * to measure against, then an untrustworthy fix, then the distance.
     */
    public function rejectionReason(): ?AttendanceRejectionReason
    {
        if (! $this->isConfigured()) {
            return AttendanceRejectionReason::LocationNotConfigured;
        }

        if (! $this->isAccurateEnough()) {
            return AttendanceRejectionReason::InsufficientAccuracy;
        }

        if (! $this->isWithinRadius()) {
            return AttendanceRejectionReason::OutsideAllowedArea;
        }

        return null;
    }

    public function passes(): bool
    {
        return $this->rejectionReason() === null;
    }
}
