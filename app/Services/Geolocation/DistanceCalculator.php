<?php

declare(strict_types=1);

namespace App\Services\Geolocation;

use App\Support\Geo\Coordinates;

/**
 * Great-circle distance between two points - the haversine formula.
 *
 * The Earth is modelled as a sphere of mean radius 6 371 km. Against the
 * real ellipsoid that is at most a 0.5% error, which over a 150 m radius is
 * under a metre and far below what a phone's GPS resolves. The formula is
 * also numerically stable for the tiny angles involved, which the simpler
 * spherical law of cosines is not.
 */
final readonly class DistanceCalculator
{
    /**
     * Mean Earth radius in metres (IUGG).
     */
    public const float EARTH_RADIUS_METERS = 6_371_000.0;

    public function metersBetween(Coordinates $from, Coordinates $to): float
    {
        $fromLatitude = deg2rad($from->latitude);
        $toLatitude = deg2rad($to->latitude);
        $deltaLatitude = deg2rad($to->latitude - $from->latitude);
        $deltaLongitude = deg2rad($to->longitude - $from->longitude);

        $a = sin($deltaLatitude / 2) ** 2
            + cos($fromLatitude) * cos($toLatitude) * sin($deltaLongitude / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_METERS * $c;
    }
}
