<?php

declare(strict_types=1);

namespace App\Support\Geo;

/**
 * The map link the admin screens open for a stored position.
 *
 * Attendance records, rejected attempts and the settings preview all offer
 * "open in map". One template keeps the three links identical, and keeps
 * the coordinates at the seven decimals they are stored with - PHP's
 * default float-to-string would render a position near the equator in
 * scientific notation, which a map search does not read.
 */
final class GoogleMapsLink
{
    public static function to(Coordinates $coordinates): string
    {
        return sprintf(
            'https://www.google.com/maps?q=%s,%s',
            number_format($coordinates->latitude, 7, '.', ''),
            number_format($coordinates->longitude, 7, '.', ''),
        );
    }
}
