<?php

declare(strict_types=1);

namespace App\Support\Filament;

use App\Enums\Permission;
use App\Enums\WorkspacePanel;
use App\Models\Attachment;
use App\Models\User;
use App\Services\Access\PanelAccess;
use App\Support\Http\LegacyAdminRedirects;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Resources\Resource as FilamentResource;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Whether the link a user was following when they were asked to sign in
 * (an e-mail, a notification, a bookmark) leads somewhere they may open —
 * decision D-23: "after sign-in a user lands on the workspace screen, unless
 * they were following a link to a page they may open — then they land
 * there". Used by the login response; anything this class does not
 * recognise or cannot confirm answers null, and the user lands on the
 * workspace screen instead of a 403 or a 404.
 *
 * A followed link is honoured when it is plainly this site's (PanelUrl —
 * no other host, no userinfo, no backslash tricks) and the route it reaches
 * would let the user in:
 *
 * - a panel page: the panel admits the user (PanelAccess) and the page
 *   itself does — its canAccess(), and for a resource page the resource's
 *   access, the record found inside the user's visible scope and the view
 *   or edit right on it, exactly the checks the page runs when it mounts;
 * - an attachment download: the attachment policy's `download`;
 * - a backup download: `roles.manage`, as the controller demands;
 * - the bare /admin (the dashboard until D-23) for a user without
 *   Administration: where AuthenticateWorkspace would send them
 *   (LegacyAdminRedirects::formerDashboard()).
 *
 * The address returned is rebuilt from the validated path and query, never
 * the remembered string itself.
 */
final class IntendedDestination
{
    public function __construct(
        private readonly PanelAccess $access,
        private readonly Router $router,
    ) {}

    public function for(User $user, string $intended, Request $request): ?string
    {
        $address = PanelUrl::sameSiteAddress($intended, $request);

        if ($address === null) {
            return null;
        }

        $route = $this->route($address);

        if ($route === null) {
            return null;
        }

        return match ($route->getName()) {
            'attachments.download' => $this->mayDownloadAttachment($user, $route) ? $address : null,
            'backups.download' => Gate::forUser($user)->allows(Permission::RolesManage->value) ? $address : null,
            default => $this->panelAddress($user, $address, $route, $request),
        };
    }

    private function route(string $address): ?Route
    {
        try {
            return $this->router->getRoutes()->match(Request::create($address));
        } catch (HttpExceptionInterface) {
            return null;
        }
    }

    private function mayDownloadAttachment(User $user, Route $route): bool
    {
        $key = $route->parameter('attachment');

        if (! is_string($key)) {
            return false;
        }

        $attachment = (new Attachment)->resolveRouteBinding($key);

        return $attachment instanceof Attachment && Gate::forUser($user)->allows('download', $attachment);
    }

    private function panelAddress(User $user, string $address, Route $route, Request $request): ?string
    {
        $panelId = PanelUrl::panelIdForUrl($address, $request);

        if ($panelId === null) {
            return null;
        }

        if (! $this->access->canAccess($user, $panelId)) {
            return $this->isTheFormerDashboard($panelId, $route)
                ? LegacyAdminRedirects::formerDashboard($user, parse_url($address, PHP_URL_QUERY) ?: null)
                : null;
        }

        $previous = Filament::getCurrentPanel();
        Filament::setCurrentPanel($panelId);

        try {
            return $this->pageAdmits($route) ? $address : null;
        } finally {
            Filament::setCurrentPanel($previous);
        }
    }

    private function isTheFormerDashboard(string $panelId, Route $route): bool
    {
        return $panelId === WorkspacePanel::Admin->id()
            && trim($route->uri(), '/') === trim(Filament::getPanel($panelId)->getPath(), '/');
    }

    /**
     * The checks the page itself runs when it mounts. A panel route that is
     * not a page (the panel root's redirect to its home) is left to the
     * panel's own gate, already passed.
     */
    private function pageAdmits(Route $route): bool
    {
        $page = $route->getControllerClass();

        if ($page === null || ! is_subclass_of($page, Page::class)) {
            return true;
        }

        if (! is_subclass_of($page, ResourcePage::class)) {
            return $page::canAccess();
        }

        /** @var class-string<FilamentResource> $resource */
        $resource = $page::getResource();

        if (! $resource::canAccess()) {
            return false;
        }

        $key = $route->parameter('record');

        if ($key === null) {
            return is_subclass_of($page, CreateRecord::class) ? $resource::canCreate() : $page::canAccess();
        }

        if (! is_string($key)) {
            return false;
        }

        // Resolved through the resource's own query, so a record outside the
        // user's visible scope is not found — as on the page itself.
        $record = $resource::resolveRecordRouteBinding($key);

        if ($record === null || ! $page::canAccess(['record' => $record])) {
            return false;
        }

        return is_subclass_of($page, EditRecord::class)
            ? $resource::canEdit($record)
            : $resource::canView($record);
    }
}
