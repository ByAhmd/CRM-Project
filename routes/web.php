<?php

declare(strict_types=1);

use App\Enums\WorkspacePanel;
use App\Http\Controllers\AttachmentDownloadController;
use App\Http\Controllers\BackupDownloadController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\LandingLanguageController;
use App\Http\Controllers\LegacyAdminRedirectController;
use App\Support\Http\LegacyAdminRedirects;
use BezhanSalleh\LanguageSwitch\Http\Middleware\SwitchLanguageLocale;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

/*
 | The public landing page at the site root (decision D-24). It is not a
 | panel page, so it is set up explicitly: `panel:app` (Filament's SetUpPanel,
 | Livewire-persistent) gives it the home panel's theme, font, dark-mode boot
 | and sign-in address, and the language switch's middleware applies the
 | reader's language — panel routes get it from the plugin, this one would
 | otherwise always render in the default locale.
 |
 | The language buttons on the page post to `landing.language`, which stores
 | the choice exactly like the profile menu's switch does (the plugin's
 | session value and cookie, and the account of a signed-in reader), so the
 | page works without JavaScript.
 */
Route::get('/', LandingController::class)
    ->middleware(['panel:'.WorkspacePanel::HOME, SwitchLanguageLocale::class])
    ->name('landing');

Route::post('/language', LandingLanguageController::class)
    ->name('landing.language');

/*
 | Addresses that moved out of /admin when the CRM split into workspaces
 | (D-23) answer a permanent redirect to their new home, the rest of the path
 | and the query string kept: stored notifications, sent e-mails and
 | bookmarks still carry them. Only the moved paths are listed, so nothing
 | the Administration workspace still serves is shadowed.
 */
foreach (LegacyAdminRedirects::map() as $from => $to) {
    Route::get('admin/'.$from.'/{rest?}', LegacyAdminRedirectController::class)
        ->where('rest', '.*')
        ->defaults('target', $to)
        ->name('legacy.admin.'.str_replace('/', '.', $from));
}

/*
 | Attachments live on a private disk and are streamed after the policy check
 | (module row 13). Bound by uuid. The route is guarded by the panel's own
 | middleware rather than Laravel's plain `auth`, run in the home panel
 | (`panel:app`, D-23) so it follows exactly the rule every signed-in user
 | meets there: a guest goes to the sign-in page, a user whose account is no
 | longer active (User::canAccessPanel()) is refused even while a session is
 | alive, and a password change invalidates the session here too. The file's
 | own record decides the rest through AttachmentPolicy.
 */
Route::middleware(['web', 'panel:'.WorkspacePanel::HOME, Authenticate::class, AuthenticateSession::class])
    ->get('/attachments/{attachment}', AttachmentDownloadController::class)
    ->name('attachments.download');

/*
 | Backup files (decision D-16) live outside the web root and are streamed to
 | super admins (`roles.manage`) only, behind the same panel middleware. The
 | set id and the part are constrained to their fixed shapes and resolved
 | against the listed sets, never used as a path.
 */
Route::middleware(['web', 'panel:'.WorkspacePanel::HOME, Authenticate::class, AuthenticateSession::class])
    ->get('/backups/{backup}/{file}', BackupDownloadController::class)
    ->where(['backup' => '[0-9]{8}-[0-9]{6}', 'file' => 'database|files'])
    ->name('backups.download');
