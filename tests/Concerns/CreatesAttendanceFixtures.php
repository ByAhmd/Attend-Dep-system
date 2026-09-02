<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\UserStatus;
use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\User;
use App\Services\Geolocation\DistanceCalculator;
use App\Support\Geo\Coordinates;
use App\Support\Geo\LocationReading;
use Carbon\CarbonInterface;
use Database\Factories\AttendanceFactory;

/**
 * Fixture helpers for attendance tests.
 *
 * Every coordinate here is deterministic. The company sits at a fixed
 * Riyadh point and "a reading N metres away" is placed due north of it, so
 * the haversine distance is exactly R * delta-latitude and a test can put a
 * device at 149.99 m or 150.01 m and know which side of the line it is on.
 */
trait CreatesAttendanceFixtures
{
    protected function makeAdmin(?string $email = null, string $password = 'secret'): User
    {
        return User::factory()->admin()->create(array_filter([
            'email' => $email,
            'password' => $password,
        ]));
    }

    protected function makeEmployee(
        ?string $email = null,
        UserStatus $status = UserStatus::Active,
        string $password = 'secret',
    ): User {
        return User::factory()->create(array_filter([
            'email' => $email,
            'status' => $status,
            'password' => $password,
        ]));
    }

    /**
     * Sets the company location (and radius) the workflow verifies against.
     */
    protected function configureCompanyLocation(
        ?float $latitude = null,
        ?float $longitude = null,
        int $radiusMeters = 150,
    ): AttendanceSetting {
        $settings = AttendanceSetting::current();

        $settings->forceFill([
            'latitude' => $latitude ?? AttendanceFactory::COMPANY_LATITUDE,
            'longitude' => $longitude ?? AttendanceFactory::COMPANY_LONGITUDE,
            'radius_meters' => $radiusMeters,
        ])->save();

        return $settings;
    }

    protected function companyCoordinates(): Coordinates
    {
        return new Coordinates(AttendanceFactory::COMPANY_LATITUDE, AttendanceFactory::COMPANY_LONGITUDE);
    }

    /**
     * A point the given number of metres due north of the company.
     */
    protected function coordinatesMetersFromCompany(float $meters): Coordinates
    {
        $deltaLatitude = rad2deg($meters / DistanceCalculator::EARTH_RADIUS_METERS);

        return new Coordinates(
            AttendanceFactory::COMPANY_LATITUDE + $deltaLatitude,
            AttendanceFactory::COMPANY_LONGITUDE,
        );
    }

    protected function readingMetersFromCompany(float $meters, float $accuracyMeters = 10.0): LocationReading
    {
        return new LocationReading($this->coordinatesMetersFromCompany($meters), $accuracyMeters);
    }

    protected function readingAtCompany(float $accuracyMeters = 10.0): LocationReading
    {
        return new LocationReading($this->companyCoordinates(), $accuracyMeters);
    }

    /**
     * An open attendance record (checked in, not out) for the employee, on
     * today's date unless another day is given.
     */
    protected function checkedIn(User $employee, ?CarbonInterface $on = null): Attendance
    {
        $factory = Attendance::factory()->for($employee);

        if ($on instanceof CarbonInterface) {
            $factory = $factory->on($on);
        }

        return $factory->create();
    }

    protected function checkedOut(User $employee, ?CarbonInterface $on = null): Attendance
    {
        $factory = Attendance::factory()->for($employee)->checkedOut();

        if ($on instanceof CarbonInterface) {
            $factory = $factory->on($on);
        }

        return $factory->create();
    }
}
