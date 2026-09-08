<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Services\Leave\LeaveAttachmentStore;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands over the document attached to one leave request.
 *
 * The address carries the request's id and never a filename, so there is no
 * path in the URL to edit, to guess or to walk out of. The stored path is
 * read from the row the id resolved to, and the row is only reached after
 * the Gate has been asked - the same question the admin resource and the
 * employee's own list ask before they draw a link at all, so a link that is
 * not drawn is also a link that does not work.
 *
 * The order of the two checks is deliberate. Authorisation runs first, so a
 * request that is not the caller's own is refused without telling them
 * whether it has a document; only the owner and an administrator learn
 * that, and they learn it as a 404.
 */
final class LeaveAttachmentController
{
    public function __construct(private readonly LeaveAttachmentStore $attachments) {}

    public function __invoke(LeaveRequest $leaveRequest): StreamedResponse
    {
        Gate::authorize('view', $leaveRequest);

        abort_unless($this->attachments->has($leaveRequest), 404);

        return $this->attachments->download($leaveRequest);
    }
}
