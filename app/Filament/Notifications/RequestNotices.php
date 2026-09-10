<?php

declare(strict_types=1);

namespace App\Filament\Notifications;

use App\Data\Requests\RequestNotice;
use App\Support\Filament\RequestNoticeLink;
use Filament\Actions\Action;
use Filament\Livewire\DatabaseNotifications;
use Filament\Notifications\Notification;
use Illuminate\Notifications\DatabaseNotification as StoredNotification;

/**
 * The bell, in both panels: Filament's own component with one thing changed.
 *
 * Filament stores a rendered notification - a title, a body and an action's
 * label, as strings - and draws them back exactly as they were written. This
 * product cannot do that, because a person's language here is a cookie they
 * may flip between two screens and no column records it. So a row holds the
 * facts (see RequestNotice) and this is where they become a sentence, in
 * whichever language the reader is in at the moment they open the bell.
 *
 * Subclassed rather than replaced: everything else about the panel - the
 * unread count, mark-as-read, clear, the slide-over, the polling attribute -
 * is Filament's and is untouched. Only the one method that turns a row into
 * something drawable is ours, and a row it does not recognise is handed
 * straight back to the parent.
 *
 * The same component serves both panels on purpose. The rows are per person,
 * not per panel, so an administrator - who is also an employee here - sees
 * one bell with the same contents wherever they are standing, and each line
 * carries the address of the panel it belongs to. The alternative, filtering
 * each bell to the panel it is drawn in, was rejected: it would give one
 * person two different unread counts, and hide their own leave decision from
 * the panel they spend the day in.
 */
final class RequestNotices extends DatabaseNotifications
{
    public function getNotification(StoredNotification $notification): Notification
    {
        $notice = RequestNotice::fromArray((array) $notification->data);

        if (! $notice instanceof RequestNotice) {
            return parent::getNotification($notification);
        }

        return Notification::make($notification->getKey())
            // A stored notification has no duration, and a Filament
            // Notification's default is six seconds. Left off, every line in
            // the bell would time out a few seconds after the reader opened
            // it and dispatch notificationClosed - which DELETES the row.
            // Somebody who opened the bell and looked away would come back to
            // an empty one, permanently. Filament's own database payload
            // carries 'persistent' for this reason; ours has to say it here.
            ->persistent()
            ->title($notice->title())
            ->body($notice->body())
            ->icon($notice->icon())
            // Filament calls this a status and it is what colours both the
            // line and its glyph; its four words are colour names. Passing
            // the request's own badge colour means a pending request is the
            // same amber in the bell as it is in the queue, and an answered
            // one carries the green or the red it already wears on طلباتي.
            ->status($notice->color())
            ->date($this->formatNotificationDate($notification->getAttributeValue('created_at')))
            ->actions([
                Action::make('open')
                    ->label($notice->openLabel())
                    ->url(RequestNoticeLink::for($notice))
                    // Opening it is reading it. Leaving the row unread after
                    // the reader has gone to look at the thing would leave a
                    // count on the bell that no longer means anything.
                    ->markAsRead(),
            ]);
    }
}
