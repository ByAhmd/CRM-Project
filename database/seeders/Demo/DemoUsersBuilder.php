<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\CrmRole;
use App\Enums\NotificationEvent;
use App\Enums\UserStatus;
use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Access\RoleService;
use App\Services\Notifications\NotificationPreferenceService;

/**
 * The people and the catalogue of a demo run: one active user per seeded
 * role (plus a second manager and rep), two teams each led by its manager,
 * a few notification preferences and the demo products.
 *
 * Roles are granted through RoleService (audited as `user.roles_changed`),
 * the first user — the demo super admin — in the trusted system context as
 * `app:onboard` does, every later grant by that super admin.
 */
final class DemoUsersBuilder
{
    public function __construct(
        private readonly RoleService $roles,
        private readonly NotificationPreferenceService $preferences,
        private readonly RecordVisibilityResolver $visibility,
    ) {}

    public function build(DemoContext $context, string $password): void
    {
        $start = $context->ago(95);
        $definitions = DemoDataset::users();

        $superAdmin = $context->at($start, fn (): User => $this->createUser($context, $definitions[0], $password, null));
        $context->as($superAdmin, $start, fn () => $this->roles->syncUserRoles($superAdmin, [CrmRole::SuperAdmin->value]));

        foreach (DemoDataset::teams() as $key => $team) {
            $context->teams[$key] = $context->as($superAdmin, $start->copy()->addMinutes(5), function () use ($team): Team {
                return Team::query()->create([...$team, 'is_active' => true]);
            });
            $context->record('teams', (int) $context->teams[$key]->getKey());
        }

        foreach (array_slice($definitions, 1) as $index => $definition) {
            $moment = $start->copy()->addMinutes(10 + $index);
            $team = $definition['team'] === null ? null : $context->teams[$definition['team']];

            $user = $context->as($superAdmin, $moment, fn (): User => $this->createUser($context, $definition, $password, $team));
            $context->as($superAdmin, $moment, fn () => $this->roles->syncUserRoles($user, [$definition['role']->value], $superAdmin));

            if ($team !== null && $definition['manages']) {
                $context->as($superAdmin, $moment, fn (): bool => $team->forceFill(['manager_user_id' => $user->getKey()])->save());
            }
        }

        $this->visibility->forgetTeamMembers();

        $this->preferences($context);
        $this->products($context, $superAdmin);
    }

    /**
     * @param  array{key: string, role: CrmRole, name: string, locale: string, team: ?string, manages: bool}  $definition
     */
    private function createUser(DemoContext $context, array $definition, string $password, ?Team $team): User
    {
        $user = User::query()->create([
            'name' => $definition['name'],
            'email' => $definition['key'].'@'.DemoDataset::USER_DOMAIN,
            'password' => $password,
            'status' => UserStatus::Active,
            'locale' => $definition['locale'],
            'timezone' => (string) config('crm.timezone'),
            'team_id' => $team?->getKey(),
        ]);

        $context->users[$definition['key']] = $user;
        $context->record('users', (int) $user->getKey());

        return $user;
    }

    /**
     * A few channel choices that differ from the defaults (bell on, mail
     * off), so the preferences page shows stored rows. Mail stays off for
     * every demo user: their addresses are not deliverable.
     */
    private function preferences(DemoContext $context): void
    {
        $choices = [
            'sales_manager' => [NotificationEvent::DealStageChanged],
            'sales_manager2' => [NotificationEvent::DealStageChanged, NotificationEvent::LeadStale],
            'support' => [NotificationEvent::TaskReminder],
            'read_only' => [NotificationEvent::NoteMention],
        ];

        foreach ($choices as $key => $events) {
            $user = $context->user($key);
            $matrix = [];

            foreach ($events as $event) {
                $matrix[$event->value] = ['database' => false, 'mail' => false];
            }

            $context->as($user, $context->ago(90), fn () => $this->preferences->update($user, $matrix, $user));
        }
    }

    private function products(DemoContext $context, User $superAdmin): void
    {
        foreach (DemoDataset::products() as $index => $product) {
            $model = $context->as($superAdmin, $context->ago(94, -$index), fn (): Product => Product::query()->create([...$product, 'is_active' => true]));

            $context->products[] = $model;
            $context->record('products', (int) $model->getKey());
        }
    }
}
