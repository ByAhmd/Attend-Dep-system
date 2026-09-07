<?php

declare(strict_types=1);

namespace App\Filament\Resources\PresencePings\Pages;

use App\Filament\Resources\PresencePings\PresencePingResource;
use Filament\Resources\Pages\ListRecords;

final class ListPresencePings extends ListRecords
{
    protected static string $resource = PresencePingResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * The limit of the feature, printed above the table it applies to.
     *
     * A browser reports its position only while the page is open and the
     * device awake, so the rows below are evidence of presence and the gaps
     * between them are evidence of nothing. Anyone reading this table has
     * to know that before they read a gap as an absence, which is why the
     * sentence is on the page rather than in the documentation.
     */
    public function getSubheading(): string
    {
        return __('presence.pages.list.subheading');
    }
}
