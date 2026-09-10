<?php

declare(strict_types=1);

namespace App\Data\Requests;

use App\Enums\RequestKind;
use App\Enums\RequestStatus;
use App\Models\AttendanceCorrection;
use App\Models\LeaveRequest;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

/**
 * One line in somebody's bell: what happened to which request, held as the
 * facts rather than as a finished sentence.
 *
 * This is the whole of what a `notifications` row carries, and the reason it
 * carries facts is that a stored notification outlives the language it was
 * written in. Language here is a cookie, not a column: nothing records which
 * language a person reads in, and they may switch between two screens. A row
 * holding "تم اعتماد طلب التصحيح" would go on saying that to somebody who
 * moved to English three months later, and there would be nothing left to
 * translate it from. So the row holds a kind, a status, a name, a day and a
 * note, and the sentence is composed when somebody opens the bell, in
 * whichever language they are reading at that moment.
 *
 * The employee's name and the administrator's note are copied in rather than
 * looked up. That is not a denormalisation to save a query - though it does,
 * fifty times over on one bell - it is what a notification is: a record of
 * what was true when it was sent. A person renamed afterwards did not send a
 * different request.
 *
 * Dates are printed as Y-m-d, which is what every other screen in this
 * product prints and is the same string in both languages, so the only
 * translated part of a period is the word between its two ends.
 *
 * The status is the discriminator and not a flag beside one. A notice about a
 * Pending request is the one an administrator gets when it arrives; a notice
 * about an Approved or Rejected one is what its author gets when it is
 * answered. There is no third case, because nothing else happens to a
 * request.
 */
