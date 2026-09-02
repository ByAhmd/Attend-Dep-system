<?php

declare(strict_types=1);

namespace Tests\Unit\Geolocation;

use App\Services\Geolocation\DistanceCalculator;
use App\Support\Geo\Coordinates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The haversine distance the whole attendance rule rests on.
 *
 * Pure arithmetic, so no application is booted. The offsets are built the
 * same way the fixtures build them - an arc of N metres along a meridian or
 * a parallel - which is what lets the feature tests place a device at
 * 150.00 m and 150.01 m and know which side of the radius it stands on.
 */
final class DistanceCalculatorTest extends TestCase
{
    private const float COMPANY_LATITUDE = 24.7136;

    private const float COMPANY_LONGITUDE = 46.6753;

    private DistanceCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new DistanceCalculator;
    }

    #[Test]
    public function the_same_point_is_zero_metres_away(): void
    {
        $company = $this->company();

        $this->assertSame(0.0, $this->calculator->metersBetween($company, $company));
        $this->assertSame(0.0, $this->calculator->metersBetween($company, new Coordinates(self::COMPANY_LATITUDE, self::COMPANY_LONGITUDE)));
    }

    /**
     * @return array<string, array{float}>
     */
    public static function meridianOffsets(): array
    {
        return [
            '100 m' => [100.0],
            '150 m (the default radius)' => [150.0],
            '150.01 m (one centimetre outside)' => [150.01],
            '1000 m' => [1000.0],
        ];
    }

    #[Test]
    #[DataProvider('meridianOffsets')]
    public function a_point_due_north_measures_exactly_its_offset(float $meters): void
    {
        $north = new Coordinates(
            self::COMPANY_LATITUDE + rad2deg($meters / DistanceCalculator::EARTH_RADIUS_METERS),
            self::COMPANY_LONGITUDE,
        );

        $this->assertEqualsWithDelta($meters, $this->calculator->metersBetween($this->company(), $north), 1e-6);
    }

    /**
     * @return array<string, array{float}>
     */
    public static function parallelOffsets(): array
    {
        return [
            '100 m east' => [100.0],
            '150 m east' => [150.0],
            '1000 m east' => [1000.0],
            '100 m west' => [-100.0],
            '150 m west' => [-150.0],
            '1000 m west' => [-1000.0],
        ];
    }

    #[Test]
    #[DataProvider('parallelOffsets')]
    public function a_point_along_the_parallel_measures_its_offset_to_the_centimetre(float $meters): void
    {
        // Along a parallel the distance shrinks by cos(latitude), so the
        // longitude step has to grow by the same factor to cover N metres.
        $parallelRadius = DistanceCalculator::EARTH_RADIUS_METERS * cos(deg2rad(self::COMPANY_LATITUDE));

        $point = new Coordinates(
            self::COMPANY_LATITUDE,
            self::COMPANY_LONGITUDE + rad2deg($meters / $parallelRadius),
        );

        $this->assertEqualsWithDelta(abs($meters), $this->calculator->metersBetween($this->company(), $point), 0.01);
    }

    #[Test]
    public function the_distance_is_the_same_in_both_directions(): void
    {
        $riyadh = $this->company();
        $jeddah = new Coordinates(21.4858, 39.1925);
        $nearby = new Coordinates(self::COMPANY_LATITUDE + 0.0012, self::COMPANY_LONGITUDE - 0.0007);

        $this->assertSame(
            $this->calculator->metersBetween($riyadh, $jeddah),
            $this->calculator->metersBetween($jeddah, $riyadh),
        );
        $this->assertSame(
            $this->calculator->metersBetween($riyadh, $nearby),
            $this->calculator->metersBetween($nearby, $riyadh),
        );
    }

    #[Test]
    public function riyadh_to_jeddah_is_about_844_kilometres(): void
    {
        // A sphere against the real ellipsoid: at most half a percent out.
        $distance = $this->calculator->metersBetween($this->company(), new Coordinates(21.4858, 39.1925));

        $this->assertEqualsWithDelta(844_000.0, $distance, 844_000.0 * 0.005);
    }

    #[Test]
    public function crossing_the_antimeridian_is_a_short_hop_not_a_trip_around_the_world(): void
    {
        // 0.0002 degrees of longitude at the equator, taken the short way.
        $expected = deg2rad(0.0002) * DistanceCalculator::EARTH_RADIUS_METERS;

        $distance = $this->calculator->metersBetween(new Coordinates(0.0, 179.9999), new Coordinates(0.0, -179.9999));

        $this->assertEqualsWithDelta($expected, $distance, 0.01);
        $this->assertEqualsWithDelta(22.24, $distance, 0.05);
    }

    #[Test]
    public function pole_to_pole_is_half_the_circumference(): void
    {
        $distance = $this->calculator->metersBetween(new Coordinates(90.0, 0.0), new Coordinates(-90.0, 0.0));

        $this->assertEqualsWithDelta(M_PI * DistanceCalculator::EARTH_RADIUS_METERS, $distance, 1e-3);
    }

    #[Test]
    public function the_earth_radius_is_the_iugg_mean_radius(): void
    {
        // The fixtures place devices with this constant; a change here moves
        // every "N metres from the company" reading in the suite.
        $this->assertSame(6_371_000.0, DistanceCalculator::EARTH_RADIUS_METERS);
    }

    private function company(): Coordinates
    {
        return new Coordinates(self::COMPANY_LATITUDE, self::COMPANY_LONGITUDE);
    }
}
