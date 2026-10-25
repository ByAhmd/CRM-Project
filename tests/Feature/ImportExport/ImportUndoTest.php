<?php

declare(strict_types=1);

namespace Tests\Feature\ImportExport;

use App\Contracts\OwnedRecord;
use App\Enums\ActivityLogEvent;
use App\Enums\DealStatus;
use App\Enums\Permission;
use App\Enums\WorkspacePanel;
use App\Filament\Imports\AccountImporter;
use App\Filament\Imports\ContactImporter;
use App\Filament\Imports\DealImporter;
use App\Filament\Imports\LeadImporter;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Resources\Imports\Pages\ListImports;
use App\Filament\Resources\Imports\Pages\ViewImport;
use App\Filament\Resources\Imports\Tables\ImportsTable;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Jobs\UndoImport;
use App\Models\Account;
use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealStageLog;
use App\Models\Import;
use App\Models\Lead;
use App\Models\LeadStatusLog;
use App\Models\Note;
use App\Models\Product;
use App\Models\Task;
use App\Models\User;
use App\Services\ImportExport\ImportUndoService;
use Filament\Actions\Action;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\Concerns\DrainsQueueLikeTheScheduler;
use Tests\Concerns\ReadsFilamentNotifications;
use Tests\TestCase;

/**
 * Undoing an import run (module 18, decision D-29): what is deleted, what
 * is kept and why, who may do it, the run page's confirmation, and the
 * queued, chunked execution the way the scheduler drain runs it (D-1).
 *
 * Runs are built two ways. Where the real path is cheap — one CSV per
 * entity through the list page on the sync queue, which stamps `import_id`
 * on every created record and `completed_at` on the run — the real path is
 * used. The touched-rule, reach and chunking cases create the run row and
 * its records by hand at a travelled moment, stamping `import_id` and
 * `completed_at` themselves, so the moments before and after completion can
 * be told apart.
 */
