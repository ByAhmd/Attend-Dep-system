<?php

declare(strict_types=1);

namespace App\Support\Geo;

/**
 * Metres as people read them.
 *
 * Distances are stored to the centimetre but shown as whole metres: "82 m"
 * is what an employee standing in a car park can act on, "82.37 m" is not.
 */
final class Meters
{
    public static function format(float|int|string|null $meters): string
    {
        if ($meters === null || $meters === '') {
            return '—';
        }

        return number_format(round((float) $meters), 0, '.', ',');
    }
}
