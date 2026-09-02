<?php

declare(strict_types=1);

namespace Tests\Unit\Attendance;

use App\Data\Attendance\LocationVerification;
use App\Enums\AttendanceRejectionReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The verdict on one reading, in isolation from the database and the
 * workflow: which rule fires first, where the radius line falls, and how
 * the distance is rounded before it is compared.
 */
final class LocationVerificationTest extends TestCase
{
    private const int RADIUS = 150;

    private const float MAX_ACCURACY = 100.0;

    #[Test]
    public function an_unconfigured_company_location_fails_whatever_the_reading_says(): void
    {
        $perfectReading = $this->verification(distance: null, accuracy: 0.0);
        $terribleReading = $this->verification(distance: null, accuracy: 5000.0);

        foreach ([$perfectReading, $terribleReading] as $verification) {
            $this->assertFalse($verification->isConfigured());
            $this->assertFalse($verification->isWithinRadius());
            $this->assertNull($verification->roundedDistance());
            $this->assertSame(AttendanceRejectionReason::LocationNotConfigured, $verification->rejectionReason());
            $this->assertFalse($verification->passes());
        }
    }

    #[Test]
    public function an_accuracy_above_the_ceiling_fails_even_at_the_company_door(): void
    {
        $verification = $this->verification(distance: 0.0, accuracy: 100.01);

        $this->assertFalse($verification->isAccurateEnough());
        $this->assertTrue($verification->isWithinRadius());
        $this->assertSame(AttendanceRejectionReason::InsufficientAccuracy, $verification->rejectionReason());
        $this->assertFalse($verification->passes());
    }

    #[Test]
    public function an_accuracy_exactly_at_the_ceiling_is_accepted(): void
    {
        $verification = $this->verification(distance: 0.0, accuracy: self::MAX_ACCURACY);

        $this->assertTrue($verification->isAccurateEnough());
        $this->assertNull($verification->rejectionReason());
        $this->assertTrue($verification->passes());
    }

    #[Test]
    public function a_distance_equal_to_the_radius_is_inside(): void
    {
        $verification = $this->verification(distance: 150.0, accuracy: 10.0);

        $this->assertTrue($verification->isWithinRadius());
        $this->assertTrue($verification->passes());
    }

    #[Test]
    public function the_distance_is_compared_at_centimetre_precision(): void
    {
        // 150.004 stores as 150.00 and is inside; 150.005 stores as 150.01
        // and is outside. The verdict and the persisted value never disagree.
        $justInside = $this->verification(distance: 150.004, accuracy: 10.0);
        $justOutside = $this->verification(distance: 150.005, accuracy: 10.0);

        $this->assertSame(150.0, $justInside->roundedDistance());
        $this->assertTrue($justInside->passes());

        $this->assertSame(150.01, $justOutside->roundedDistance());
        $this->assertFalse($justOutside->passes());
        $this->assertSame(AttendanceRejectionReason::OutsideAllowedArea, $justOutside->rejectionReason());
    }

    #[Test]
    public function a_reading_outside_the_radius_is_rejected_for_the_area(): void
    {
        $verification = $this->verification(distance: 500.0, accuracy: 10.0);

        $this->assertTrue($verification->isConfigured());
        $this->assertTrue($verification->isAccurateEnough());
        $this->assertFalse($verification->isWithinRadius());
        $this->assertSame(AttendanceRejectionReason::OutsideAllowedArea, $verification->rejectionReason());
    }

    #[Test]
    public function the_first_failing_rule_wins_in_a_fixed_order(): void
    {
        // Not configured beats a bad fix; a bad fix beats a bad distance.
        $everythingWrong = $this->verification(distance: null, accuracy: 5000.0);
        $badFixFarAway = $this->verification(distance: 5000.0, accuracy: 5000.0);
        $goodFixFarAway = $this->verification(distance: 5000.0, accuracy: 10.0);

        $this->assertSame(AttendanceRejectionReason::LocationNotConfigured, $everythingWrong->rejectionReason());
        $this->assertSame(AttendanceRejectionReason::InsufficientAccuracy, $badFixFarAway->rejectionReason());
        $this->assertSame(AttendanceRejectionReason::OutsideAllowedArea, $goodFixFarAway->rejectionReason());
    }

    /**
     * @return array<string, array{?float, float}>
     */
    public static function readings(): array
    {
        return [
            'not configured' => [null, 10.0],
            'bad fix at the door' => [0.0, 100.01],
            'good fix at the door' => [0.0, 10.0],
            'good fix on the line' => [150.0, 10.0],
            'good fix outside' => [150.01, 10.0],
            'bad fix outside' => [150.01, 100.01],
        ];
    }

    #[Test]
    #[DataProvider('readings')]
    public function passes_is_exactly_the_absence_of_a_rejection_reason(?float $distance, float $accuracy): void
    {
        $verification = $this->verification($distance, $accuracy);

        $this->assertSame($verification->rejectionReason() === null, $verification->passes());
    }

    #[Test]
    public function the_rounded_distance_has_two_decimals(): void
    {
        $this->assertSame(82.37, $this->verification(82.3749, 10.0)->roundedDistance());
        $this->assertSame(82.38, $this->verification(82.3751, 10.0)->roundedDistance());
        $this->assertSame(0.0, $this->verification(0.001, 10.0)->roundedDistance());
        $this->assertSame(1234.57, $this->verification(1234.5678, 10.0)->roundedDistance());
    }

    #[Test]
    public function the_verdict_keeps_every_number_it_was_built_from(): void
    {
        $verification = $this->verification(82.37, 12.5);

        $this->assertSame(82.37, $verification->distanceMeters);
        $this->assertSame(self::RADIUS, $verification->allowedRadiusMeters);
        $this->assertSame(12.5, $verification->accuracyMeters);
        $this->assertSame(self::MAX_ACCURACY, $verification->maxAccuracyMeters);
    }

    private function verification(?float $distance, float $accuracy): LocationVerification
    {
        return new LocationVerification(
            distanceMeters: $distance,
            allowedRadiusMeters: self::RADIUS,
            accuracyMeters: $accuracy,
            maxAccuracyMeters: self::MAX_ACCURACY,
        );
    }
}
