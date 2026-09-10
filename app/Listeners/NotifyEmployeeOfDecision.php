<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Data\Requests\RequestNotice;
use App\Events\RequestDecided;
use App\Notifications\RequestDecisionNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Puts an answer in the bell of the one person it is about.
 *
 * The recipient is the request's author and never anybody else. Nobody
 * decides their own request, so the sender and the reader are always two
 * different people and there is no case to exclude.
 *
 * The account is reached through the request's own relation, which is
 * declared withTrashed(): a request answered in the moments before its
 * author's account was deleted would otherwise resolve to nothing and be
 * lost. Writing the row anyway is harmless - a deleted account has no bell
 * to open - and restoring the account brings the answer back with it, which
 * is what restoring an account is meant to mean.
 *
 * Swallowed and logged for the same reason as its sibling, and here the
 * reason is sharper: an approval has already amended an attendance row
 * inside a committed transaction by the time this runs. A notification that
 * threw and was allowed to escape would put a red error on the
 * administrator's screen over a decision that was carried out perfectly,
 * and they would press Approve again on a request that is no longer
 * pending.
 */
final class NotifyEmployeeOfDecision
{
    public function handle(RequestDecided $event): void
    {
        try {
            $event->request->user->notify(
                new RequestDecisionNotification(RequestNotice::about($event->request)),
            );
        } catch (Throwable $exception) {
            Log::warning('An employee could not be notified of a decision on their request.', [
                'request' => $event->request::class,
                'request_id' => $event->request->getKey(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
