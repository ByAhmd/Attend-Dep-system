<?php

declare(strict_types=1);

namespace Tests\Unit\Geo;

use App\Support\Geo\Coordinates;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Coordinates are validated on construction, so nothing downstream - the
 * distance calculation, the attendance row, the audit row - can ever hold a
 * point that is not on Earth.
 */
final class CoordinatesTest extends TestCase
{
    /**
     * @return array<string, array{float, float}>
     */
    public static function pointsOffTheGlobe(): array
    {
        return [
            'latitude just above 90' => [90.0000001, 0.0],
            'latitude just below -90' => [-90.0000001, 0.0],
            'latitude of 400' => [400.0, 46.6753],
            'longitude just above 180' => [0.0, 180.0000001],
            'longitude just below -180' => [0.0, -180.0000001],
            'longitude of 720' => [24.7136, 720.0],
            'latitude not a number' => [NAN, 46.6753],
            'longitude not a number' => [24.7136, NAN],
        ];
    }

    #[Test]
    #[DataProvider('pointsOffTheGlobe')]
    public function a_point_off_the_globe_is_refused(float $latitude, float $longitude): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Coordinates($latitude, $longitude);
    }

    /**
     * @return array<string, array{float, float}>
     */
    public static function boundaryPoints(): array
    {
        return [
            'north pole' => [90.0, 0.0],
            'south pole' => [-90.0, 0.0],
            'antimeridian, east side' => [0.0, 180.0],
            'antimeridian, west side' => [0.0, -180.0],
            'all four limits at once' => [-90.0, 180.0],
            'null island' => [0.0, 0.0],
        ];
    }

    #[Test]
    #[DataProvider('boundaryPoints')]
    public function the_limits_themselves_are_valid_points(float $latitude, float $longitude): void
    {
        $coordinates = new Coordinates($latitude, $longitude);

        $this->assertSame($latitude, $coordinates->latitude);
        $this->assertSame($longitude, $coordinates->longitude);
    }

    #[Test]
    public function a_riyadh_point_keeps_its_full_precision(): void
    {
        $coordinates = new Coordinates(24.7136123, 46.6753456);

        $this->assertSame(24.7136123, $coordinates->latitude);
        $this->assertSame(46.6753456, $coordinates->longitude);
    }

    #[Test]
    public function the_refusal_names_the_offending_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Latitude must be between -90 and 90, 91 given.');

        new Coordinates(91.0, 0.0);
    }
}
