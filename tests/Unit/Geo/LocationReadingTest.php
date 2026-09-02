<?php

declare(strict_types=1);

namespace Tests\Unit\Geo;

use App\Support\Geo\Coordinates;
use App\Support\Geo\LocationReading;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * A reading is a position plus the accuracy the browser attached to it,
 * and nothing else - in particular no time, because the server keeps the
 * clock.
 */
final class LocationReadingTest extends TestCase
{
    /**
     * @return array<string, array{float}>
     */
    public static function impossibleAccuracies(): array
    {
        return [
            'negative accuracy' => [-0.01],
            'strongly negative accuracy' => [-100.0],
            'accuracy not a number' => [NAN],
        ];
    }

    #[Test]
    #[DataProvider('impossibleAccuracies')]
    public function an_impossible_accuracy_is_refused(float $accuracy): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LocationReading(new Coordinates(24.7136, 46.6753), $accuracy);
    }

    #[Test]
    public function a_perfect_fix_of_zero_metres_is_accepted(): void
    {
        $reading = new LocationReading(new Coordinates(24.7136, 46.6753), 0.0);

        $this->assertSame(0.0, $reading->accuracyMeters);
    }

    #[Test]
    public function make_builds_the_reading_from_three_numbers(): void
    {
        $reading = LocationReading::make(24.7136, 46.6753, 12.5);

        $this->assertSame(24.7136, $reading->coordinates->latitude);
        $this->assertSame(46.6753, $reading->coordinates->longitude);
        $this->assertSame(12.5, $reading->accuracyMeters);
    }

    #[Test]
    public function make_refuses_a_point_off_the_globe(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LocationReading::make(95.0, 46.6753, 10.0);
    }

    #[Test]
    public function a_reading_carries_no_device_time(): void
    {
        // The browser sends latitude, longitude and accuracy. Were a device
        // timestamp ever added here it would be one refactor away from being
        // trusted; the workflow must have nothing to trust.
        $properties = array_map(
            static fn (ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass(LocationReading::class))->getProperties(),
        );

        $this->assertSame(['coordinates', 'accuracyMeters'], $properties);
    }
}
