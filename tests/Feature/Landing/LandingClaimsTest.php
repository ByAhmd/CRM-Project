<?php

declare(strict_types=1);

namespace Tests\Feature\Landing;

use App\Enums\CrmRole;
use App\Enums\NotificationEvent;
use App\Enums\Permission;
use App\Enums\WorkspacePanel;
use App\Models\User;
use App\Services\Tasks\WeeklySummary;
use App\Support\Access\RolePermissionMatrix;
use App\Support\Notifications\NotificationChannels;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * «Truthful content only» (decision D-24): every capability the landing page
 * claims, and every answer of its FAQ, is pinned here to the code that ships
 * it. A new claim without evidence fails (the claim keys must equal the
 * evidence keys), and evidence that disappears — a class removed, a command
 * renamed, a schedule dropped — fails the claim it backed.
 *
 * Evidence forms:
 *   class:X              a class or enum exists
 *   method:X::m          a public method exists
 *   case:X::C            an enum case exists
 *   permission:key       a permission key exists
 *   command:name         an Artisan command is registered
 *   schedule:name        the command is on the scheduler
 *   column:table.column  a column exists
 *   config:key           a configuration value is set
 *   role-lacks:role:key  a seeded role does not hold the permission by default
 *   home:mfa-optional    the home panel offers two-step verification, not required
 *   home:mfa-app-and-email  that verification works with an authenticator app and by e-mail
 *   channels:event:a,b   a fresh user gets the event on these channels by
 *                        default (with a real mail transport configured)
 *   weekly-recipients:key  the weekly summary goes to holders of the key, and
 *                        only to them
 *   home:no-registration the home panel has no self-registration
 *   password:min:N       the password policy demands at least N characters
 *   locale-default:xx    the default interface language
 */
