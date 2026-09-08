<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\JobTitle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An active job title, distinct from every other one the factory has made.
 *
 * Both names carry a counter, because each is unique in its own right and a
 * test that asks for three titles must get three. The counter is not reset
 * between test methods, so the exact name is not something to assert
 * against: a test that cares what is printed under an employee's name
 * passes the names it wants, which is what the makeJobTitle() fixture does.
 *
 * @extends Factory<JobTitle>
 */
final class JobTitleFactory extends Factory
{
    public const string NAME_AR = 'مسمى';

    public const string NAME_EN = 'Title';

    /**
     * How many titles this process has made. Not per test: the two unique
     * indexes are the reason the suffix exists, and a counter that restarted
     * would hand out a name the previous test already used.
     */
    private static int $made = 0;

    public function definition(): array
    {
        self::$made++;

        return [
            'name_ar' => self::NAME_AR.' '.self::$made,
            'name_en' => self::NAME_EN.' '.self::$made,
            'is_active' => true,
        ];
    }

    /**
     * Still held by whoever holds it, no longer offered to anybody new.
     */
    public function retired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
