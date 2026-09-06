<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\User;
use App\Services\Attachments\AttachmentStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only way to read an attachment (module row 13): the file lives on a
 * private disk, so it is streamed through the application after the policy
 * has checked `attachment.download` and the visibility of the subject.
 *
 * Bound by uuid; a soft-deleted row is out of the binding query and 404s.
 * The route sits behind the `web` + `auth` middleware, sharing the panel's
 * session and guard, so a guest is redirected to the panel login.
 */
final class AttachmentDownloadController extends Controller
{
    public function __invoke(Request $request, Attachment $attachment, AttachmentStorage $storage): StreamedResponse
    {
        Gate::authorize('download', $attachment);

        abort_unless($storage->exists($attachment), 404);

        $user = $request->user();

        return $storage->stream($attachment, $user instanceof User ? $user : null);
    }
}
