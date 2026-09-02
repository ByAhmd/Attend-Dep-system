<?php

declare(strict_types=1);

namespace Tests\Unit\Geo;

use App\Support\Geo\Meters;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Distances are stored to the centimetre and shown as whole metres.
 */
final class MetersTest extends TestCase
{
    /**
     * @return array<string, array{float|int|string|null, string}>
     */
    public static function formattedValues(): array
    {
        return [
            'fraction rounded down' => [82.37, '82'],
            'fraction rounded up' => [82.61, '83'],
            'half rounds away from zero' => [82.5, '83'],
            'whole metres stay whole' => [150.0, '150'],
            'integer input' => [150, '150'],
            'decimal column string' => ['150.01', '150'],
            'zero' => [0.0, '0'],
            'thousands are grouped' => [2223.9, '2,224'],
            'null is a dash' => [null, '—'],
            'empty string is a dash' => ['', '—'],
        ];
    }

    #[Test]
    #[DataProvider('formattedValues')]
    public function metres_are_shown_whole(float|int|string|null $meters, string $expected): void
    {
        $this->assertSame($expected, Meters::format($meters));
    }
}
