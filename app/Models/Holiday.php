<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Locale;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\HolidayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One official holiday: a named, inclusive range of days on which nobody
 * is expected at the company.
 *
 * It exists so the screens that would otherwise accuse can first ask "was
 * anybody expected that day at all": the absence list stays empty on it
 * and the monthly report does not count it as a day missed. It grants
 * nothing and blocks nothing - an employee who comes in on a holiday
 * records a perfectly ordinary session.
 *
 * @property int $id
 * @property string $name_ar
 * @property string $name_en
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
#[Fillable(['name_ar', 'name_en', 'starts_on', 'ends_on'])]
final class Holiday extends Model
{
    /** @use HasFactory<HolidayFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }

    /**
     * The holiday's name in the reader's own language.
     */
    public function displayName(): string
    {
        return Locale::current() === Locale::English ? $this->name_en : $this->name_ar;
    }

    /**
     * Calendar days covered, both ends included - the same arithmetic as a
     * leave request's, for the same inclusive range.
     */
    public function dayCount(): int
    {
        return (int) $this->starts_on->diffInDays($this->ends_on) + 1;
    }

    public function coversDate(CarbonInterface $date): bool
    {
        $day = $date->toDateString();

        return $day >= $this->starts_on->toDateString()
            && $day <= $this->ends_on->toDateString();
    }

    /**
     * Holidays touching any day of the given inclusive range, so a span of
     * days is answered with one query and the per-day questions are asked
     * of the loaded rows.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOverlapping(Builder $query, CarbonInterface $from, CarbonInterface $until): Builder
    {
        return $query
            ->where('starts_on', '<=', $until->toDateString())
            ->where('ends_on', '>=', $from->toDateString());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInDateOrder(Builder $query): Builder
    {
        return $query->orderBy('starts_on')->orderBy('id');
    }
}
