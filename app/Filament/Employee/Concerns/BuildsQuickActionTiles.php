<?php

declare(strict_types=1);

namespace App\Filament\Employee\Concerns;

use App\Filament\Employee\Actions\RequestCorrectionAction;
use App\Filament\Employee\Actions\RequestLeaveAction;
use App\Filament\Employee\Pages\Attendance;
use App\Filament\Employee\Pages\Requests;
use App\Models\User;
use App\Services\Attendance\CorrectionQuota;
use App\Services\Requests\EmployeeRequestCounts;

/**
 * The band of tiles that carries the employee panel's two screens between
 * them.
 *
 * A band and not a navigation bar. Two destinations do not justify fixed
 * chrome, and a hand-written bar would be new CSS plus a render hook that
 * also fires on the sign-in page; a flat row of tiles reaches both screens
 * in one tap and scrolls away with the page, which is right for something
 * nobody uses twice in a sitting. It is also the only way back from
 * /requests: this panel has no navigation at all, and a phone's back gesture
 * is not a thing a screen may rely on.
 *
 * No tile is ever hidden. A control that vanishes when it cannot be used is
 * a control somebody hunts for and then reports as broken; a tile that opens
 * onto the sentence explaining itself is an answer.
 *
 * Every tile carries a second line, including when there is nothing to
 * report - "nothing awaiting a decision" is printed, not omitted. A figure
 * that appears only when it is bad teaches a reader to distrust its absence.
 */
trait BuildsQuickActionTiles
{
    /**
     * The band under the attendance card.
     *
     * The correction tile carries the remaining allowance, because this is
     * the screen somebody is standing on when they decide whether to ask,
     * and "no allowance left this month" is worth knowing before the form
     * opens rather than after it has been filled in.
     *
     * @return list<array{key: string, label: string, caption: string, icon: string, action: ?string, href: ?string}>
     */
    protected function attendanceScreenTiles(User $employee): array
    {
        return [
            self::correctionTile($this->allowanceCaption($employee)),
            self::leaveTile(),
            [
                'key' => 'requests',
                'label' => __('requests.tiles.my_requests'),
                'caption' => $this->pendingCaption($employee),
                'icon' => 'heroicon-o-inbox-stack',
                'action' => null,
                'href' => Requests::getUrl(),
            ],
        ];
    }

    /**
     * The band above the two request tables.
     *
     * The third tile is the way back to the attendance screen, so the band
     * never offers a tap that changes nothing, and the correction tile says
     * what the form is for rather than what is left of the allowance. This
     * page has no other reason to ask what the company's settings are, and
     * the allowance is stated in the modal's first line either way - so the
     * screen an employee opens to read a decision costs exactly what it
     * costs to read one.
     *
     * @return list<array{key: string, label: string, caption: string, icon: string, action: ?string, href: ?string}>
     */
    protected function requestsScreenTiles(): array
    {
        return [
            self::correctionTile(__('requests.tiles.caption_correction')),
            self::leaveTile(),
            [
                'key' => 'attendance',
                'label' => __('requests.tiles.attendance'),
                'caption' => __('requests.tiles.caption_attendance'),
                'icon' => 'heroicon-o-clock',
                'action' => null,
                'href' => Attendance::getUrl(),
            ],
        ];
    }

    /**
     * @return array{key: string, label: string, caption: string, icon: string, action: ?string, href: ?string}
     */
    private static function correctionTile(string $caption): array
    {
        return [
            'key' => 'correction',
            'label' => __('requests.tiles.correction'),
            'caption' => $caption,
            'icon' => 'heroicon-o-pencil-square',
            'action' => RequestCorrectionAction::NAME,
            'href' => null,
        ];
    }

    /**
     * @return array{key: string, label: string, caption: string, icon: string, action: ?string, href: ?string}
     */
    private static function leaveTile(): array
    {
        return [
            'key' => 'leave',
            'label' => __('requests.tiles.leave'),
            'caption' => __('requests.tiles.caption_leave'),
            'icon' => 'heroicon-o-calendar-days',
            'action' => RequestLeaveAction::NAME,
            'href' => null,
        ];
    }

    /**
     * What is left of this month's correction allowance.
     *
     * Zero and "switched off" are two different noes and get two different
     * sentences: one of them renews next month, and the other is a setting
     * only an administrator can change.
     */
    private function allowanceCaption(User $employee): string
    {
        $quota = app(CorrectionQuota::class);

        if ($quota->allowance() === 0) {
            return __('requests.tiles.badge_corrections_off');
        }

        $remaining = $quota->remainingFor($employee);

        return $remaining === 0
            ? __('requests.tiles.badge_no_quota')
            : __('requests.tiles.badge_quota', ['count' => $remaining]);
    }

    /**
     * Both kinds of request in one figure. The employee is being pointed at
     * one screen, not asked to divide their attention between two numbers.
     */
    private function pendingCaption(User $employee): string
    {
        $pending = app(EmployeeRequestCounts::class)->pendingTotal($employee);

        return $pending === 0
            ? __('requests.tiles.badge_no_pending')
            : __('requests.tiles.badge_pending', ['count' => $pending]);
    }
}
