<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\User;
use App\Services\System\BackupService;
use App\Services\System\BackupSet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The only way to read a backup file (decision D-16): the sets live outside
 * the web root, so one file of a set is streamed through the application to a
 * holder of `roles.manage` — the super admin who may open System → Backups.
 *
 * Nothing in the URL becomes a path: the set is looked up by id among the
 * sets BackupService lists, and the file is one of the two fixed parts
 * (`database`, `files`), so no traversal can reach anything else. The route
 * constraints reject any other shape before this runs.
 */
final class BackupDownloadController extends Controller
{
    public function __invoke(Request $request, string $backup, string $file, BackupService $backups): BinaryFileResponse
    {
        Gate::authorize(Permission::RolesManage->value);

        $user = $request->user();
        $set = $backups->find($backup);

        abort_unless($user instanceof User && $set !== null && BackupSet::isPart($file), 404);

        return $backups->download($set, $file, $user);
    }
}