final class LandingClaimsTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const array EVIDENCE = [
        'capabilities.sales.pipeline' => ['class:App\Models\Pipeline', 'column:pipeline_stages.probability', 'column:deals.probability'],
        'capabilities.sales.deal_board' => ['method:App\Filament\Pages\DealBoard::moveDeal'],
        'capabilities.sales.lead_scoring' => ['class:App\Services\Leads\LeadScoringService', 'class:App\Models\LeadScoringRule', 'column:leads.score_override'],
        'capabilities.sales.conversion' => ['method:App\Services\Leads\LeadConversionWorkflow::convert', 'permission:lead.convert'],
        'capabilities.sales.products' => ['class:App\Models\Product', 'class:App\Models\DealProduct', 'class:App\Enums\ForecastCategory'],

        'capabilities.tasks.assignment' => ['permission:task.assign', 'class:App\Notifications\RecordAssignedNotification', 'channels:record_assigned:database,mail'],
        'capabilities.tasks.progress' => ['case:App\Enums\TaskUpdateKind::Progress', 'class:App\Notifications\TaskCompletedNotification'],
        'capabilities.tasks.comments' => ['case:App\Enums\TaskUpdateKind::Comment', 'class:App\Models\TaskUpdateMention', 'class:App\Notifications\TaskMentionNotification'],
        'capabilities.tasks.checklists' => ['class:App\Models\TaskChecklistItem', 'method:App\Services\Tasks\TaskService::tickChecklist', 'method:App\Services\Tasks\TaskChecklist::progressLabel', 'column:task_checklist_items.completed_by', 'column:task_template_checklist_items.body'],
        'capabilities.tasks.routines' => ['class:App\Services\Tasks\RoutineTaskService', 'case:App\Enums\RoutineFrequency::Weekly', 'case:App\Enums\RoutineFrequency::Monthly', 'schedule:tasks:generate-routines'],
        'capabilities.tasks.calendar' => ['class:App\Filament\Pages\TasksBoard', 'class:App\Filament\Pages\Calendar'],

        'capabilities.reports.pipeline' => ['class:App\Filament\Pages\Reports\PipelineReportPage', 'class:App\Filament\Pages\Reports\ForecastReportPage', 'class:App\Filament\Pages\Reports\WinLossReportPage'],
        'capabilities.reports.funnel' => ['class:App\Filament\Pages\Reports\ConversionFunnelReportPage', 'class:App\Filament\Pages\Reports\SourcePerformanceReportPage'],
        'capabilities.reports.performance' => ['class:App\Filament\Pages\Reports\SalesPerformanceReportPage', 'class:App\Filament\Pages\Reports\ActivityReportPage'],
        'capabilities.reports.tasks' => ['class:App\Filament\Pages\Reports\TaskPerformanceReportPage', 'column:tasks.assigned_at'],
        'capabilities.reports.weekly' => ['class:App\Notifications\WeeklySummaryNotification', 'schedule:crm:weekly-summary', 'weekly-recipients:task.assign'],

        'capabilities.security.visibility' => ['case:App\Enums\VisibilityLevel::Own', 'case:App\Enums\VisibilityLevel::Team', 'case:App\Enums\VisibilityLevel::All', 'class:App\Services\Access\RecordVisibilityResolver'],
        'capabilities.security.workspaces' => ['class:App\Services\Access\PanelAccess', 'permission:workspace.sales'],
        'capabilities.security.two_step' => ['home:mfa-optional', 'home:mfa-app-and-email'],
        'capabilities.security.audit' => ['class:App\Filament\Resources\ActivityLogs\ActivityLogResource', 'config:crm.audit.retention_days'],
        'capabilities.security.backups' => ['class:App\Services\System\BackupService', 'schedule:crm:backup'],
        'capabilities.security.invitations' => ['class:App\Notifications\UserInvitationNotification', 'home:no-registration', 'password:min:12'],

        'faq.items.accounts' => ['class:App\Notifications\UserInvitationNotification', 'home:no-registration'],
        'faq.items.language' => ['locale-default:ar', 'class:App\Listeners\PersistUserLocale'],
        'faq.items.visibility' => ['case:App\Enums\VisibilityLevel::Team', 'role-lacks:sales_rep:lead.assign', 'role-lacks:sales_rep:deal.assign'],
        'faq.items.two_step' => ['home:mfa-optional', 'home:mfa-app-and-email', 'password:min:12'],
        'faq.items.backups' => ['schedule:crm:backup', 'class:App\Notifications\BackupFailedNotification', 'class:App\Filament\Resources\ActivityLogs\ActivityLogResource'],
        'faq.items.routines' => ['class:App\Services\Tasks\RoutineTaskService', 'permission:task.assign', 'schedule:tasks:generate-routines'],
    ];

    #[Test]
    public function every_claim_on_the_page_has_evidence_and_every_piece_of_evidence_a_claim(): void
    {
        $claims = [];

        foreach (['ar', 'en'] as $locale) {
            $keys = [];

            foreach ((array) __('landing.capabilities', [], $locale) as $group => $content) {
                if (is_array($content)) {
                    foreach (array_keys((array) $content['points']) as $point) {
                        $keys[] = "capabilities.{$group}.{$point}";
                    }
                }
            }

            foreach (array_keys((array) __('landing.faq.items', [], $locale)) as $item) {
                $keys[] = "faq.items.{$item}";
            }

            $claims[$locale] = $keys;
        }

        $this->assertSame($claims['ar'], $claims['en'], 'the two languages claim different things');
        $this->assertEqualsCanonicalizing(array_keys(self::EVIDENCE), $claims['en'], 'a claim without evidence, or evidence for a claim the page no longer makes');
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function claims(): array
    {
        $cases = [];

        foreach (self::EVIDENCE as $claim => $evidence) {
            $cases[$claim] = [$evidence];
        }

        return $cases;
    }

    /**
     * @param  list<string>  $evidence
     */
    #[Test]
    #[DataProvider('claims')]
    public function the_claim_is_backed_by_shipped_code(array $evidence): void
    {
        foreach ($evidence as $item) {
            $this->assertTrue($this->holds($item), "the evidence [{$item}] no longer holds");
        }
    }

    #[Test]
    public function the_rendered_page_shows_exactly_the_evidenced_capabilities(): void
    {
        preg_match_all('/data-claim="([a-z_]+\.[a-z_]+)"/', (string) $this->get('/')->getContent(), $matches);

        $expected = array_map(
            static fn (string $key): string => substr($key, strlen('capabilities.')),
            array_values(array_filter(array_keys(self::EVIDENCE), static fn (string $key): bool => str_starts_with($key, 'capabilities.'))),
        );

        $this->assertSame($expected, $matches[1]);
    }

    private function holds(string $item): bool
    {
        [$kind, $subject] = explode(':', $item, 2);

        return match ($kind) {
            'class' => class_exists($subject) || enum_exists($subject),
            'method' => method_exists(...explode('::', $subject, 2)),
            'case' => defined($subject),
            'permission' => Permission::tryFrom($subject) !== null,
            'command' => array_key_exists($subject, Artisan::all()),
            'schedule' => $this->isScheduled($subject),
            'column' => Schema::hasColumn(...explode('.', $subject, 2)),
            'config' => config($subject) !== null,
            'role-lacks' => $this->roleLacks($subject),
            'home' => $this->homePanelHolds($subject),
            'channels' => $this->deliveredByDefault($subject),
            'weekly-recipients' => $this->weeklySummaryGoesTo($subject),
            'password' => $this->passwordDemands($subject),
            'locale-default' => config('app.locale') === $subject,
            default => false,
        };
    }

    private function isScheduled(string $command): bool
    {
        $this->assertArrayHasKey($command, Artisan::all(), "sanity: {$command} is not a registered command");

        foreach (app(Schedule::class)->events() as $event) {
            if (str_contains((string) $event->command, $command)) {
                return true;
            }
        }

        return false;
    }

    private function roleLacks(string $subject): bool
    {
        [$role, $permission] = explode(':', $subject, 2);

        return ! in_array(Permission::from($permission), RolePermissionMatrix::for(CrmRole::from($role)), true);
    }

    private function homePanelHolds(string $feature): bool
    {
        $home = Filament::getPanel(WorkspacePanel::HOME);

        return match ($feature) {
            'mfa-optional' => $home->hasMultiFactorAuthentication() && ! $home->isMultiFactorAuthenticationRequired(),
            'mfa-app-and-email' => $this->offersProviders($home->getMultiFactorAuthenticationProviders(), [AppAuthentication::class, EmailAuthentication::class]),
            'no-registration' => ! $home->hasRegistration(),
            default => false,
        };
    }

    /**
     * @param  array<mixed>  $providers
     * @param  list<class-string>  $expected
     */
    private function offersProviders(array $providers, array $expected): bool
    {
        foreach ($expected as $class) {
            if (array_filter($providers, static fn (mixed $provider): bool => $provider instanceof $class) === []) {
                return false;
            }
        }

        return true;
    }

    private function deliveredByDefault(string $subject): bool
    {
        [$event, $channels] = explode(':', $subject, 2);

        config(['mail.default' => 'smtp']);
        $this->assertTrue(NotificationChannels::mailIsConfigured(), 'sanity: a real transport is configured for the check');

        $delivered = NotificationChannels::for(User::factory()->create(), NotificationEvent::from($event));

        return array_diff(explode(',', $channels), $delivered) === [];
    }

    private function weeklySummaryGoesTo(string $permission): bool
    {
        $this->seedAccess();

        $holder = $this->userWithPermissions(null, [Permission::from($permission)]);
        $other = $this->userWithPermissions(null, [Permission::TaskViewAny]);
        $recipients = app(WeeklySummary::class)->recipients()->modelKeys();

        return in_array($holder->getKey(), $recipients, true) && ! in_array($other->getKey(), $recipients, true);
    }

    private function passwordDemands(string $rule): bool
    {
        [$kind, $value] = explode(':', $rule, 2);
        $applied = Password::defaults()->appliedRules();

        return $kind === 'min'
            && $applied['min'] >= (int) $value
            && $applied['mixedCase'] === true
            && $applied['numbers'] === true;
    }
}
