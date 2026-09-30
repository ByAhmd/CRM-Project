<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Authorisation;

use App\Enums\CrmRole;
use App\Filament\Pages\Calendar;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\DealBoard;
use App\Filament\Pages\NotificationPreferences;
use App\Filament\Pages\Reports\ActivityReportPage;
use App\Filament\Pages\Reports\ConversionFunnelReportPage;
use App\Filament\Pages\Reports\ForecastReportPage;
use App\Filament\Pages\Reports\LeadReportPage;
use App\Filament\Pages\Reports\PipelineReportPage;
use App\Filament\Pages\Reports\SalesPerformanceReportPage;
use App\Filament\Pages\Reports\SourcePerformanceReportPage;
use App\Filament\Pages\Reports\TaskPerformanceReportPage;
use App\Filament\Pages\Reports\WinLossReportPage;
use App\Filament\Pages\Settings\GeneralSettings;
use App\Filament\Pages\System\Backups;
use App\Filament\Pages\TasksBoard;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\ActivityTypes\ActivityTypeResource;
use App\Filament\Resources\Competitors\CompetitorResource;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\CustomFields\CustomFieldResource;
use App\Filament\Resources\DealCloseReasons\DealCloseReasonResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\EmailTemplates\EmailTemplateResource;
use App\Filament\Resources\Exports\ExportResource;
use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Resources\Industries\IndustryResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\LeadScoringRules\LeadScoringRuleResource;
use App\Filament\Resources\LeadSources\LeadSourceResource;
use App\Filament\Resources\LeadStatuses\LeadStatusResource;
use App\Filament\Resources\Pipelines\PipelineResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Tags\TagResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\Teams\TeamResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Account;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Authorisation audit: the page matrix for the seeded roles, over records
 * the viewer owns, records of their team and records outside their reach,
 * derived from docs/PERMISSIONS.md and RolePermissionMatrix. Every mismatch is
 * collected so one run reports the whole matrix.
 *
 * D-15 (2026-09-28): the seventh role, `employee`, joins the walk. It reaches
 * the dashboard, its tasks (list, view, create, edit of its own), the tasks
 * board, the calendar and its notification preferences, and is refused every
 * sales, report, settings, administration, import and export page.
 */
final class RoleMatrixWalkProbeTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    /** The six roles that read the sales records (D-15 keeps the employee out of them). */
    private const ALL = ['super_admin', 'admin', 'sales_manager', 'sales_rep', 'support', 'read_only'];

    /** D-15 (2026-09-28): the pages every seeded role reaches, the employee included. */
    private const EVERYONE = [...self::ALL, 'employee'];

    private const COMMERCIAL_WRITERS = ['super_admin', 'admin', 'sales_manager', 'sales_rep'];

    private const SETTINGS = ['super_admin', 'admin'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
    }

    #[Test]
    public function every_panel_page_answers_each_seeded_role_as_the_permission_matrix_says(): void
    {
        $team = $this->makeTeam();
        $mismatches = [];

        foreach (CrmRole::cases() as $role) {
            $viewer = $this->makeUser($role, ['name' => 'Viewer '.$role->value], $team);

            $pages = [
                [LeadResource::getUrl('index'), self::ALL],
                [LeadResource::getUrl('create'), self::COMMERCIAL_WRITERS],
                [ContactResource::getUrl('index'), self::ALL],
                [ContactResource::getUrl('create'), self::COMMERCIAL_WRITERS],
                [AccountResource::getUrl('index'), self::ALL],
                [AccountResource::getUrl('create'), self::COMMERCIAL_WRITERS],
                [DealResource::getUrl('index'), self::ALL],
                [DealResource::getUrl('create'), self::COMMERCIAL_WRITERS],
                [ActivityResource::getUrl('index'), self::ALL],
                [ActivityResource::getUrl('create'), [...self::COMMERCIAL_WRITERS, 'support']],
                // D-15 (2026-09-28): the employee lists its tasks and creates its own to-dos.
                [TaskResource::getUrl('index'), self::EVERYONE],
                [TaskResource::getUrl('create'), [...self::COMMERCIAL_WRITERS, 'support', 'employee']],
                [ProductResource::getUrl('index'), self::ALL],
                [ProductResource::getUrl('create'), self::SETTINGS],
                [EmailTemplateResource::getUrl('index'), self::ALL],
                [EmailTemplateResource::getUrl('create'), self::SETTINGS],
                [ActivityTypeResource::getUrl('index'), self::SETTINGS],
                [CompetitorResource::getUrl('index'), self::SETTINGS],
                [CustomFieldResource::getUrl('index'), self::SETTINGS],
                [DealCloseReasonResource::getUrl('index'), self::SETTINGS],
                [IndustryResource::getUrl('index'), self::SETTINGS],
                [LeadScoringRuleResource::getUrl('index'), self::SETTINGS],
                [LeadSourceResource::getUrl('index'), self::SETTINGS],
                [LeadStatusResource::getUrl('index'), self::SETTINGS],
                [PipelineResource::getUrl('index'), self::SETTINGS],
                [TagResource::getUrl('index'), self::SETTINGS],
                [UserResource::getUrl('index'), self::SETTINGS],
                [TeamResource::getUrl('index'), self::SETTINGS],
                [RoleResource::getUrl('index'), ['super_admin']],
                // D-16 (2026-09-30): the backups page is the super admin's alone, like the roles screen.
                [Backups::getUrl(), ['super_admin']],
                [ActivityLogResource::getUrl('index'), self::SETTINGS],
                [ImportResource::getUrl('index'), ['super_admin', 'admin', 'sales_manager']],
                // D-13: every role may export within its scope, and anyone who may export sees their own runs.
                [ExportResource::getUrl('index'), self::ALL],
                // D-15 (2026-09-28): the employee's pages — dashboard, tasks board, calendar, preferences.
                [Dashboard::getUrl(), self::EVERYONE],
                [DealBoard::getUrl(), self::ALL],
                // D-14 (2026-09-21): the shared tasks board is open to every role holding task.view_any — all seven since D-15.
                [TasksBoard::getUrl(), self::EVERYONE],
                [Calendar::getUrl(), self::EVERYONE],
                [NotificationPreferences::getUrl(), self::EVERYONE],
                [(string) Filament::getProfileUrl(), self::EVERYONE],
                [GeneralSettings::getUrl(), self::SETTINGS],
                [ActivityReportPage::getUrl(), self::ALL],
                [ConversionFunnelReportPage::getUrl(), self::ALL],
                [ForecastReportPage::getUrl(), self::ALL],
                [LeadReportPage::getUrl(), self::ALL],
                [PipelineReportPage::getUrl(), self::ALL],
                [SalesPerformanceReportPage::getUrl(), self::ALL],
                [SourcePerformanceReportPage::getUrl(), self::ALL],
                [TaskPerformanceReportPage::getUrl(), self::ALL],
                [WinLossReportPage::getUrl(), self::ALL],
            ];

            foreach ($pages as [$url, $allowed]) {
                $this->check($mismatches, $viewer, $role, $url, in_array($role->value, $allowed, true) ? 200 : 403);
            }

            $this->walkRecords($mismatches, $viewer, $role, $team);
        }

        $this->assertSame([], $mismatches, "Matrix mismatches:\n".implode("\n", $mismatches));
    }

    /**
     * @param  list<string>  $mismatches
     */
    private function walkRecords(array &$mismatches, User $viewer, CrmRole $role, Team $team): void
    {
        $teammate = $this->makeUser(CrmRole::SalesRep, ['name' => 'Teammate of '.$role->value], $team);
        $outsider = $this->makeUser(CrmRole::SalesRep, ['name' => 'Outsider of '.$role->value]);

        $reach = match ($role) {
            CrmRole::SuperAdmin, CrmRole::Admin, CrmRole::Support, CrmRole::ReadOnly => ['own' => true, 'team' => true, 'foreign' => true],
            CrmRole::SalesManager => ['own' => true, 'team' => true, 'foreign' => false],
            CrmRole::SalesRep => ['own' => true, 'team' => false, 'foreign' => false],
            // D-15 (2026-09-28): own tasks only, whatever the team.
            CrmRole::Employee => ['own' => true, 'team' => false, 'foreign' => false],
        };
        $writes = in_array($role->value, self::COMMERCIAL_WRITERS, true);
        // D-15 (2026-09-28): the employee reads no lead, contact, account, deal or activity — not even one it owns.
        $readsSales = $role !== CrmRole::Employee;

        foreach (['own' => $viewer, 'team' => $teammate, 'foreign' => $outsider] as $kind => $owner) {
            $records = [
                [LeadResource::class, Lead::factory()->create(['owner_id' => $owner->getKey()]), $writes, $readsSales],
                [ContactResource::class, Contact::factory()->create(['owner_id' => $owner->getKey()]), $writes, $readsSales],
                [AccountResource::class, Account::factory()->create(['owner_id' => $owner->getKey()]), $writes, $readsSales],
                [DealResource::class, Deal::factory()->create(['owner_id' => $owner->getKey()]), $writes, $readsSales],
                [TaskResource::class, Task::factory()->create(['assignee_id' => $owner->getKey(), 'created_by' => $owner->getKey()]), ($role === CrmRole::Support || $role === CrmRole::Employee || $writes), true],
                [ActivityResource::class, Activity::factory()->create(['owner_id' => $owner->getKey(), 'lead_id' => Lead::factory()->create(['owner_id' => $owner->getKey()])->getKey()]), null, $readsSales],
            ];

            foreach ($records as [$resource, $record, $mayEdit, $reads]) {
                $visible = $reach[$kind] && $reads;
                $this->check($mismatches, $viewer, $role, $resource::getUrl('view', ['record' => $record]), $visible ? 200 : 404, "{$kind} view");

                // Activities are immutable (A-10): they have no edit page.
                if ($mayEdit === null) {
                    continue;
                }

                $this->check($mismatches, $viewer, $role, $resource::getUrl('edit', ['record' => $record]), ! $visible ? 404 : ($mayEdit ? 200 : 403), "{$kind} edit");
            }
        }
    }

    /**
     * @param  list<string>  $mismatches
     */
    private function check(array &$mismatches, User $viewer, CrmRole $role, string $url, int $expected, string $label = 'page'): void
    {
        $status = $this->actingAs($viewer)->get($url)->status();

        if ($status !== $expected) {
            $mismatches[] = sprintf('%s %s %s: expected %d, got %d', $role->value, $label, parse_url($url, PHP_URL_PATH), $expected, $status);
        }
    }
}
