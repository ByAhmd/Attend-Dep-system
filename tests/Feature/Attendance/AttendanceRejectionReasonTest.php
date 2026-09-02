<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Data\Attendance\LocationVerification;
use App\Enums\AttendanceRejectionReason;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The sentence an employee reads when refused must never contradict the
 * verdict: a reading refused for being past the limit is shown past the
 * limit, in whole metres, in both languages.
 */
final class AttendanceRejectionReasonTest extends TestCase
{
    #[Test]
    public function a_distance_just_outside_the_radius_is_never_announced_as_equal_to_it(): void
    {
        App::setLocale('en');

        $verification = new LocationVerification(
            distanceMeters: 150.3,
            allowedRadiusMeters: 150,
            accuracyMeters: 10.0,
            maxAccuracyMeters: 100.0,
        );

        $this->assertFalse($verification->isWithinRadius());

        $message = AttendanceRejectionReason::OutsideAllowedArea->message($verification);

        $this->assertStringContainsString('151 meters away', $message);
        $this->assertStringContainsString('within 150 meters', $message);
        $this->assertStringNotContainsString('150 meters away', $message);
    }

    #[Test]
    public function an_accuracy_just_past_the_ceiling_is_rounded_up_in_the_message(): void
    {
        App::setLocale('en');

        $verification = new LocationVerification(
            distanceMeters: 0.0,
            allowedRadiusMeters: 150,
            accuracyMeters: 100.3,
            maxAccuracyMeters: 100.0,
        );

        $this->assertFalse($verification->isAccurateEnough());

        $this->assertStringContainsString(
            '±101 m',
            AttendanceRejectionReason::InsufficientAccuracy->message($verification),
        );
    }

    #[Test]
    public function the_arabic_sentence_carries_the_same_numbers(): void
    {
        App::setLocale('ar');

        $message = AttendanceRejectionReason::OutsideAllowedArea->message(new LocationVerification(
            distanceMeters: 150.3,
            allowedRadiusMeters: 150,
            accuracyMeters: 10.0,
            maxAccuracyMeters: 100.0,
        ));

        $this->assertStringContainsString('151', $message);
        $this->assertStringContainsString('150', $message);
        $this->assertStringNotContainsString('attendance.rejections', $message);
    }

    #[Test]
    public function a_reason_without_a_verification_still_reads_as_a_sentence(): void
    {
        $message = AttendanceRejectionReason::OutsideAllowedArea->message();

        $this->assertStringContainsString('150', $message);
        $this->assertStringContainsString('—', $message);
        $this->assertStringNotContainsString(':distance', $message);
    }
}
