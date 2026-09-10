<?php

declare(strict_types=1);

namespace App\Filament\Employee\Pages;

use App\Filament\Employee\Actions\RequestCorrectionAction;
use App\Filament\Employee\Actions\RequestLeaveAction;
use App\Filament\Employee\Concerns\BuildsQuickActionTiles;
use App\Filament\Employee\Concerns\ThrottlesPerAccount;
use App\Filament\Employee\Contracts\ThrottlesRequests;
use App\Filament\Employee\Widgets\MyCorrectionRequestsWidget;
use App\Filament\Employee\Widgets\MyLeaveRequestsWidget;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Widgets\Widget;

/**
 * The employee's second and last screen: what they asked for, and what
 * became of it.
 *
 * This page holds every decision in full, and the bell in the top bar only
 * says that one has arrived. The division is deliberate: a notification row
 * is a copy of a moment and is cleared by whoever reads it, while this table
 * is the record and is never cleared, so a decision an employee dismissed on
 * a crowded morning is still here in the afternoon. There is no email either
 * way - mail is logged rather than sent on this host and the queue runs
 * synchronously, so a decision announced by email would be a decision
 * announced nowhere.
 *
 * Not in the navigation, because the employee panel has none. It is reached
 * from the tile band on the attendance screen, which is one tap from the two
 * buttons the whole product exists for - and it carries that same band
 * itself, so somebody reading a rejection can answer it from where they read
 * it instead of going back for the form.
 */
final class Requests extends Page implements ThrottlesRequests
{
    use BuildsQuickActionTiles;
    use ThrottlesPerAccount;

    protected static ?string $slug = 'requests';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.employee.pages.requests';

    public function getTitle(): string
    {
        return __('requests.page.title');
    }

    public function getSubheading(): string
    {
        return __('requests.page.subheading');
    }

    /**
     * A panel with no navigation has nothing to navigate back through, and
     * a single crumb reading the page's own title is noise.
     *
     * @return array<string>
     */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    /**
     * Wider than the attendance card, because two tables of dates and
     * decisions need the room the two buttons do not.
     */
    public function getMaxContentWidth(): Width
    {
        return Width::TwoExtraLarge;
    }

    public function requestCorrectionAction(): Action
    {
        return RequestCorrectionAction::make($this->employee());
    }

    public function requestLeaveAction(): Action
    {
        return RequestLeaveAction::make($this->employee());
    }

    /**
     * Corrections first: they are about a day that has already happened and
     * are the more urgent of the two to read a decision on.
     *
     * @return array<class-string<Widget>>
     */
    protected function getFooterWidgets(): array
    {
        return [
            MyCorrectionRequestsWidget::class,
            MyLeaveRequestsWidget::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'tiles' => $this->requestsScreenTiles(),
        ];
    }

    /**
     * The signed-in account. Protected, not public: a public method on a
     * Livewire component is an endpoint the browser may call, and this one
     * returns a person.
     */
    protected function employee(): User
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }
}
