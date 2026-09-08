<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kinds of leave an employee may ask for.
 *
 * An enum and not a lookup table, unlike job titles, because this list is
 * fixed by labour law rather than by the company: the owner does not invent
 * a new kind of leave the way they invent a new job title. Adding a seventh
 * is one case here, one migration widening leave_requests_type_check, and
 * one key per language.
 *
 * The two bereavement cases are separate because the entitlement differs by
 * relation, and folding them into one would ask a grieving person to
 * explain the difference in the reason field.
 */
enum LeaveType: string
{
    case Annual = 'annual';
    case Sick = 'sick';
    case Exam = 'exam';
    case BereavementImmediate = 'bereavement_immediate';
    case BereavementSibling = 'bereavement_sibling';
    case Unpaid = 'unpaid';

    public function label(): string
    {
        return __('enums.leave_type.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            static fn (array $carry, self $case): array => $carry + [$case->value => $case->label()],
            [],
        );
    }
}
