<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Data\Attendance\LocationVerification;
use App\Models\AttendanceSetting;
use App\Services\Geolocation\DistanceCalculator;
use App\Support\Geo\LocationReading;

/**
 * Measures a reading against the configured company location.
 *
 * The backend is the only authority on distance. The browser may show the
 * employee a number for feedback, but the value that decides and the value
 * that is stored are both computed here from the raw coordinates.
 */
final readonly class LocationVerifier
{
    public function __construct(
        private DistanceCalculator $distances,
    ) {}

    public function verify(LocationReading $reading, ?AttendanceSetting $settings = null): LocationVerification
    {
        $settings ??= AttendanceSetting::current();
        $company = $settings->coordinates();

        return new LocationVerification(
            distanceMeters: $company === null
                ? null
                : $this->distances->metersBetween($company, $reading->coordinates),
            allowedRadiusMeters: $settings->radius_meters,
            accuracyMeters: $reading->accuracyMeters,
            maxAccuracyMeters: (float) config('attendance.max_accuracy_meters'),
        );
    }
}
