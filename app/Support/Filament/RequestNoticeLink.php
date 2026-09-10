<?php

declare(strict_types=1);

namespace App\Support\Filament;

use App\Data\Requests\RequestNotice;
use App\Enums\RequestKind;
use App\Filament\Employee\Pages\Requests;
use App\Filament\Resources\AttendanceCorrections\AttendanceCorrectionResource;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;

/**
 * Where a line in the bell opens.
 *
 * Built when the bell is read and never stored beside the notice. A URL
 * written into a row in September is a promise about routing made months in
 * advance: move a resource, rename a slug, change the domain, and every
 * stored notification becomes a link to a 404. The notice holds an id, and
 * an id is still an id.
 *
 * The panel is named on every call rather than left to the current one. Both
 * bells read the same rows - an administrator is an employee too and has one
 * of each kind - so the employee panel has to be able to produce an admin
 * URL and the admin panel an employee one. Without the argument, Filament
 * would resolve the panel the reader happens to be standing in and the
 * resource would not be registered there.
 *
 * A request the administrator has not answered opens in its queue, mounted
 * straight onto the record's own modal, which is the same pair of parameters
 * the global search uses to open an attendance row. An answered one opens
 * the employee's طلباتي page, because there is nothing for them to press:
 * the decision and its note are already printed there in full, and that page
 * is where the next request is filed from.
 */
final class RequestNoticeLink
{
    public static function for(RequestNotice $notice): string
    {
        if ($notice->isDecision()) {
            return Requests::getUrl(panel: PanelAccess::EMPLOYEE_PANEL_ID);
        }

        $parameters = [
            'tableAction' => 'view',
            'tableActionRecord' => $notice->requestId,
        ];

        return match ($notice->kind) {
            RequestKind::Correction => AttendanceCorrectionResource::getUrl(
                parameters: $parameters,
                panel: PanelAccess::ADMIN_PANEL_ID,
            ),
            RequestKind::Leave => LeaveRequestResource::getUrl(
                parameters: $parameters,
                panel: PanelAccess::ADMIN_PANEL_ID,
            ),
        };
    }
}
