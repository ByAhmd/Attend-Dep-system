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

    /**
     * Whole metres rounded up, for a number being reported as too large.
     *
     * A reading refused at 150.3 m must not be announced as "150 m" next to
     * "within 150 m". The ceiling is taken on the centimetre value the
     * verdict and the audit row use, so floating-point noise cannot push
     * 151.00 up to 152.
     */
    public static function formatUp(float|int|string|null $meters): string
    {
        if ($meters === null || $meters === '') {
            return '—';
        }

        return number_format(ceil(round((float) $meters, 2)), 0, '.', ',');
    }
}