final readonly class RequestNotice
{
    /**
     * The two invisible characters that stop a person's own words from
     * rearranging the sentence they were put into.
     *
     * A notification body is one line holding a name or a note beside a
     * date, and the two halves may be written in different scripts: an
     * Arabic name on an English screen, an administrator's Arabic note read
     * by somebody who has switched to English. The bidirectional algorithm
     * resolves the neutral characters between them - the spaces and the em
     * dash - by looking at what is on each side, and it treats a run of
     * digits as right-to-left for that purpose. "سارة الحربي — 2026-09-09"
     * in an English paragraph therefore becomes one right-to-left run and is
     * drawn as "2026-09-09 — سارة الحربي": the date first, which is not the
     * sentence anybody wrote.
     *
     * FIRST STRONG ISOLATE and POP DIRECTIONAL ISOLATE close the interpolated
     * value off, so it lays itself out in its own direction and the sentence
     * around it lays itself out in the reader's. They are format characters:
     * no font draws them, and nothing but the layout can tell they are there.
     */
    private const string ISOLATE_START = "\u{2068}";

    private const string ISOLATE_END = "\u{2069}";

    public function __construct(
        public RequestKind $kind,
        public int $requestId,
        public string $employeeName,
        public CarbonImmutable $from,
        public ?CarbonImmutable $until,
        public RequestStatus $status,
        public ?string $note,
    ) {}

    /**
     * The notice a request makes about itself, as it stands right now.
     */
    public static function about(AttendanceCorrection|LeaveRequest $request): self
    {
        if ($request instanceof AttendanceCorrection) {
            return new self(
                kind: RequestKind::Correction,
                requestId: (int) $request->getKey(),
                employeeName: $request->user->name,
                from: $request->attendance_date,
                until: null,
                status: $request->status,
                note: $request->decision_note,
            );
        }

        return new self(
            kind: RequestKind::Leave,
            requestId: (int) $request->getKey(),
            employeeName: $request->user->name,
            from: $request->starts_on,
            until: $request->ends_on,
            status: $request->status,
            note: $request->decision_note,
        );
    }

    /**
     * A stored row read back, or null when the row is not one of ours.
     *
     * Null rather than an exception, and every field checked rather than
     * assumed: this reads a JSON blob written by an older deployment of this
     * application, and a bell that fataled on one unrecognised row would be
     * a bell nobody could open. The caller falls back to Filament's own
     * rendering, which is what such a row was written for.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $kind = RequestKind::tryFrom(is_string($data['kind'] ?? null) ? $data['kind'] : '');
        $status = RequestStatus::tryFrom(is_string($data['status'] ?? null) ? $data['status'] : '');
        $from = self::readDate($data['from'] ?? null);

        if (! $kind instanceof RequestKind || ! $status instanceof RequestStatus || ! $from instanceof CarbonImmutable) {
            return null;
        }

        return new self(
            kind: $kind,
            requestId: is_numeric($data['request_id'] ?? null) ? (int) $data['request_id'] : 0,
            employeeName: is_string($data['employee_name'] ?? null) ? $data['employee_name'] : '',
            from: $from,
            until: self::readDate($data['until'] ?? null),
            status: $status,
            note: is_string($data['note'] ?? null) ? $data['note'] : null,
        );
    }

    /**
     * What is written into `notifications.data`.
     *
     * `format` is Filament's, not ours: its bell selects on `data->format`
     * and would not show a row without it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'format' => 'filament',
            'kind' => $this->kind->value,
            'request_id' => $this->requestId,
            'employee_name' => $this->employeeName,
            'from' => $this->from->toDateString(),
            'until' => $this->until?->toDateString(),
            'status' => $this->status->value,
            'note' => $this->note,
        ];
    }

    /**
     * Whether this notice is an answer. A pending request has not been
     * answered, which is exactly why an administrator is being told about it.
     */
    public function isDecision(): bool
    {
        return ! $this->status->isPending();
    }

    public function title(): string
    {
        return $this->isDecision()
            ? __('notifications.decision.'.$this->kind->value.'_'.$this->status->value)
            : __('notifications.new_request.'.$this->kind->value);
    }

    /**
     * The second line: for an administrator, who asked and about when; for
     * an employee, when, and the note - which on a rejection is the entire
     * answer they are receiving.
     */
    public function body(): string
    {
        if (! $this->isDecision()) {
            return __('notifications.new_request.body', [
                'name' => self::isolated($this->employeeName),
                'period' => $this->period(),
            ]);
        }

        $note = $this->note === null ? '' : trim($this->note);

        return $note === ''
            ? __('notifications.decision.body', ['period' => $this->period()])
            : __('notifications.decision.body_with_note', [
                'period' => $this->period(),
                'note' => self::isolated($note),
            ]);
    }

    /**
     * The day, or the two days a leave request runs between. A single-day
     * leave request reads as one date rather than as "from the 5th to the
     * 5th".
     */
    public function period(): string
    {
        if (! $this->until instanceof CarbonImmutable || $this->until->isSameDay($this->from)) {
            return $this->from->toDateString();
        }

        return __('notifications.period.range', [
            'from' => $this->from->toDateString(),
            'until' => $this->until->toDateString(),
        ]);
    }

    /**
     * An arriving request is drawn as what it is - a correction or a leave
     * request; an answered one as what became of it. The reader of the first
     * is deciding which queue to open, and the reader of the second already
     * knows what they asked for.
     */
    public function icon(): string
    {
        return $this->isDecision() ? $this->status->icon() : $this->kind->icon();
    }

    public function color(): string
    {
        return $this->status->color();
    }

    public function openLabel(): string
    {
        return $this->isDecision()
            ? __('notifications.decision.open')
            : __('notifications.new_request.open');
    }

    /**
     * A value somebody typed, closed off from the sentence around it.
     *
     * Only the two values whose script this system does not choose - the
     * employee's name and the administrator's note. The dates are digits and
     * the rest of the line is a translated string in the reader's own
     * language, so neither can pull the other around.
     */
    private static function isolated(string $value): string
    {
        return self::ISOLATE_START.$value.self::ISOLATE_END;
    }

    /**
     * The shape is checked before Carbon is asked, and Carbon is still asked
     * inside a try: '2026-13-45' has the right shape and is not a day.
     */
    private static function readDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
