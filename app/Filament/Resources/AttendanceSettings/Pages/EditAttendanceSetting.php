<?php

declare(strict_types=1);

namespace App\Filament\Resources\AttendanceSettings\Pages;

use App\Filament\Resources\AttendanceSettings\AttendanceSettingResource;
use App\Models\AttendanceSetting;
use App\Support\Geo\Coordinates;
use App\Support\Geo\GoogleMapsLink;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

/**
 * The settings screen. Resolves the single row itself rather than taking a
 * record from the route - there is only one, and nobody should reach
 * another by editing a URL.
 */
final class EditAttendanceSetting extends EditRecord
{
    protected static string $resource = AttendanceSettingResource::class;

    public function getTitle(): string
    {
        return __('settings.pages.edit.title');
    }

    public function getBreadcrumb(): string
    {
        return __('settings.pages.edit.title');
    }

    /**
     * The resource's index is this page, so the resource crumb Filament
     * prepends would link to the screen already open and repeat its title.
     */
    public function hasResourceBreadcrumbs(): bool
    {
        return false;
    }

    public function getSubheading(): string
    {
        return __('settings.pages.edit.subheading');
    }

    public function mount(int|string|null $record = null): void
    {
        parent::mount(AttendanceSetting::current()->getKey());
    }

    /**
     * No delete: the row is the configuration, and the model would only
     * recreate it blank. The one header action opens the saved location
     * on a map, which is the quickest way to spot a swapped pair of
     * coordinates.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label(__('settings.helpers.preview'))
                ->icon(Heroicon::OutlinedMap)
                ->color('gray')
                ->url(function (): ?string {
                    $coordinates = $this->settings()->coordinates();

                    return $coordinates instanceof Coordinates ? GoogleMapsLink::to($coordinates) : null;
                })
                ->openUrlInNewTab()
                ->visible(fn (): bool => $this->settings()->isConfigured()),
        ];
    }

    protected function getSavedNotificationTitle(): string
    {
        return __('settings.notifications.saved');
    }

    /**
     * The page contract types the record as a Model; mount() only ever
     * loads the settings row, so narrowing it here is a statement, not a
     * check.
     */
    private function settings(): AttendanceSetting
    {
        $record = $this->getRecord();

        assert($record instanceof AttendanceSetting);

        return $record;
    }
}