final class ImportUndoTest extends TestCase
{
    use CreatesCrmFixtures;
    use DrainsQueueLikeTheScheduler;
    use ReadsFilamentNotifications;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
    }

    /**
     * @param  class-string  $page
     * @param  class-string<Model&OwnedRecord>  $model
     * @param  array<string, string>  $created
     */
    #[Test]
    #[DataProvider('entities')]
    public function undoing_a_run_soft_deletes_what_it_created_audits_each_deletion_and_keeps_the_duplicate_it_updated(string $page, string $csv, string $model, array $created): void
    {
        $manager = $this->salesManager();
        $duplicate = $this->duplicateFor($model, $manager);

        $import = $this->importThroughPanel($manager, $page, $csv, 'update');

        $this->assertSame(2, $import->successful_rows, 'one row created a record, the other updated the duplicate');
        $record = $model::query()->where($created)->firstOrFail();
        $this->assertSame($import->getKey(), $record->getAttribute('import_id'), 'a created record remembers its run');
        $this->assertNull($duplicate->refresh()->getAttribute('import_id'), 'an updated duplicate never carries the run');

        $service = app(ImportUndoService::class);
        $preview = $service->preview($import, $manager);

        $this->assertSame(1, $preview->deletable);
        $this->assertSame(1, $preview->updated);
        $this->assertSame(0, $preview->touched);
        $this->assertSame(0, $preview->forbidden);
        $this->assertSame(0, $preview->alreadyDeleted);
        $this->assertSame(1, $preview->total);
        $this->assertSame(1, $preview->kept());
        $this->assertSame([(int) $record->getKey()], $preview->deletableIds);

        // The sync queue runs the first batch as soon as the request has committed.
        $service->request($import, $manager);
        $import->refresh();

        $this->assertSoftDeleted($record);
        $this->assertNotSoftDeleted($duplicate);
        $this->assertDatabaseHas('activity_log', [
            'description' => $model::permissionGroup().'.deleted',
            'subject_type' => $record->getMorphClass(),
            'subject_id' => $record->getKey(),
            'causer_id' => $manager->getKey(),
        ]);

        $this->assertNotNull($import->undone_at);
        $this->assertSame($manager->getKey(), $import->undone_by);
        $this->assertSame(1, $import->undone_rows);
        $this->assertSame(1, $import->kept_rows);
        $this->assertSame($import->successful_rows, $import->undone_rows + $import->kept_rows);
        $this->assertSame(ImportResource::STATUS_UNDONE, ImportResource::status($import));

        $this->assertSame(
            ['undone' => 1, 'kept' => 1, 'updated' => 1, 'touched' => 0, 'forbidden' => 0, 'already_deleted' => 0],
            $this->undoneLedgerCounts($import, $manager),
        );
        $this->databaseNotification($manager, __('imports.notifications.undo_completed.title'));
    }

    /**
     * One CSV per entity: a new record and a row matching the duplicate
     * duplicateFor() prepares, imported with the «update duplicates» strategy.
     *
     * @return array<string, array{class-string, string, class-string<Model&OwnedRecord>, array<string, string>}>
     */
    public static function entities(): array
    {
        return [
            'leads' => [ListLeads::class, "first_name,last_name,email\nNew,Lead,new.lead@example.com\nDup,Lead,dup.lead@example.com\n", Lead::class, ['email' => 'new.lead@example.com']],
            'contacts' => [ListContacts::class, "first_name,last_name,email\nNew,Contact,new.contact@example.com\nDup,Contact,dup.contact@example.com\n", Contact::class, ['email' => 'new.contact@example.com']],
            'accounts' => [ListAccounts::class, "name\nNew Account\nDup Account\n", Account::class, ['name' => 'New Account']],
            'deals' => [ListDeals::class, "title,account\nNew Deal,Horizon Trading\nDup Deal,Horizon Trading\n", Deal::class, ['title' => 'New Deal']],
        ];
    }

    /**
     * Every touch rule reads "after the run completed": a child stamped at or
     * before `completed_at` — here a status log a colleague wrote at the very
     * moment the run completed (the importer never writes one) — is not a
     * touch, while one stamped a moment later is.
     */
    #[Test]
    public function a_lead_worked_on_after_the_run_is_kept_under_every_touched_rule_while_one_moved_before_completion_is_not(): void
    {
        $manager = $this->salesManager();
        $this->travelTo(Carbon::parse('2026-10-20 09:00:00'));
        $import = $this->makeImport($manager, LeadImporter::class, 9);
        [$edited, $noted, $called, $tasked, $filed, $converted, $moved, $movedBeforeCompletion, $untouched] = Lead::factory()
            ->count(9)
            ->create(['owner_id' => $manager->getKey(), 'import_id' => $import->getKey()])
            ->all();

        // A status log stamped at completion, not after it: not a touch.
        $this->statusLogOn($movedBeforeCompletion, $manager);

        $this->travel(10)->minutes();

        $edited->update(['company_name' => 'Edited after the run']); // LogsActivity writes lead.updated
        Note::factory()->create(['lead_id' => $noted->getKey(), 'author_id' => $manager->getKey()]);
        Activity::factory()->create(['lead_id' => $called->getKey(), 'owner_id' => $manager->getKey(), 'created_by' => $manager->getKey()]);
        Task::factory()->create(['lead_id' => $tasked->getKey(), 'assignee_id' => $manager->getKey()]);
        Attachment::factory()->forSubject($filed)->create(['uploaded_by' => $manager->getKey()]);
        Lead::withoutWorkflowGuard(static fn (): bool => $converted->forceFill(['converted_at' => now()])->saveQuietly());
        $this->statusLogOn($moved, $manager);

        $service = app(ImportUndoService::class);
        $preview = $service->preview($import, $manager);

        $this->assertSame(7, $preview->touched);
        $this->assertSame(2, $preview->deletable);
        $this->assertSame(0, $preview->updated);
        $this->assertSame(0, $preview->forbidden);
        $this->assertSame(0, $preview->alreadyDeleted);
        $this->assertSame(9, $preview->total);
        $this->assertEqualsCanonicalizing([$movedBeforeCompletion->getKey(), $untouched->getKey()], $preview->deletableIds);

        $service->request($import, $manager);

        foreach ([$edited, $noted, $called, $tasked, $filed, $converted, $moved] as $kept) {
            $this->assertNotSoftDeleted($kept);
        }

        $this->assertSoftDeleted($movedBeforeCompletion);
        $this->assertSoftDeleted($untouched);
        $import->refresh();
        $this->assertSame(2, $import->undone_rows);
        $this->assertSame(7, $import->kept_rows);
        $this->assertSame(['undone' => 2, 'kept' => 7, 'updated' => 0, 'touched' => 7, 'forbidden' => 0, 'already_deleted' => 0], $this->undoneLedgerCounts($import, $manager));
    }

    /**
     * The deal rules at the completion boundary: a contact attached to the deal
     * at the very moment the run completed is not a touch, one attached a
     * moment later is. DealImporter never writes `deal_contacts` (it sets
     * `deals.contact_id`); the pivot's moment is compared like every other
     * child's because the rule reads "after the run completed".
     */
    #[Test]
    public function a_deal_worked_on_after_the_run_is_kept_under_every_touched_rule_while_a_contact_attached_before_completion_is_not(): void
    {
        $manager = $this->salesManager();
        $this->travelTo(Carbon::parse('2026-10-20 09:00:00'));
        $account = Account::factory()->create(['owner_id' => $manager->getKey()]);
        $contact = Contact::factory()->create(['owner_id' => $manager->getKey(), 'account_id' => $account->getKey()]);
        $import = $this->makeImport($manager, DealImporter::class, 6);
        [$closed, $staged, $priced, $introduced, $introducedBeforeCompletion, $untouched] = Deal::factory()
            ->count(6)
            ->create(['owner_id' => $manager->getKey(), 'account_id' => $account->getKey(), 'import_id' => $import->getKey()])
            ->all();

        // A contact attached at completion, not after it: not a touch.
        $introducedBeforeCompletion->contacts()->attach($contact->getKey());

        $this->travel(10)->minutes();

        Deal::withoutWorkflowGuard(static fn (): bool => $closed->forceFill(['status' => DealStatus::Won, 'won_at' => now()])->saveQuietly());
        DealStageLog::query()->create([
            'deal_id' => $staged->getKey(),
            'from_stage_id' => $staged->stage_id,
            'to_stage_id' => $staged->stage_id,
            'changed_by' => $manager->getKey(),
            'changed_at' => now(),
        ]);
        // Written straight to the table: DealProductObserver would also recalculate the deal's amount and leave a
        // ledger entry, which would mark the deal through the ledger rule rather than the product-line rule.
        DB::table('deal_products')->insert([
            'deal_id' => $priced->getKey(),
            'product_id' => Product::factory()->create()->getKey(),
            'quantity' => 1,
            'unit_price' => '100.00',
            'discount_percent' => 0,
            'line_total' => '100.00',
            'sort' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $introduced->contacts()->attach($contact->getKey());

        $preview = app(ImportUndoService::class)->preview($import, $manager);

        $this->assertSame(4, $preview->touched);
        $this->assertSame(2, $preview->deletable);
        $this->assertSame(6, $preview->total);
        $this->assertEqualsCanonicalizing([$introducedBeforeCompletion->getKey(), $untouched->getKey()], $preview->deletableIds);
        $this->assertSame(0, ActivityLog::query()->where('subject_type', $priced->getMorphClass())->where('subject_id', $priced->getKey())->where('created_at', '>', $import->completed_at)->count(), 'precondition: the product line alone marks the deal');
    }

    /**
     * An Account run writes accounts only and a Contact run contacts only, so a
     * record that names one of theirs — a contact, deal or child account under
     * an imported account; a deal whose primary contact, or a lead whose
     * conversion, is an imported contact — can only have been linked to it
     * afterwards, and that link's ledger entry lands on the child, never on the
     * imported record. Existence is therefore the touch, whatever the child's
     * age: an older contact moved onto the imported account from its form
     * counts exactly like one created under it.
     */
    #[Test]
    public function an_account_or_a_contact_that_other_records_came_to_name_after_the_run_is_kept(): void
    {
        $manager = $this->salesManager();
        $this->travelTo(Carbon::parse('2026-10-20 09:00:00'));
        $accountRun = $this->makeImport($manager, AccountImporter::class, 5);
        [$withContact, $withDeal, $withChild, $withMovedContact, $plainAccount] = Account::factory()
            ->count(5)
            ->create(['owner_id' => $manager->getKey(), 'import_id' => $accountRun->getKey()])
            ->all();
        $elsewhere = Account::factory()->create(['owner_id' => $manager->getKey()]);
        $contactRun = $this->makeImport($manager, ContactImporter::class, 4);
        [$onDeal, $primaryOfDeal, $convertedInto, $plainContact] = Contact::factory()
            ->count(4)
            ->create(['owner_id' => $manager->getKey(), 'account_id' => $elsewhere->getKey(), 'import_id' => $contactRun->getKey()])
            ->all();
        // Older records, from before the run, that a colleague links to the imported ones afterwards.
        $olderContact = Contact::factory()->create(['owner_id' => $manager->getKey(), 'account_id' => $elsewhere->getKey()]);
        $olderDeal = Deal::factory()->create(['owner_id' => $manager->getKey(), 'account_id' => $elsewhere->getKey()]);

        $this->travel(10)->minutes();

        Contact::factory()->create(['owner_id' => $manager->getKey(), 'account_id' => $withContact->getKey()]);
        Deal::factory()->create(['owner_id' => $manager->getKey(), 'account_id' => $withDeal->getKey()]);
        Account::factory()->create(['owner_id' => $manager->getKey(), 'parent_account_id' => $withChild->getKey()]);
        $olderContact->update(['account_id' => $withMovedContact->getKey()]); // the contact form's account field: contact.updated, on the contact
        $olderDeal->contacts()->attach($onDeal->getKey());
        Deal::factory()->create(['owner_id' => $manager->getKey(), 'account_id' => $elsewhere->getKey(), 'contact_id' => $primaryOfDeal->getKey()]);
        Lead::factory()->create(['owner_id' => $manager->getKey(), 'converted_contact_id' => $convertedInto->getKey()]);

        // Preconditions: the links wrote nothing on the imported records themselves, so only the new rules can mark them.
        $this->assertSame(0, ActivityLog::query()->where('subject_type', $withMovedContact->getMorphClass())->where('subject_id', $withMovedContact->getKey())->where('created_at', '>', $accountRun->completed_at)->count(), 'moving the older contact left its ledger entry on the contact, not on the account');
        $this->assertSame(0, ActivityLog::query()->where('subject_type', $primaryOfDeal->getMorphClass())->whereIn('subject_id', [$primaryOfDeal->getKey(), $convertedInto->getKey()])->where('created_at', '>', $contactRun->completed_at)->count(), 'the deal and the lead left their ledger entries on themselves, not on the contacts');

        $service = app(ImportUndoService::class);

        $accounts = $service->preview($accountRun, $manager);
        $this->assertSame(4, $accounts->touched);
        $this->assertSame([$plainAccount->getKey()], $accounts->deletableIds, 'only the account nothing came to name is deletable');

        $contacts = $service->preview($contactRun, $manager);
        $this->assertSame(3, $contacts->touched);
        $this->assertSame([$plainContact->getKey()], $contacts->deletableIds, 'only the contact nothing came to name is deletable');
    }

    #[Test]
    public function records_outside_the_actors_delete_reach_and_records_already_deleted_are_counted_and_left_alone(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $teammate = $this->salesRep($team);
        $outsider = $this->salesRep();
        $admin = $this->admin();
        $this->travelTo(Carbon::parse('2026-10-20 09:00:00'));
        $import = $this->makeImport($manager, LeadImporter::class, 4);
        $mine = Lead::factory()->create(['owner_id' => $manager->getKey(), 'import_id' => $import->getKey()]);
        $teammates = Lead::factory()->create(['owner_id' => $teammate->getKey(), 'import_id' => $import->getKey()]);
        $outsiders = Lead::factory()->create(['owner_id' => $outsider->getKey(), 'import_id' => $import->getKey()]);
        $gone = Lead::factory()->create(['owner_id' => $manager->getKey(), 'import_id' => $import->getKey()]);
        $gone->delete();
        $deletedAt = $gone->refresh()->deleted_at;

        $this->travel(10)->minutes();

        $service = app(ImportUndoService::class);
        $preview = $service->preview($import, $manager);

        $this->assertSame(2, $preview->deletable);
        $this->assertSame(1, $preview->forbidden);
        $this->assertSame(1, $preview->alreadyDeleted);
        $this->assertSame(0, $preview->touched);
        $this->assertSame(4, $preview->total);
        $this->assertEqualsCanonicalizing([$mine->getKey(), $teammates->getKey()], $preview->deletableIds);

        // Set-based or not, the answer is the entity policy's, record by record.
        foreach ([$mine, $teammates, $outsiders] as $lead) {
            $this->assertSame(
                Gate::forUser($manager)->allows('delete', $lead),
                in_array((int) $lead->getKey(), $preview->deletableIds, true),
                "lead {$lead->getKey()}: the preview agrees with LeadPolicy::delete",
            );
        }

        $byAdmin = $service->preview($import, $admin);
        $this->assertSame(3, $byAdmin->deletable, 'all-level reach deletes the outsider\'s record too');
        $this->assertSame(0, $byAdmin->forbidden);
        $this->assertSame(1, $byAdmin->alreadyDeleted);

        $service->request($import, $manager);

        $this->assertSoftDeleted($mine);
        $this->assertSoftDeleted($teammates);
        $this->assertNotSoftDeleted($outsiders);
        $this->assertEquals($deletedAt, $gone->refresh()->deleted_at, 'an already deleted record is not deleted again');
        $this->assertSame(1, ActivityLog::query()->where('description', ActivityLogEvent::LeadDeleted->value)->where('subject_id', $gone->getKey())->count());

        $import->refresh();
        $this->assertSame(2, $import->undone_rows);
        $this->assertSame(2, $import->kept_rows);
        $this->assertSame(['undone' => 2, 'kept' => 2, 'updated' => 0, 'touched' => 0, 'forbidden' => 1, 'already_deleted' => 1], $this->undoneLedgerCounts($import, $manager));

        // An administrator reviewing the manager's next run reaches the outsider's record under lead.view_all.
        $second = $this->makeImport($manager, LeadImporter::class, 1);
        $theirs = Lead::factory()->create(['owner_id' => $outsider->getKey(), 'import_id' => $second->getKey()]);
        $this->travel(10)->minutes();

        $service->request($second, $admin);

        $this->assertSoftDeleted($theirs);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LeadDeleted->value,
            'subject_id' => $theirs->getKey(),
            'causer_id' => $admin->getKey(),
        ]);
        $second->refresh();
        $this->assertSame($admin->getKey(), $second->undone_by);
        $this->assertSame(['undone' => 1, 'kept' => 0, 'updated' => 0, 'touched' => 0, 'forbidden' => 0, 'already_deleted' => 0], $this->undoneLedgerCounts($second, $admin));
    }

    #[Test]
    public function only_the_owner_or_a_reviewer_holding_the_delete_key_may_undo_a_completed_real_run_once(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $admin = $this->admin();
        $otherTeamManager = $this->salesManager($this->makeTeam('Jeddah Team', 'فريق جدة'));
        $ownerWithoutDelete = $this->userWithPermissions($team, [Permission::WorkspaceSales, Permission::LeadViewAny, Permission::LeadImport]);
        $reviewer = $this->userWithPermissions(null, [Permission::WorkspaceSales, Permission::ImportsView, Permission::LeadViewAny, Permission::LeadViewAll, Permission::LeadDelete]);
        $viewerWithoutDelete = $this->userWithPermissions(null, [Permission::WorkspaceSales, Permission::ImportsView, Permission::LeadViewAny, Permission::LeadViewAll]);

        $run = $this->makeImport($manager, LeadImporter::class, 1);

        $this->assertTrue($manager->can('undo', $run), 'the owner holding lead.delete');
        $this->assertTrue($reviewer->can('undo', $run), 'imports.view with all-level reach and lead.delete on another user\'s run');
        $this->assertTrue($admin->can('undo', $run));
        $this->assertFalse($viewerWithoutDelete->can('undo', $run), 'a reviewer without the delete key');
        $this->assertFalse($rep->can('undo', $run), 'a sales rep: neither the run nor the delete key');
        $this->assertFalse($otherTeamManager->can('undo', $run), 'a team-scoped manager may not even view another user\'s run');

        $this->assertFalse($ownerWithoutDelete->can('undo', $this->makeImport($ownerWithoutDelete, LeadImporter::class, 1)), 'the owner without lead.delete');
        $this->assertFalse($rep->can('undo', $this->makeImport($rep, LeadImporter::class, 1)), 'a rep owning the run still lacks lead.delete');

        $this->assertFalse($manager->can('undo', $this->makeImport($manager, LeadImporter::class, 1, ['dry_run' => true])), 'a check-only run wrote nothing');
        $this->assertFalse($manager->can('undo', $this->makeImport($manager, LeadImporter::class, 1, ['completed_at' => null])), 'a run still processing');
        $this->assertFalse($manager->can('undo', $this->makeImport($manager, LeadImporter::class, 1, ['undo_requested_at' => now()])), 'a run already asked to be undone');
        $this->assertFalse($manager->can('undo', $this->makeImport($manager, LeadImporter::class, 1, ['undo_requested_at' => now(), 'undone_at' => now(), 'undone_by' => $manager->getKey()])), 'a run already undone');

        $this->expectException(AuthorizationException::class);
        app(ImportUndoService::class)->request($run, $rep);
    }

    #[Test]
    public function the_run_page_offers_the_undo_with_its_counts_and_confirming_queues_one_job_and_shows_the_run_undoing(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $outsider = $this->salesRep();
        $viewerWithoutDelete = $this->userWithPermissions(null, [Permission::WorkspaceSales, Permission::ImportsView, Permission::LeadViewAny, Permission::LeadViewAll]);
        $this->travelTo(Carbon::parse('2026-10-20 09:00:00'));
        // 6 records carry the run and 7 rows succeeded: one row updated a duplicate.
        $import = $this->makeImport($manager, LeadImporter::class, 7);
        Lead::factory()->count(3)->create(['owner_id' => $manager->getKey(), 'import_id' => $import->getKey()]);
        $touched = Lead::factory()->create(['owner_id' => $manager->getKey(), 'import_id' => $import->getKey()]);
        Lead::factory()->create(['owner_id' => $manager->getKey(), 'import_id' => $import->getKey()])->delete();
        Lead::factory()->create(['owner_id' => $outsider->getKey(), 'import_id' => $import->getKey()]);
        // A run whose only record has been worked on since: nothing to undo.
        $exhausted = $this->makeImport($manager, LeadImporter::class, 1);
        $worked = Lead::factory()->create(['owner_id' => $manager->getKey(), 'import_id' => $exhausted->getKey()]);

        $this->travel(10)->minutes();
        Note::factory()->create(['lead_id' => $touched->getKey(), 'author_id' => $manager->getKey()]);
        Note::factory()->create(['lead_id' => $worked->getKey(), 'author_id' => $manager->getKey()]);

        Livewire::actingAs($viewerWithoutDelete)
            ->test(ViewImport::class, ['record' => $import->getRouteKey()])
            ->assertActionHidden('undo');

        // The dialog says the counts before anything happens; the modal is a Livewire partial, so it is read from the mounted action.
        [$heading, $description, $canConfirm] = $this->mountedUndoDialog(
            Livewire::actingAs($manager)
                ->test(ViewImport::class, ['record' => $import->getRouteKey()])
                ->assertActionVisible('undo')
                ->mountAction('undo')
                ->assertActionMounted('undo'),
        );
        $this->assertSame(__('imports.confirmations.undo.heading'), $heading);
        $this->assertStringContainsString(e(trans_choice('imports.confirmations.undo.will_delete', 3, ['count' => '3'])), $description);
        $this->assertStringContainsString(e(__('imports.confirmations.undo.restorable')), $description);
        $this->assertStringContainsString(e(trans_choice('imports.confirmations.undo.keeps_updated', 1, ['count' => '1'])), $description);
        $this->assertStringContainsString(e(trans_choice('imports.confirmations.undo.keeps_touched', 1, ['count' => '1'])), $description);
        $this->assertStringContainsString(e(trans_choice('imports.confirmations.undo.keeps_forbidden', 1, ['count' => '1'])), $description);
        $this->assertStringContainsString(e(trans_choice('imports.confirmations.undo.keeps_deleted', 1, ['count' => '1'])), $description);
        $this->assertStringContainsString(e(__('imports.confirmations.undo.queued')), $description);
        $this->assertStringNotContainsString(e(__('imports.confirmations.undo.nothing')), $description);
        $this->assertTrue($canConfirm, 'something can be deleted, so the dialog can be confirmed');

        [$heading, $description, $canConfirm] = $this->mountedUndoDialog(
            Livewire::actingAs($manager)
                ->test(ViewImport::class, ['record' => $exhausted->getRouteKey()])
                ->assertActionVisible('undo')
                ->mountAction('undo')
                ->assertActionMounted('undo'),
        );
        $this->assertSame(__('imports.confirmations.undo.heading'), $heading);
        $this->assertSame(e(__('imports.confirmations.undo.nothing')), $description, 'nothing to delete: the reason, and nothing else');
        $this->assertFalse($canConfirm, 'nothing can be deleted, so the dialog has no submit button');
        $this->assertNull($exhausted->refresh()->undo_requested_at);
        $this->assertNull($import->refresh()->undo_requested_at, 'opening the dialog changes nothing');

        Queue::fake();

        $page = Livewire::actingAs($manager)
            ->test(ViewImport::class, ['record' => $import->getRouteKey()])
            ->callAction('undo')
            ->assertHasNoActionErrors()
            ->assertRedirect(ImportResource::getUrl('view', ['record' => $import]));

        // The toast is read from the session before assertNotified() pulls it out of there.
        $toast = $this->sessionNotification(__('imports.notifications.undo_started.title'));
        $this->assertSame(__('imports.notifications.undo_started.body'), $toast['body'] ?? null);
        $page->assertNotified(__('imports.notifications.undo_started.title'));

        Queue::assertPushed(UndoImport::class, 1);
        Queue::assertPushed(UndoImport::class, fn (UndoImport $job): bool => $job->importId === (int) $import->getKey()
            && $job->actorId === (int) $manager->getKey()
            && $job->locale === app()->getLocale()
            && $job->afterId === 0);

        $import->refresh();
        $this->assertNotNull($import->undo_requested_at);
        $this->assertNull($import->undone_at);
        $this->assertSame(ImportResource::STATUS_UNDOING, ImportResource::status($import));
        $this->assertSame(1, Lead::onlyTrashed()->where('import_id', $import->getKey())->count(), 'only the record deleted beforehand: the job waits on the queue');

        Livewire::actingAs($manager)
            ->test(ListImports::class)
            ->assertCanSeeTableRecords([$import])
            ->assertSee(__('imports.statuses.undoing'))
            ->assertSeeHtml('wire:poll.'.ImportsTable::POLL_INTERVAL);

        Livewire::actingAs($manager)
            ->test(ViewImport::class, ['record' => $import->getRouteKey()])
            ->assertSee(__('imports.sections.undo'))
            ->assertSee($import->undo_requested_at->format('Y-m-d H:i'))
            ->assertSee(__('imports.statuses.undoing'))
            ->assertActionHidden('undo');
    }

    /**
     * An administrator reviewing the manager's run confirms the undo
     * (ImportPolicy::undo: `imports.view`, all-level reach and `lead.delete`),
     * so the undoer is not the launcher: the run page's undo section must name
     * the administrator, which the summary — naming the launcher — cannot do
     * for it.
     */
    #[Test]
    public function the_undo_confirmed_on_the_page_runs_on_the_scheduler_drain_in_the_actors_locale_and_reports_in_the_bell(): void
    {
        // Production's connection (D-1); phpunit.xml puts the rest of the suite on sync.
        config()->set('queue.default', 'database');
        $manager = $this->salesManager();
        $admin = $this->admin();
        $this->travelTo(Carbon::parse('2026-10-20 09:00:00'));
        // 2 records carry the run and 3 rows succeeded: one row updated a duplicate.
        $import = $this->makeImport($manager, LeadImporter::class, 3);
        $leads = Lead::factory()->count(2)->create(['owner_id' => $manager->getKey(), 'import_id' => $import->getKey()]);
        $this->travel(10)->minutes();

        Livewire::actingAs($admin)
            ->test(ViewImport::class, ['record' => $import->getRouteKey()])
            ->callAction('undo')
            ->assertHasNoActionErrors();

        $requestLocale = app()->getLocale();
        $import->refresh();
        $this->assertTrue($import->isUndoing());
        $this->assertSame(1, DB::table('jobs')->count(), 'the first batch waits for the drain');
        $this->assertSame(2, Lead::query()->where('import_id', $import->getKey())->count(), 'nothing is deleted in the request');

        // The drain runs in the application's default locale, not the actor's.
        $output = $this->drainQueueLikeTheScheduler($requestLocale === 'ar' ? 'en' : 'ar');

        $this->assertSame(0, DB::table('failed_jobs')->count(), $output);
        $this->assertSame(0, DB::table('jobs')->count(), $output);

        $import->refresh();
        $this->assertNotNull($import->undone_at);
        $this->assertSame($admin->getKey(), $import->undone_by);
        $this->assertSame(2, $import->undone_rows);
        $this->assertSame(1, $import->kept_rows);
        $this->assertSame($import->successful_rows, $import->undone_rows + $import->kept_rows);
        $this->assertSame(ImportResource::STATUS_UNDONE, ImportResource::status($import));
        $this->assertSame(0, Lead::query()->where('import_id', $import->getKey())->count());
        $this->assertSame(2, Lead::onlyTrashed()->where('import_id', $import->getKey())->count());

        foreach ($leads as $lead) {
            $this->assertDatabaseHas('activity_log', [
                'description' => ActivityLogEvent::LeadDeleted->value,
                'subject_id' => $lead->getKey(),
                'causer_id' => $admin->getKey(),
            ]);
        }

        // The bell notice goes to whoever confirmed, in the locale they confirmed in (D-5), with a way back to the run.
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $manager->getKey())->count(), 'the launcher is not told: the notice answers the actor');
        $notice = $this->databaseNotification($admin, __('imports.notifications.undo_completed.title', [], $requestLocale));
        $this->assertSame(
            trans_choice('imports.notifications.undo_completed.undone', 2, ['count' => '2'], $requestLocale).' '.trans_choice('imports.notifications.undo_completed.kept', 1, ['count' => '1'], $requestLocale),
            $notice->data['body'] ?? null,
        );
        $this->assertSame(__('imports.actions.view', [], $requestLocale), $notice->data['actions'][0]['label'] ?? null);
        $url = (string) ($notice->data['actions'][0]['url'] ?? '');
        $this->assertSame(ImportResource::getUrl('view', ['record' => $import]), $url);
        $this->assertUrlInWorkspace(WorkspacePanel::Sales, $url);

        $this->assertSame(['undone' => 2, 'kept' => 1, 'updated' => 1, 'touched' => 0, 'forbidden' => 0, 'already_deleted' => 0], $this->undoneLedgerCounts($import, $admin));

        Livewire::actingAs($manager)
            ->test(ListImports::class)
            ->assertSee(__('imports.statuses.undone'))
            ->assertDontSeeHtml('wire:poll.');

        // The owner's page names who undid the run in the undo section; the summary names the launcher.
        Livewire::actingAs($manager)
            ->test(ViewImport::class, ['record' => $import->getRouteKey()])
            ->assertSee(__('imports.statuses.undone'))
            ->assertSee(__('imports.fields.undone_by'))
            ->assertSee($admin->name)
            ->assertActionHidden('undo');
    }

    #[Test]
    public function a_run_with_more_records_than_one_batch_is_undone_across_drains_and_shows_its_progress_in_between(): void
    {
        config()->set('queue.default', 'database');
        $manager = $this->salesManager();
        $this->travelTo(Carbon::parse('2026-10-20 09:00:00'));
        $import = $this->makeImport($manager, LeadImporter::class, 450);
        Lead::factory()->count(450)->create(['owner_id' => $manager->getKey(), 'import_id' => $import->getKey()]);
        $this->travel(10)->minutes();

        app(ImportUndoService::class)->request($import, $manager);

        $this->assertSame(1, DB::table('jobs')->count());

        // A drain stops after 50 seconds and the next minute's drain carries
        // on; one batch here stands for everything one cut drain got through.
        $this->drainOneJobLikeACutScheduler();

        $import->refresh();
        $this->assertSame(ImportUndoService::BATCH_SIZE, $import->undone_rows);
        $this->assertSame(0, $import->kept_rows);
        $this->assertNull($import->undone_at);
        $this->assertSame(ImportResource::STATUS_UNDOING, ImportResource::status($import));
        $this->assertSame(ImportUndoService::BATCH_SIZE, Lead::onlyTrashed()->count());
        $this->assertSame(1, DB::table('jobs')->count(), 'the next batch waits for the next drain');

        Livewire::actingAs($manager)
            ->test(ListImports::class)
            ->assertSee(__('imports.statuses.undoing'))
            ->assertSeeHtml('wire:poll.'.ImportsTable::POLL_INTERVAL);

        $output = $this->drainQueueLikeTheScheduler();

        $this->assertSame(0, DB::table('failed_jobs')->count(), $output);
        $this->assertSame(0, DB::table('jobs')->count(), $output);

        $import->refresh();
        $this->assertSame(450, $import->undone_rows);
        $this->assertSame(0, $import->kept_rows);
        $this->assertNotNull($import->undone_at);
        $this->assertSame(ImportResource::STATUS_UNDONE, ImportResource::status($import));
        $this->assertSame(0, Lead::query()->count());
        $this->assertSame(450, Lead::onlyTrashed()->count());
        $this->assertSame(['undone' => 450, 'kept' => 0, 'updated' => 0, 'touched' => 0, 'forbidden' => 0, 'already_deleted' => 0], $this->undoneLedgerCounts($import, $manager));
        $this->assertSame(1, ActivityLog::query()->where('description', ActivityLogEvent::ImportUndone->value)->count(), 'one ledger entry for the act, however many batches');

        Livewire::actingAs($manager)
            ->test(ListImports::class)
            ->assertDontSeeHtml('wire:poll.');
    }

    /**
     * The existing record the entity's CSV in entities() matches as a
     * duplicate — by email for leads and contacts, by name for accounts, by
     * title on the account for deals — owned by the importer so the «update»
     * strategy may update it.
     *
     * @param  class-string<Model&OwnedRecord>  $model
     */
    private function duplicateFor(string $model, User $owner): Model
    {
        if ($model === Deal::class) {
            $account = Account::factory()->create(['owner_id' => $owner->getKey(), 'name' => 'Horizon Trading']);

            return Deal::factory()->create(['owner_id' => $owner->getKey(), 'account_id' => $account->getKey(), 'title' => 'Dup Deal']);
        }

        return match ($model) {
            Lead::class => Lead::factory()->create(['owner_id' => $owner->getKey(), 'email' => 'dup.lead@example.com']),
            Contact::class => Contact::factory()->create(['owner_id' => $owner->getKey(), 'email' => 'dup.contact@example.com']),
            default => Account::factory()->create(['owner_id' => $owner->getKey(), 'name' => 'Dup Account']),
        };
    }

    /**
     * Imports the CSV through the entity's list page on the sync queue: the
     * run completes inside the request, every created record stamped with it.
     *
     * @param  class-string  $page
     */
    private function importThroughPanel(User $actor, string $page, string $csv, string $duplicateStrategy): Import
    {
        $headers = str_getcsv(explode("\n", $csv, 2)[0], ',', '"', '\\');
        $headers = array_values(array_filter($headers, static fn (?string $header): bool => $header !== null && $header !== ''));

        Livewire::actingAs($actor)
            ->test($page)
            ->callAction('import', data: [
                'file' => UploadedFile::fake()->createWithContent('rows.csv', $csv),
                'columnMap' => array_combine($headers, $headers),
                'duplicate_strategy' => $duplicateStrategy,
            ])
            ->assertHasNoActionErrors();

        return Import::query()->latest('id')->firstOrFail();
    }

    /** A status log on the lead at the current (travelled) moment, as the workflows write one. */
    private function statusLogOn(Lead $lead, User $changer): void
    {
        LeadStatusLog::query()->create([
            'lead_id' => $lead->getKey(),
            'from_status_id' => null,
            'to_status_id' => $lead->lead_status_id,
            'changed_by' => $changer->getKey(),
            'changed_at' => now(),
        ]);
    }

    /**
     * The undo dialog mounted on the run page: its heading, its description
     * as HTML and whether it offers a submit button. Filament renders the
     * modal as a Livewire partial, so the rendered page the test holds does
     * not carry it; the mounted action does.
     *
     * @return array{string, string, bool}
     */
    private function mountedUndoDialog(Testable $page): array
    {
        $instance = $page->instance();
        assert($instance instanceof ViewImport);

        $action = $instance->getMountedAction();
        $this->assertInstanceOf(Action::class, $action, 'the undo dialog is open');

        $description = $action->getModalDescription();

        return [
            (string) $action->getModalHeading(),
            $description instanceof Htmlable ? $description->toHtml() : (string) $description,
            $action->getModalSubmitAction() !== null,
        ];
    }

    /**
     * The counts the one `import.undone` ledger entry of the run carries,
     * written as the actor's; fails the test when there is none.
     *
     * @return array<string, int>
     */
    private function undoneLedgerCounts(Import $import, User $actor): array
    {
        $entry = ActivityLog::query()
            ->where('description', ActivityLogEvent::ImportUndone->value)
            ->where('subject_type', $import->getMorphClass())
            ->where('subject_id', $import->getKey())
            ->first();

        $this->assertInstanceOf(ActivityLog::class, $entry, 'no import.undone ledger entry for the run');
        $this->assertSame($actor->getKey(), $entry->causer_id);
        $this->assertSame($import->file_name, $entry->properties->get('subject_label'));

        $counts = [];

        foreach (['undone', 'kept', 'updated', 'touched', 'forbidden', 'already_deleted'] as $name) {
            $counts[$name] = (int) $entry->properties->get($name);
        }

        return $counts;
    }
}
