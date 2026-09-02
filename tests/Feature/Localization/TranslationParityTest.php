<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use App\Data\Attendance\LocationVerification;
use App\Enums\AttendanceAction;
use App\Enums\AttendanceRejectionReason;
use App\Enums\AttendanceStatus;
use App\Enums\NavigationGroup;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use UnitEnum;

/**
 * Every user-facing string exists in both languages.
 *
 * A key present in one locale and missing from the other renders as its
 * raw path to whoever reads in the locale that lacks it, which is a
 * hardcoded string wearing a different hat. Enum labels and rejection
 * sentences are resolved for real in both locales, because a key that
 * exists but is spelled differently from what the enum asks for fails the
 * same way at runtime.
 */
final class TranslationParityTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function locales(): array
    {
        return [
            'english' => ['en'],
            'arabic' => ['ar'],
        ];
    }

    #[Test]
    public function both_locales_carry_the_same_files(): void
    {
        $this->assertNotSame([], $this->files('en'));
        $this->assertSame($this->files('en'), $this->files('ar'));
    }

    #[Test]
    public function every_english_key_has_an_arabic_counterpart(): void
    {
        $missing = array_keys(array_diff_key($this->keys('en'), $this->keys('ar')));

        $this->assertSame([], $missing, 'missing from lang/ar: '.implode(', ', $missing));
    }

    #[Test]
    public function every_arabic_key_has_an_english_counterpart(): void
    {
        $missing = array_keys(array_diff_key($this->keys('ar'), $this->keys('en')));

        $this->assertSame([], $missing, 'missing from lang/en: '.implode(', ', $missing));
    }

    #[Test]
    #[DataProvider('locales')]
    public function no_translation_is_an_empty_string(string $locale): void
    {
        $empty = array_keys(array_filter($this->keys($locale), static fn (string $value): bool => trim($value) === ''));

        $this->assertSame([], $empty, "empty in lang/{$locale}: ".implode(', ', $empty));
    }

    #[Test]
    public function every_labelled_enum_is_covered(): void
    {
        // The discovery below walks app/Enums; this pins what it must find
        // so an empty directory glob could never pass silently.
        $this->assertEqualsCanonicalizing(
            [UserRole::class, UserStatus::class, AttendanceStatus::class, AttendanceAction::class, AttendanceRejectionReason::class],
            $this->labelledEnums(),
        );
    }

    #[Test]
    #[DataProvider('locales')]
    public function every_enum_case_has_a_label(string $locale): void
    {
        App::setLocale($locale);

        foreach ($this->labelledEnums() as $enum) {
            foreach ($enum::cases() as $case) {
                $this->assertTrue(method_exists($case, 'label'));

                $label = (string) $case->label();

                $this->assertNotSame('', $label, "{$enum}::{$case->name} has an empty label in {$locale}");
                $this->assertStringNotContainsString('enums.', $label, "{$enum}::{$case->name} has no label in {$locale}");
            }
        }
    }

    #[Test]
    #[DataProvider('locales')]
    public function every_rejection_reason_has_a_sentence_for_the_employee(string $locale): void
    {
        App::setLocale($locale);

        $verification = new LocationVerification(
            distanceMeters: 212.37,
            allowedRadiusMeters: 150,
            accuracyMeters: 650.0,
            maxAccuracyMeters: 100.0,
        );

        foreach (AttendanceRejectionReason::cases() as $reason) {
            foreach ([$reason->message(), $reason->message($verification)] as $message) {
                $this->assertNotSame('', $message, "{$reason->name} has an empty message in {$locale}");
                $this->assertStringNotContainsString('attendance.rejections', $message, "{$reason->name} has no message in {$locale}");
                $this->assertDoesNotMatchRegularExpression('/:[a-z_]+/', $message, "{$reason->name} left a placeholder unfilled in {$locale}");
            }
        }

        // A quantity refused for being too large is reported rounded up.
        $this->assertStringContainsString('213', AttendanceRejectionReason::OutsideAllowedArea->message($verification));
        $this->assertStringContainsString('150', AttendanceRejectionReason::OutsideAllowedArea->message($verification));
        $this->assertStringContainsString('650', AttendanceRejectionReason::InsufficientAccuracy->message($verification));
    }

    #[Test]
    #[DataProvider('locales')]
    public function every_navigation_group_has_a_label(string $locale): void
    {
        App::setLocale($locale);

        foreach (NavigationGroup::cases() as $group) {
            $label = $group->getLabel();

            $this->assertNotSame('', $label, "{$group->name} has an empty label in {$locale}");
            $this->assertStringNotContainsString('navigation.', $label, "{$group->name} has no label in {$locale}");
        }
    }

    /**
     * @return list<string>
     */
    private function files(string $locale): array
    {
        $files = array_map(
            static fn (string $path): string => basename($path),
            glob(lang_path($locale.'/*.php')) ?: [],
        );

        sort($files);

        return $files;
    }

    /**
     * @return array<string, string>
     */
    private function keys(string $locale): array
    {
        $flat = [];

        foreach (glob(lang_path($locale.'/*.php')) ?: [] as $file) {
            $lines = require $file;

            $this->assertIsArray($lines, "{$file} does not return an array");

            $flat += $this->flatten($lines, basename($file, '.php'));
        }

        return $flat;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, string>
     */
    private function flatten(array $data, string $prefix): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $path = $prefix.'.'.$key;

            if (is_array($value)) {
                $flat += $this->flatten($value, $path);

                continue;
            }

            $flat[$path] = is_scalar($value) ? (string) $value : '';
        }

        return $flat;
    }

    /**
     * Every enum under app/Enums that resolves its cases through label().
     *
     * @return list<class-string<UnitEnum>>
     */
    private function labelledEnums(): array
    {
        $enums = [];

        foreach (glob(app_path('Enums/*.php')) ?: [] as $file) {
            $class = 'App\\Enums\\'.basename($file, '.php');

            if (enum_exists($class) && method_exists($class, 'label')) {
                $enums[] = $class;
            }
        }

        return $enums;
    }
}
