<?php

declare(strict_types=1);

namespace App\Services\Leave;

use App\Models\LeaveRequest;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The one place that knows where a leave request's supporting document is
 * kept, how it leaves the server, and how it is destroyed.
 *
 * The disk is private and sits above the document root, so a stored path is
 * not a URL and cannot become one. Everything here works from the request
 * row: the caller hands over a LeaveRequest and never a path, which is what
 * keeps a path out of the query string and out of anybody's reach.
 *
 * Deletion lives here rather than in a `deleted` model event on
 * LeaveRequest, for two reasons. A model event fires on Model::delete() and
 * on nothing else - not on a bulk delete built from the query builder, not
 * on a truncate, not on a foreign key cascade - so it advertises a
 * guarantee it cannot keep, and the guarantee wanted here is that a file
 * never outlives its row. And no interface in this product deletes a leave
 * request at all: every policy's delete verb is false, so the door has to
 * be a named method somebody calls deliberately rather than a hook waiting
 * for an event that is never dispatched.
 */
final readonly class LeaveAttachmentStore
{
    public const string DISK = 'leave-attachments';

    /**
     * Four megabytes: large enough for a photographed medical note or a
     * scanned examination timetable, small enough that a shared host's disk
     * and a phone on mobile data both survive it.
     */
    public const int MAX_BYTES = 4 * 1024 * 1024;

    /**
     * What an approver can actually open. A document and two image formats;
     * anything else - an archive, an office file, a script - is a file this
     * product has no reason to hold.
     *
     * @var list<string>
     */
    public const array ACCEPTED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];

    /**
     * The name a download falls back to when the original one transliterates
     * to nothing an HTTP header may carry.
     */
    private const string FALLBACK_DOWNLOAD_NAME = 'leave-attachment';

    /**
     * Whether this request has a document that can actually be served: four
     * coherent columns, a path that stays inside the disk, and a file
     * present on it.
     */
    public function has(LeaveRequest $request): bool
    {
        $path = $this->storedPath($request);

        return $path !== null && $this->disk()->exists($path);
    }

    /**
     * The file as a download, under the name its owner gave it and the type
     * it was stored with.
     *
     * Always an attachment, never inline, and always with the sniffing and
     * scripting of the response switched off: these bytes came from outside
     * and are served from the application's own origin, so nothing about
     * them may be allowed to render in the tab that asked for them.
     *
     * The Content-Disposition header is built here rather than left to the
     * framework because the fallback filename it derives is required to be
     * printable ASCII, and an Arabic name that transliterates to nothing
     * would otherwise raise an exception instead of a download.
     *
     * @throws RuntimeException when there is nothing to send; ask has() first
     */
    public function download(LeaveRequest $request): StreamedResponse
    {
        $path = $this->storedPath($request);

        if ($path === null) {
            throw new RuntimeException(
                "Leave request {$request->id} has no readable attachment; has() answers that before download() is called.",
            );
        }

        return $this->disk()->download($path, $request->attachment_name, [
            'Content-Type' => $this->contentType((string) $request->attachment_mime_type),
            'Content-Disposition' => $this->disposition((string) $request->attachment_name),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    /**
     * Removes the document and the four columns that describe it.
     *
     * Call it before deleting a leave request, and on its own when only the
     * file has to go. The columns are cleared first and the file second: if
     * the second step fails the disk keeps a file nothing points at, which
     * is litter and can be swept, while the other order would leave a row
     * pointing at bytes that are gone - a download that fails in front of
     * the person who asked for it.
     */
    public function discard(LeaveRequest $request): void
    {
        $path = $this->storedPath($request);

        $request->forceFill([
            'attachment_path' => null,
            'attachment_name' => null,
            'attachment_size' => null,
            'attachment_mime_type' => null,
        ])->save();

        if ($path !== null) {
            $this->forget($path);
        }
    }

    /**
     * Deletes a stored file that no request will ever claim.
     *
     * The upload control writes the file when the employee chooses it,
     * which is before the form is sent and therefore before the workflow
     * has agreed to accept it. A submission the workflow refuses leaves
     * those bytes behind, and this is how the caller that handled the
     * refusal takes them away again.
     *
     * A path that is not a plain relative path inside the disk is ignored
     * rather than passed to the filesystem: deletion is the one operation
     * where a mistaken path is unrecoverable.
     */
    public function forget(string $path): void
    {
        $safe = $this->within($path);

        if ($safe === null) {
            return;
        }

        $this->disk()->delete($safe);
    }

    /**
     * The path to serve, or null when this request has no usable document.
     *
     * The four columns are checked together because the table stores them
     * that way; a row that somehow held three of them is a row nothing can
     * be served from. The path is then checked for the shape it must have,
     * so a value that ever reached the column by another route cannot climb
     * out of the disk - Flysystem refuses a traversal too, but it refuses
     * it with an exception, and an unreadable attachment is a 404 and not a
     * server error.
     */
    private function storedPath(LeaveRequest $request): ?string
    {
        if ($request->attachment_path === null
            || $request->attachment_name === null
            || $request->attachment_size === null
            || $request->attachment_mime_type === null) {
            return null;
        }

        return $this->within($request->attachment_path);
    }

    /**
     * A relative path that stays inside the disk root, or null.
     *
     * Absolute paths, Windows drive letters, backslashes, parent segments
     * and embedded null bytes are all refused outright rather than
     * normalised away: this application writes plain relative paths, so
     * anything else is not a path to repair but a path to distrust.
     */
    private function within(string $path): ?string
    {
        $path = trim($path);

        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')) {
            return null;
        }

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            return null;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $path;
    }

    /**
     * The type to declare for a stored file.
     *
     * A MIME type is a string that arrived with an upload and is now going
     * back out inside a response header, so only the three types this
     * product accepts are echoed; anything else - a type from a form that
     * once allowed more, or a value that reached the column some other way
     * - is served as opaque bytes. Nothing is lost by that: the response is
     * a download either way, and the file keeps the name and extension its
     * owner gave it.
     */
    private function contentType(string $stored): string
    {
        return in_array($stored, self::ACCEPTED_MIME_TYPES, true)
            ? $stored
            : 'application/octet-stream';
    }

    /**
     * The Content-Disposition header for a file the employee named.
     *
     * The name is reduced to its last segment first: a name carrying a
     * separator was never a filename, and a header is the wrong place to
     * discover that. The fallback is the transliteration of what is left,
     * with everything an HTTP header cannot carry removed, and a constant
     * where that leaves nothing.
     */
    private function disposition(string $name): string
    {
        $name = str_replace(['/', '\\', "\0"], '', basename(str_replace('\\', '/', $name)));

        if ($name === '') {
            $name = self::FALLBACK_DOWNLOAD_NAME;
        }

        $fallback = trim(preg_replace('/[^\x20-\x7e]/', '', str_replace('%', '', Str::ascii($name))) ?? '');

        if ($fallback === '' || trim($fallback, '.') === '') {
            $fallback = self::FALLBACK_DOWNLOAD_NAME;
        }

        return HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, $fallback);
    }

    private function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk(self::DISK);

        return $disk;
    }
}
