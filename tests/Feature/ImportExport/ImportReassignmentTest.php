<?php

declare(strict_types=1);

namespace Tests\Feature\ImportExport;

use App\Enums\ActivityLogEvent;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Filament\Imports\AccountImporter;
use App\Filament\Imports\ContactImporter;
use App\Filament\Imports\DealImporter;
use App\Filament\Imports\LeadImporter;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Import;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\RecordAssignedNotification;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Jobs\ImportCsv;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * An import that names a new owner for an existing record is a reassignment
 * (D-4, A-20): it goes through RecordAssignmentService, so the change is
 * written to the ledger as `{entity}.assigned` with the importer as causer
 * and the new owner is notified — exactly as the panel's owner field and
 * assign actions do. Covered for the four importers.
 */
final class ImportReassignmentTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
    }

    /**
     * @return array<string, array{'lead'|'contact'|'account'|'deal'}>
     */
    public static function entities(): array
    {
        return [
            'lead' => ['lead'],
            'contact' => ['contact'],
            'account' => ['account'],
            'deal' => ['deal'],
        ];
    }

    /**
     * @param  'lead'|'contact'|'account'|'deal'  $entity
     */
    #[Test]
    #[DataProvider('entities')]
    public function an_import_that_changes_the_owner_of_an_existing_record_is_audited_and_notified_through_the_assignment_service(string $entity): void
    {
        Notification::fake();

        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $from = $this->salesRep($team);
        $to = $this->salesRep($team);
        $to->forceFill(['email' => 'new-owner@example.com'])->save();

        [$record, $row, $changedColumn, $changedValue] = $this->existingRecord($entity, $from);

        $import = $this->runImport($entity, $manager, [[...$row, 'owner' => 'NEW-OWNER@example.com']]);

        $this->assertSame(1, $import->successful_rows);
        $this->assertSame(0, $import->getFailedRowsCount());

        $record->refresh();
        $this->assertSame($to->getKey(), $record->getAttribute('owner_id'));
        $this->assertSame($changedValue, $record->getAttribute($changedColumn), 'the row\'s other values are saved with the reassignment');

        $logs = ActivityLog::query()->where('description', $this->assignedEvent($entity)->value)->get();
        $this->assertCount(1, $logs, 'one ownership change is one ledger entry');

        $log = $logs->firstOrFail();
        $this->assertSame($record->getKey(), (int) $log->subject_id);
        $this->assertSame($manager->getKey(), (int) $log->causer_id);
        $this->assertSame($from->getKey(), (int) $log->properties->get('previous_owner_id'));
        $this->assertSame($to->getKey(), (int) $log->properties->get('owner_id'));

        Notification::assertSentTo($to, RecordAssignedNotification::class);
        Notification::assertNotSentTo([$manager, $from], RecordAssignedNotification::class);
    }

    /**
     * @param  'lead'|'contact'|'account'|'deal'  $entity
     */
    #[Test]
    #[DataProvider('entities')]
    public function an_import_that_names_the_current_owner_writes_no_assignment_and_sends_no_notification(string $entity): void
    {
        Notification::fake();

        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $owner = $this->salesRep($team);
        $owner->forceFill(['email' => 'owner@example.com'])->save();

        [$record, $row, $changedColumn, $changedValue] = $this->existingRecord($entity, $owner);

        $import = $this->runImport($entity, $manager, [[...$row, 'owner' => 'owner@example.com']]);

        $this->assertSame(1, $import->successful_rows);
        $record->refresh();
        $this->assertSame($owner->getKey(), $record->getAttribute('owner_id'));
        $this->assertSame($changedValue, $record->getAttribute($changedColumn));
        $this->assertSame(0, ActivityLog::query()->where('description', $this->assignedEvent($entity)->value)->count());
        Notification::assertNothingSentTo($owner);
    }

    #[Test]
    public function a_new_record_created_for_another_owner_is_a_creation_not_a_reassignment(): void
    {
        Notification::fake();

        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $rep->forceFill(['email' => 'rep@example.com'])->save();

        $import = $this->runImport('lead', $manager, [
            ['first_name' => 'Fresh', 'last_name' => 'Lead', 'email' => 'fresh@example.com', 'owner' => 'rep@example.com'],
        ]);

        $this->assertSame(1, $import->successful_rows);
        $lead = Lead::query()->where('email_normalized', 'fresh@example.com')->firstOrFail();
        $this->assertSame($rep->getKey(), $lead->owner_id);
        $this->assertSame($manager->getKey(), $lead->created_by);
        $this->assertSame(0, ActivityLog::query()->where('description', ActivityLogEvent::LeadAssigned->value)->count());
    }

    #[Test]
    public function an_importer_who_may_update_but_not_assign_cannot_take_over_an_existing_record(): void
    {
        Notification::fake();

        $team = $this->makeTeam();
        $teammate = $this->salesRep($team);
        $lead = Lead::factory()->create(['owner_id' => $teammate->getKey(), 'email' => 'theirs@example.com', 'company_name' => 'Old Co']);
        $importer = $this->userWithPermissions($team, [
            Permission::LeadViewAny, Permission::LeadViewTeam, Permission::LeadUpdate, Permission::LeadImport,
        ]);

        $import = $this->runImport('lead', $importer, [
            ['first_name' => 'Taken', 'last_name' => 'Over', 'email' => 'theirs@example.com', 'company_name' => 'New Co', 'owner' => (string) $importer->email],
        ]);

        $this->assertSame(0, $import->successful_rows);
        $this->assertSame(
            __('imports.validation.owner_out_of_reach', ['value' => (string) $importer->email]),
            $this->failedImportReason($import, 'email', 'theirs@example.com'),
        );

        $lead->refresh();
        $this->assertSame($teammate->getKey(), $lead->owner_id, 'naming oneself is still a change of owner, which needs the assign verb (D-4)');
        $this->assertSame('Old Co', $lead->company_name, 'a refused row writes nothing');
        $this->assertSame(0, ActivityLog::query()->where('description', ActivityLogEvent::LeadAssigned->value)->count());
        Notification::assertNothingSent();
    }

    #[Test]
    public function a_reassignment_the_service_refuses_fails_the_row_and_rolls_the_whole_row_back(): void
    {
        Notification::fake();

        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $from = $this->salesRep($team);
        $to = $this->salesRep($team);
        $to->forceFill(['email' => 'new-owner@example.com'])->save();
        $lead = Lead::factory()->create(['owner_id' => $from->getKey(), 'email' => 'dup@example.com', 'company_name' => 'Old Co']);

        // The target passes the importer's reach check while the row is
        // filled, then stops being assignable before the service runs: the
        // service's own check refuses it.
        Lead::updating(function (Lead $updating) use ($lead, $to): void {
            if ((int) $updating->getKey() === (int) $lead->getKey()) {
                User::query()->whereKey($to->getKey())->update(['status' => UserStatus::Disabled->value]);
            }
        });

        $import = $this->runImport('lead', $manager, [
            ['first_name' => 'Dup', 'last_name' => 'Licate', 'email' => 'dup@example.com', 'company_name' => 'New Co', 'owner' => 'new-owner@example.com'],
        ]);

        $this->assertSame(0, $import->successful_rows);
        $this->assertSame(
            __('imports.validation.owner_out_of_reach', ['value' => 'new-owner@example.com']),
            $this->failedImportReason($import, 'email', 'dup@example.com'),
        );

        $lead->refresh();
        $this->assertSame($from->getKey(), $lead->owner_id);
        $this->assertSame('Old Co', $lead->company_name, 'the row\'s other values are rolled back with the refused reassignment');
        $this->assertSame(UserStatus::Active, $to->refresh()->status, 'everything the row wrote is rolled back to its savepoint');
        $this->assertSame(0, ActivityLog::query()->where('description', ActivityLogEvent::LeadAssigned->value)->count());
        Notification::assertNothingSent();
    }

    /**
     * An existing record of the entity owned by $owner, the update-strategy
     * row that matches it, and one other column the row changes.
     *
     * @param  'lead'|'contact'|'account'|'deal'  $entity
     * @return array{Model, array<string, string>, string, mixed}
     */
    private function existingRecord(string $entity, User $owner): array
    {
        return match ($entity) {
            'lead' => [
                Lead::factory()->create(['owner_id' => $owner->getKey(), 'email' => 'dup@example.com', 'company_name' => 'Old Co']),
                ['first_name' => 'Dup', 'last_name' => 'Licate', 'email' => 'dup@example.com', 'company_name' => 'New Co'],
                'company_name',
                'New Co',
            ],
            'contact' => [
                Contact::factory()->create(['owner_id' => $owner->getKey(), 'email' => 'dup@example.com', 'job_title' => 'Old title']),
                ['first_name' => 'Dup', 'last_name' => 'Licate', 'email' => 'dup@example.com', 'job_title' => 'New title'],
                'job_title',
                'New title',
            ],
            'account' => [
                Account::factory()->create(['owner_id' => $owner->getKey(), 'name' => 'Horizon Trading', 'website' => 'https://old.example.com']),
                ['name' => 'Horizon Trading', 'website' => 'https://new.example.com'],
                'website',
                'https://new.example.com',
            ],
            'deal' => [
                Deal::factory()->create([
                    'owner_id' => $owner->getKey(),
                    'account_id' => Account::factory()->create(['owner_id' => $owner->getKey(), 'name' => 'Horizon Trading'])->getKey(),
                    'title' => 'Server Supply',
                    'amount' => 100,
                ]),
                ['title' => 'Server Supply', 'account' => 'Horizon Trading', 'amount' => '250'],
                'amount',
                '250.00',
            ],
        };
    }

    /**
     * @param  'lead'|'contact'|'account'|'deal'  $entity
     */
    private function assignedEvent(string $entity): ActivityLogEvent
    {
        return ActivityLogEvent::from($entity.'.assigned');
    }

    /**
     * @param  'lead'|'contact'|'account'|'deal'  $entity
     * @param  list<array<string, string>>  $rows
     */
    private function runImport(string $entity, User $user, array $rows): Import
    {
        /** @var class-string<Importer> $importer */
        $importer = match ($entity) {
            'lead' => LeadImporter::class,
            'contact' => ContactImporter::class,
            'account' => AccountImporter::class,
            'deal' => DealImporter::class,
        };

        $import = new Import;
        $import->user()->associate($user);
        $import->file_name = $entity.'s.csv';
        $import->file_path = $entity.'s.csv';
        $import->importer = $importer;
        $import->total_rows = count($rows);
        $import->save();

        $headers = array_keys($rows[0]);

        (new ImportCsv($import, $rows, array_combine($headers, $headers), ['duplicate_strategy' => 'update']))->handle();

        $import->touch('completed_at');

        return $import->refresh();
    }
}
