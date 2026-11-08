<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Filament\Exports\LeadExporter;
use App\Filament\Pages\DealBoard;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Deals\Pages\CreateDeal;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Export;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Isolation inside the Sales entities (decision D-30): what a user lists,
 * searches, sees on the board and exports is the project they work in; a
 * record of another project still opens from its own link and says which
 * project it belongs to; a new record is born in the current project.
 */
final class ProjectIsolationTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private Project $default;

    private Project $iqan;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();

        $this->default = $this->defaultProject();
        $this->iqan = $this->makeProject();
        $this->user = $this->admin();
        $this->useProject($this->user, $this->iqan);
    }

    #[Test]
    public function every_sales_list_shows_the_current_project_only_and_switching_flips_it(): void
    {
        [$inIqan, $inDefault] = $this->recordPairs();

        $lists = [
            [ListLeads::class, 'all', $inIqan['lead'], $inDefault['lead']],
            [ListContacts::class, null, $inIqan['contact'], $inDefault['contact']],
            [ListAccounts::class, 'all', $inIqan['account'], $inDefault['account']],
            [ListDeals::class, 'all', $inIqan['deal'], $inDefault['deal']],
        ];

        foreach ($lists as [$page, $tab, $ours, $theirs]) {
            $component = Livewire::actingAs($this->user)->test($page);

            if ($tab !== null) {
                $component->set('activeTab', $tab);
            }

            $component
                ->assertCanSeeTableRecords([$ours])
                ->assertCanNotSeeTableRecords([$theirs]);
        }

        $this->useProject($this->user, $this->default);

        foreach ($lists as [$page, $tab, $ours, $theirs]) {
            $component = Livewire::actingAs($this->user)->test($page);

            if ($tab !== null) {
                $component->set('activeTab', $tab);
            }

            $component
                ->assertCanSeeTableRecords([$theirs])
                ->assertCanNotSeeTableRecords([$ours]);
        }
    }

    #[Test]
    public function global_search_runs_inside_the_current_project(): void
    {
        [$inIqan, $inDefault] = $this->recordPairs();

        $this->actingAs($this->user);

        $this->assertSame([$inIqan['lead']->getKey()], $this->ids(LeadResource::getGlobalSearchEloquentQuery()->pluck('id')->all()));
        $this->assertSame([$inIqan['contact']->getKey()], $this->ids(ContactResource::getGlobalSearchEloquentQuery()->pluck('id')->all()));
        $this->assertSame([$inIqan['account']->getKey()], $this->ids(AccountResource::getGlobalSearchEloquentQuery()->pluck('id')->all()));
        $this->assertSame([$inIqan['deal']->getKey()], $this->ids(DealResource::getGlobalSearchEloquentQuery()->pluck('id')->all()));

        $this->useProject($this->user, $this->default);

        $this->assertSame([$inDefault['lead']->getKey()], $this->ids(LeadResource::getGlobalSearchEloquentQuery()->pluck('id')->all()));
        $this->assertSame([$inDefault['deal']->getKey()], $this->ids(DealResource::getGlobalSearchEloquentQuery()->pluck('id')->all()));
    }

    #[Test]
    public function the_deal_board_shows_the_current_projects_deals_only(): void
    {
        $ours = Deal::factory()->create(['project_id' => $this->iqan->getKey(), 'owner_id' => $this->user->getKey(), 'title' => 'Iqan rollout']);
        $theirs = Deal::factory()->create(['project_id' => $this->default->getKey(), 'owner_id' => $this->user->getKey(), 'title' => 'Main project renewal']);

        Livewire::actingAs($this->user)
            ->test(DealBoard::class)
            ->assertSee($ours->title)
            ->assertDontSee($theirs->title);

        $this->useProject($this->user, $this->default);

        Livewire::actingAs($this->user)
            ->test(DealBoard::class)
            ->assertSee($theirs->title)
            ->assertDontSee($ours->title);
    }

    #[Test]
    public function an_export_holds_the_current_projects_rows_only_and_carries_the_project_column(): void
    {
        Storage::fake('local');
        $ours = Lead::factory()->create(['project_id' => $this->iqan->getKey(), 'owner_id' => $this->user->getKey(), 'company_name' => 'Iqan Ventures']);
        Lead::factory()->create(['project_id' => $this->default->getKey(), 'owner_id' => $this->user->getKey(), 'company_name' => 'Main Street Ltd']);

        Livewire::actingAs($this->user)
            ->test(ListLeads::class)
            ->set('activeTab', 'all')
            ->callAction(TestAction::make('export')->table())
            ->assertHasNoActionErrors();

        $export = Export::query()->latest('id')->firstOrFail();
        $this->assertSame(LeadExporter::class, $export->exporter);
        $this->assertSame(1, $export->total_rows, 'the file holds the current project\'s lead and nothing else');
        $this->assertSame(1, $export->successful_rows);

        $disk = Storage::disk('local');
        $directory = $export->getFileDirectory();
        $headers = (string) $disk->get($directory.'/headers.csv');
        $rows = '';

        foreach ($disk->files($directory) as $file) {
            if (str_ends_with($file, '.csv') && ! str_ends_with($file, 'headers.csv')) {
                $rows .= (string) $disk->get($file);
            }
        }

        $this->assertStringContainsString(__('leads.fields.project'), $headers, 'the project column is labelled');
        $this->assertStringContainsString($this->iqan->display_name, $rows, 'each row names its project');
        $this->assertStringContainsString($ours->email, $rows);
        $this->assertStringContainsString('Iqan Ventures', $rows);
        $this->assertStringNotContainsString('Main Street Ltd', $rows);
        $this->assertStringNotContainsString($this->default->display_name, $rows);
    }

    #[Test]
    public function a_record_of_another_project_still_opens_by_url_and_shows_its_project_badge(): void
    {
        $this->useProject($this->user, $this->default);
        [$inIqan] = $this->recordPairs();

        $pages = [
            [LeadResource::class, $inIqan['lead'], ViewLead::class, EditLead::class],
            [ContactResource::class, $inIqan['contact'], ViewContact::class, EditContact::class],
            [AccountResource::class, $inIqan['account'], ViewAccount::class, EditAccount::class],
            [DealResource::class, $inIqan['deal'], ViewDeal::class, EditDeal::class],
        ];

        foreach ($pages as [$resource, $record, $viewPage, $editPage]) {
            $this->actingAs($this->user)->get($resource::getUrl('view', ['record' => $record]))->assertOk();
            $this->actingAs($this->user)->get($resource::getUrl('edit', ['record' => $record]))->assertOk();

            $view = Livewire::actingAs($this->user)->test($viewPage, ['record' => $record->getRouteKey()]);
            $view->assertOk()->assertSee($this->iqan->display_name);

            $this->assertSame($this->iqan->display_name, $this->badgeOf($view, 'infolist'), "{$viewPage} shows the record's own project");

            $edit = Livewire::actingAs($this->user)->test($editPage, ['record' => $record->getRouteKey()]);
            $edit->assertOk()->assertSee($this->iqan->display_name);

            $this->assertSame($this->iqan->display_name, $this->badgeOf($edit, 'form'), "{$editPage} shows the record's own project");
        }
    }

    #[Test]
    public function the_create_pages_show_the_current_project_as_a_badge_and_stamp_it_on_the_record(): void
    {
        $forms = [
            [CreateLead::class, ['first_name' => 'نورة', 'last_name' => 'القحطاني'], fn (): ?Model => Lead::query()->where('last_name', 'القحطاني')->first()],
            [CreateContact::class, ['first_name' => 'خالد', 'last_name' => 'السالم'], fn (): ?Model => Contact::query()->where('last_name', 'السالم')->first()],
            [CreateAccount::class, ['name' => 'شركة الأفق'], fn (): ?Model => Account::query()->where('name', 'شركة الأفق')->first()],
            [CreateDeal::class, ['title' => 'صفقة الأفق', 'amount' => 1000], fn (): ?Model => Deal::query()->where('title', 'صفقة الأفق')->first()],
        ];

        foreach ($forms as [$page, $data, $created]) {
            $component = Livewire::actingAs($this->user)
                ->test($page)
                ->assertSee($this->iqan->display_name);

            $this->assertSame($this->iqan->display_name, $this->badgeOf($component, 'form'), "{$page} shows the current project");

            $component
                ->fillForm($data)
                ->call('create')
                ->assertHasNoFormErrors();

            $record = $created();
            $this->assertInstanceOf(Model::class, $record, "{$page} created the record");
            $this->assertSame($this->iqan->getKey(), (int) $record->getAttribute('project_id'), "{$page} stamped the current project");
        }

        // The badge follows the switch: the next record is born in the other project.
        $this->useProject($this->user, $this->default);

        $component = Livewire::actingAs($this->user)->test(CreateLead::class);

        $this->assertSame($this->default->display_name, $this->badgeOf($component, 'form'));

        $component
            ->fillForm(['first_name' => 'سارة', 'last_name' => 'العتيبي'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($this->default->getKey(), Lead::query()->where('last_name', 'العتيبي')->firstOrFail()->project_id);
    }

    /** What the project badge of a rendered page says (ProjectBadge, D-30). */
    private function badgeOf(Testable $component, string $schema): string
    {
        $page = $component->instance();
        $this->assertInstanceOf(HasSchemas::class, $page);

        $entry = $page->getSchema($schema)?->getComponent('project_badge', withHidden: true);
        $this->assertInstanceOf(TextEntry::class, $entry, "the {$schema} schema carries the project badge");

        return (string) $entry->getState();
    }

    /**
     * One lead, contact, account and deal in «إيقان» and one of each in the
     * default project, all owned by the user, so visibility never hides any
     * of them and only the project does.
     *
     * @return array{0: array<string, Model>, 1: array<string, Model>}
     */
    private function recordPairs(): array
    {
        $pairs = [];

        foreach (['iqan' => $this->iqan, 'default' => $this->default] as $key => $project) {
            $owned = ['project_id' => $project->getKey(), 'owner_id' => $this->user->getKey()];
            $account = Account::factory()->create($owned);

            $pairs[$key] = [
                'lead' => Lead::factory()->create($owned),
                'contact' => Contact::factory()->create($owned + ['account_id' => $account->getKey()]),
                'account' => $account,
                // On the pair's own account, so the factory makes no second account.
                'deal' => Deal::factory()->create($owned + ['account_id' => $account->getKey()]),
            ];
        }

        return [$pairs['iqan'], $pairs['default']];
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return list<int>
     */
    private function ids(array $ids): array
    {
        return array_values(array_map('intval', $ids));
    }
}
