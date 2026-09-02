<?php

declare(strict_types=1);

namespace App\Support\Geo;

use InvalidArgumentException;

/**
 * One position fix as the browser Geolocation API reports it: where the
 * device believes it is, and the radius in metres within which it is 95%
 * confident. The accuracy travels with the coordinates because a position
 * without its accuracy cannot be judged.
 */
final readonly class LocationReading
{
    public function __construct(
        public Coordinates $coordinates,
        public float $accuracyMeters,
    ) {
        if (is_nan($accuracyMeters) || $accuracyMeters < 0.0) {
            throw new InvalidArgumentException("Accuracy must be zero or more metres, {$accuracyMeters} given.");
        }
    }

    public static function make(float $latitude, float $longitude, float $accuracyMeters): self
    {
        return new self(new Coordinates($latitude, $longitude), $accuracyMeters);
    }
}
