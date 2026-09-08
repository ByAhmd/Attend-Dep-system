<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Locale;
use Carbon\CarbonInterface;
use Database\Factories\JobTitleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A job title an account may be given - "التسويق" / "Marketing".
 *
 * Two names and not one, because a title is printed under a person's name
 * on a screen that is read in two languages, and a title stored once would
 * be the only string in this product that ignores who is reading.
 *
 * Retired rather than deleted: a title somebody holds cannot be removed at
 * all (the foreign key restricts), and is_active answers the question that
 * is actually being asked - stop offering this one, leave it on the people
 * who have it.
 *
 * @property int $id
 * @property string $name_ar
 * @property string $name_en
 * @property bool $is_active
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Collection<int, User> $users
 */
#[Fillable(['name_ar', 'name_en', 'is_active'])]
final class JobTitle extends Model
{
    /** @use HasFactory<JobTitleFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * The title in the reader's own language.
     */
    public function displayName(): string
    {
        return Locale::current() === Locale::English ? $this->name_en : $this->name_ar;
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * One ordering for both languages.
     *
     * Sorting by the reader's own column would reshuffle the whole list the
     * moment somebody switched language, and two administrators comparing
     * screens would be looking at different orders of the same titles.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInNameOrder(Builder $query): Builder
    {
        return $query->orderBy('name_ar');
    }

    /**
     * The titles a form may offer, keyed by id.
     *
     * Active ones, plus whichever title the account being edited already
     * holds - the same rule the status select already applies to Pending.
     * Without the exception, retiring a title would silently blank the
     * field of everybody still wearing it the next time their account was
     * saved.
     *
     * @return array<int, string>
     */
    public static function selectableOptions(?int $keep = null): array
    {
        $titles = self::query()
            ->where(function (Builder $query) use ($keep): void {
                $query->where('is_active', true);

                if ($keep !== null) {
                    $query->orWhereKey($keep);
                }
            })
            ->inNameOrder()
            ->get();

        $options = [];

        foreach ($titles as $title) {
            $options[$title->id] = $title->displayName();
        }

        return $options;
    }
}
