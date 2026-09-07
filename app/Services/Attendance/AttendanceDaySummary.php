<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * One employee's day, read as the sessions it is made of.
 *
 * Since an employee may leave and come back, "today" is no longer a single
 * record with two times in it: it is a list of sessions, of which at most
 * one is open. Every question the screens ask about a day - is this person
 * inside right now, how many times did they come and go, how long were they
 * here - is answered here rather than by a query written twice in a page
 * and a widget.
 *
 * The sessions are loaded once and every figure is computed from that one
 * collection, so a screen showing the list and the total cannot show two
 * different days.
 */
final readonly class AttendanceDaySummary
{
    /**
     * @param  Collection<int, Attendance>  $sessions  the day's sessions, earliest check-in first
     */
    private function __construct(
        private Collection $sessions,
    ) {}

    public static function forEmployee(User $user, CarbonInterface $date): self
    {
        return new self(
            Attendance::query()
                ->forUser($user)
                ->forDate($date)
                ->inSessionOrder()
                ->get(),
        );
    }

    /**
     * @return Collection<int, Attendance>
     */
    public function sessions(): Collection
    {
        return $this->sessions;
    }

    public function sessionCount(): int
    {
        return $this->sessions->count();
    }

    /**
     * The session the employee is inside right now, if any. The database
     * allows only one open session per employee per day, so "the" is exact.
     */
    public function openSession(): ?Attendance
    {
        return $this->sessions->first(static fn (Attendance $session): bool => $session->isOpen());
    }

    public function isCheckedIn(): bool
    {
        return $this->openSession() instanceof Attendance;
    }

    /**
     * The session that decides what the screen says the employee is doing:
     * the open one while there is one, otherwise the last one they closed.
     */
    public function latestSession(): ?Attendance
    {
        return $this->sessions->last();
    }

    /**
     * Time inside the company today: the closed sessions only.
     *
     * The open session is still running and has no length yet, so it adds
     * nothing until it is closed. That keeps the total a statement about
     * completed presence rather than a stopwatch that moves whenever the
     * page is refreshed.
     */
    public function secondsInside(): int
    {
        return (int) $this->sessions->sum(
            static fn (Attendance $session): int => $session->durationInSeconds() ?? 0,
        );
    }
}
