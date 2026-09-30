<?php

declare(strict_types=1);

use App\Http\Controllers\AttachmentDownloadController;
use App\Http\Controllers\BackupDownloadController;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

/*
 | The CRM has no public site: the root leads straight into the admin panel.
 | Filament redirects guests to its login page and authenticated users to the dashboard.
 */
Route::redirect('/', '/admin/login', 302)->name('root');

/*
 | Attachments live on a private disk and are streamed after the policy check
 | (module row 13). Bound by uuid. The route is guarded by the panel's own
 | middleware rather than Laravel's plain `auth`, so it follows exactly the
 | rule the panel applies: a guest goes to the panel login, a user whose
 | account is no longer active (User::canAccessPanel()) is refused even while
 | a session is alive, and a password change invalidates the session here too.
 */
Route::middleware(['web', Authenticate::class, AuthenticateSession::class])
    ->get('/attachments/{attachment}', AttachmentDownloadController::class)
    ->name('attachments.download');

/*
 | Backup files (decision D-16) live outside the web root and are streamed to
 | super admins (`roles.manage`) only, behind the same panel middleware. The
 | set id and the part are constrained to their fixed shapes and resolved
 | against the listed sets, never used as a path.
 */
Route::middleware(['web', Authenticate::class, AuthenticateSession::class])
    ->get('/backups/{backup}/{file}', BackupDownloadController::class)
    ->where(['backup' => '[0-9]{8}-[0-9]{6}', 'file' => 'database|files'])
    ->name('backups.download');
