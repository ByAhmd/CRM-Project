<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\WorkspacePanel;
use App\Exceptions\Projects\InactiveProjectException;
use App\Models\Project;
use App\Models\User;
use App\Services\Projects\CurrentProject;
use App\Support\Filament\PanelUrl;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

/**
 * The project switcher in the Sales top bar (decision D-30), beside the
 * workspace switcher (D-23): the project the user is working in — name and
 * colour — and the active projects to choose from, the current one marked.
 * Mounted on every Sales page by ConfiguresCrmPanel's render hook.
 *
 * Choosing a project makes it current through CurrentProject and reloads
 * the page the user is on, so every list, board and widget re-renders in
 * the new project at once and the switch is confirmed by a toast. The id
 * comes from the browser and is trusted for nothing: an unknown or inactive
 * project — one deactivated since the page loaded — changes nothing and
 * says nothing, since the switcher never offered it.
 *
 * The page to return to is sent by the browser with the click
 * (`window.location.href`), never remembered here: the panels run in SPA
 * mode and Filament's top bar is a persisted Livewire island, so this
 * component outlives the page it was first rendered on and anything captured
 * at mount would name the page the user has since navigated away from. The
 * address is trusted for nothing either — it is re-validated as an address
 * of this site (PanelUrl::sameSiteAddress) and only the validated parts are
 * redirected to, with the Sales home as the fallback, so the switcher can
 * never be steered off-site.
 */
final class ProjectSwitcher extends Component
{
    public function switchTo(mixed $project = null, mixed $returnUrl = null): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        $id = is_int($project) || (is_string($project) && ctype_digit($project)) ? (int) $project : 0;
        $target = $id > 0 ? Project::query()->whereKey($id)->first() : null;

        if (! $target instanceof Project || ! $target->is_active) {
            return;
        }

        try {
            app(CurrentProject::class)->switch($user, $target);
        } catch (InactiveProjectException) {
            return;
        }

        Notification::make()
            ->title(__('projects.notifications.switched.title'))
            ->body(__('projects.notifications.switched.body', ['project' => $target->display_name]))
            ->success()
            ->send();

        $return = is_string($returnUrl) ? PanelUrl::sameSiteAddress($returnUrl, request()) : null;

        $this->redirect($return ?? PanelUrl::panelHome(WorkspacePanel::Sales));
    }

    public function render(): View
    {
        $user = auth()->user();
        $signedIn = $user instanceof User;

        return view('livewire.project-switcher', [
            'current' => $signedIn ? app(CurrentProject::class)->for($user) : null,
            'projects' => $signedIn ? Project::query()->active()->get() : new Collection,
        ]);
    }
}
