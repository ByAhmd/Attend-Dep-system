<?php

declare(strict_types=1);

namespace App\Support\Geo;

use InvalidArgumentException;

/**
 * A point on Earth in decimal degrees (WGS 84, which is what browsers report).
 *
 * Validated on construction so that nothing downstream - the distance
 * calculation, the database, the audit table - can ever hold a latitude of
 * 400. Request input is validated separately by LocationReadingValidator;
 * this guard exists for every other caller.
 */
final readonly class Coordinates
{
    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {
        if (is_nan($latitude) || $latitude < -90.0 || $latitude > 90.0) {
            throw new InvalidArgumentException("Latitude must be between -90 and 90, {$latitude} given.");
        }

        if (is_nan($longitude) || $longitude < -180.0 || $longitude > 180.0) {
            throw new InvalidArgumentException("Longitude must be between -180 and 180, {$longitude} given.");
        }
    }
}
