<?php

declare(strict_types=1);

namespace App\Data\Attendance;

use App\Enums\EarlyCheckOutReason;

/**
 * What the employee stated about a departure before the end of the working
 * day: which of the offered reasons, and their own words if they added any.
 *
 * A draft asserts nothing about whether the check-out is early - that is
 * the workflow's measurement, made against the server clock at the moment
 * of the check-out. A draft handed to a check-out that turns out to be on
 * time is simply not stored, because a reason for leaving early attached
 * to an on-time departure would be a claim about nothing.
 */
final readonly class EarlyCheckOutDraft
{
    /**
     * The most characters of note the column keeps.
     */
    public const int NOTE_LIMIT = 500;

    public function __construct(
        public EarlyCheckOutReason $reason,
        public ?string $note,
    ) {}

    /**
     * Reads what the browser sent alongside the location reading, which may
     * be anything at all. A payload without a recognisable reason is no
     * draft - the workflow then refuses the early check-out exactly as if
     * nothing had been sent, which is what a hand-crafted payload deserves.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public static function fromPayload(?array $payload): ?self
    {
        if ($payload === null) {
            return null;
        }

        $reason = is_string($payload['reason'] ?? null)
            ? EarlyCheckOutReason::tryFrom($payload['reason'])
            : null;

        if (! $reason instanceof EarlyCheckOutReason) {
            return null;
        }

        return new self($reason, self::note($payload['note'] ?? null));
    }

    /**
     * A note nobody typed is absent, not an empty string, and one longer
     * than the column is cut to it: the form enforces the limit already, so
     * only a payload that bypassed the form can exceed it, and losing the
     * tail of that payload is better than refusing the departure it
     * documents.
     */
    private static function note(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, self::NOTE_LIMIT);
    }
}
