<?php

declare(strict_types=1);

namespace Tests\Feature\Attachments;

use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Accounts\RelationManagers\AccountAttachmentsRelationManager;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Filament\Resources\Contacts\RelationManagers\ContactAttachmentsRelationManager;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Deals\RelationManagers\DealAttachmentsRelationManager;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Filament\Resources\Leads\RelationManagers\LeadAttachmentsRelationManager;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use App\Services\Attachments\AttachmentStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The attachments panel on the four subject pages (module row 13): upload
 * through the header action from a file Filament parked under the actor's
 * own `tmp/{user id}/` folder,
 * download, soft delete and restore — each behind AttachmentPolicy, the
 * panel itself behind the subject's `view`.
 */
final class AttachmentsRelationManagerTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    /** @var list<string> */
    private array $scratchFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();

        Storage::fake($this->diskName());
    }

    protected function tearDown(): void
    {
        foreach ($this->scratchFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function a_rep_uploads_a_file_from_the_view_page_and_the_temporary_copy_is_removed(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        Storage::disk($this->diskName())->put("tmp/{$rep->getKey()}/01J0000000000000000000000.pdf", $this->pdfBytes());

        $manager = Livewire::actingAs($rep)
            ->test(LeadAttachmentsRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->assertTableActionVisible('upload')
            ->callTableAction('upload', data: [
                'file' => ['pending' => "tmp/{$rep->getKey()}/01J0000000000000000000000.pdf"],
                'original_name' => 'quote.pdf',
                'description' => 'Signed quote',
            ])
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('attachments.notifications.uploaded'));

        $attachment = Attachment::query()->firstOrFail();

        $this->assertTrue($attachment->attachable->is($lead));
        $this->assertSame('quote.pdf', $attachment->original_name);
        $this->assertSame('application/pdf', $attachment->mime_type);
        $this->assertSame('Signed quote', $attachment->description);
        $this->assertSame($rep->getKey(), $attachment->uploaded_by);
        $this->assertSame(sprintf('crm/lead/%d/%s.pdf', $lead->getKey(), $attachment->uuid), $attachment->path);

        Storage::disk($this->diskName())->assertExists($attachment->path);
        Storage::disk($this->diskName())->assertMissing("tmp/{$rep->getKey()}/01J0000000000000000000000.pdf");

        $manager
            ->assertCanSeeTableRecords([$attachment])
            ->assertSee('quote.pdf')
            ->assertTableActionVisible('download', $attachment)
            ->assertTableActionVisible('delete', $attachment);
    }

    #[Test]
    public function the_upload_also_works_from_the_edit_page_and_falls_back_to_the_temporary_name(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        Storage::disk($this->diskName())->put("tmp/{$rep->getKey()}/photo.png", $this->pngBytes());

        Livewire::actingAs($rep)
            ->test(LeadAttachmentsRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => EditLead::class])
            ->callTableAction('upload', data: ['file' => ['pending' => "tmp/{$rep->getKey()}/photo.png"]])
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('attachments.notifications.uploaded'));

        $this->assertDatabaseHas('attachments', [
            'attachable_id' => $lead->getKey(),
            'original_name' => 'photo.png',
            'mime_type' => 'image/png',
            'description' => null,
        ]);
    }

    #[Test]
    public function a_file_that_fails_validation_is_refused_and_the_temporary_copy_is_still_removed(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        Storage::disk($this->diskName())->put("tmp/{$rep->getKey()}/malware.pdf", $this->executableBytes());

        Livewire::actingAs($rep)
            ->test(LeadAttachmentsRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->callTableAction('upload', data: ['file' => ['pending' => "tmp/{$rep->getKey()}/malware.pdf"]])
            ->assertNotified(__('attachments.validation.mime_not_allowed', ['mime' => 'application/x-dosexec']));

        $this->assertDatabaseCount('attachments', 0);
        Storage::disk($this->diskName())->assertMissing("tmp/{$rep->getKey()}/malware.pdf");
    }

    #[Test]
    public function a_path_outside_the_temporary_directory_is_refused_and_left_untouched(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $other = Lead::factory()->create(['owner_id' => $this->salesRep()->getKey()]);
        $existing = $this->storePdf($other, $rep);

        Livewire::actingAs($rep)
            ->test(LeadAttachmentsRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->callTableAction('upload', data: ['file' => ['pending' => $existing->path]])
            ->assertHasTableActionErrors(['file']);

        $this->assertDatabaseCount('attachments', 1);
        Storage::disk($this->diskName())->assertExists($existing->path);
    }

    #[Test]
    public function a_file_parked_by_another_user_is_refused_and_left_untouched(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        Storage::disk($this->diskName())->put("tmp/{$other->getKey()}/theirs.pdf", $this->pdfBytes());
        Storage::disk($this->diskName())->put('tmp/orphan.pdf', $this->pdfBytes());

        foreach (["tmp/{$other->getKey()}/theirs.pdf", 'tmp/orphan.pdf', "tmp/{$rep->getKey()}/../{$other->getKey()}/theirs.pdf"] as $path) {
            Livewire::actingAs($rep)
                ->test(LeadAttachmentsRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
                ->callTableAction('upload', data: ['file' => ['pending' => $path]])
                ->assertHasTableActionErrors(['file']);
        }

        $this->assertDatabaseCount('attachments', 0);
        Storage::disk($this->diskName())->assertExists("tmp/{$other->getKey()}/theirs.pdf");
        Storage::disk($this->diskName())->assertExists('tmp/orphan.pdf');
    }

    #[Test]
    public function nothing_can_be_uploaded_against_a_soft_deleted_subject(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $attachment = $this->storePdf($lead, $rep);
        $lead->delete();

        $this->actingAs($rep);
        $this->assertTrue(LeadAttachmentsRelationManager::canViewForRecord($lead, ViewLead::class));

        Livewire::actingAs($rep)
            ->test(LeadAttachmentsRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->assertCanSeeTableRecords([$attachment])
            ->assertTableActionHidden('upload')
            ->assertTableActionVisible('download', $attachment);

        $this->assertDatabaseCount('attachments', 1);
    }

    #[Test]
    public function a_missing_file_is_a_validation_error(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        Livewire::actingAs($rep)
            ->test(LeadAttachmentsRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->callTableAction('upload', data: ['description' => 'Nothing attached'])
            ->assertHasTableActionErrors(['file' => 'required']);

        $this->assertDatabaseCount('attachments', 0);
    }

    #[Test]
    public function the_uploader_soft_deletes_and_restores_their_own_file(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $attachment = $this->storePdf($lead, $rep);

        $manager = Livewire::actingAs($rep)
            ->test(LeadAttachmentsRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->callTableAction('delete', $attachment)
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('attachments.notifications.deleted'));

        $this->assertSoftDeleted('attachments', ['id' => $attachment->getKey()]);
        Storage::disk($this->diskName())->assertExists($attachment->path);

        $manager
            ->assertCanNotSeeTableRecords([$attachment])
            ->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$attachment])
            ->assertTableActionHidden('download', $attachment)
            ->callTableAction('restore', $attachment)
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('attachments.notifications.restored'));

        $this->assertNull($attachment->refresh()->deleted_at);
    }

    #[Test]
    public function a_role_without_the_delete_permission_cannot_delete_even_its_own_upload(): void
    {
        $owner = $this->salesRep();
        $support = $this->support();
        $lead = Lead::factory()->create(['owner_id' => $owner->getKey()]);
        $attachment = $this->storePdf($lead, $support);

        $this->assertFalse($support->can('delete', $attachment));

        Livewire::actingAs($support)
            ->test(LeadAttachmentsRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->assertCanSeeTableRecords([$attachment])
            ->assertTableActionVisible('upload')
            ->assertTableActionVisible('download', $attachment)
            ->assertTableActionHidden('delete', $attachment);

        $this->assertNull($attachment->refresh()->deleted_at);
    }

    #[Test]
    public function a_read_only_user_sees_the_files_and_may_only_download(): void
    {
        $owner = $this->salesRep();
        $readOnly = $this->readOnly();
        $lead = Lead::factory()->create(['owner_id' => $owner->getKey()]);
        $attachment = $this->storePdf($lead, $owner);

        $this->actingAs($readOnly);
        $this->assertTrue(LeadAttachmentsRelationManager::canViewForRecord($lead, ViewLead::class));

        Livewire::actingAs($readOnly)
            ->test(LeadAttachmentsRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->assertCanSeeTableRecords([$attachment])
            ->assertTableActionHidden('upload')
            ->assertTableActionVisible('download', $attachment)
            ->assertTableActionHidden('delete', $attachment);
    }

    #[Test]
    public function the_panel_is_hidden_from_a_user_who_cannot_view_the_subject(): void
    {
        $owner = $this->salesRep();
        $stranger = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $owner->getKey()]);

        $this->assertFalse(LeadAttachmentsRelationManager::canViewForRecord($lead, ViewLead::class));

        $this->actingAs($stranger);
        $this->assertFalse(LeadAttachmentsRelationManager::canViewForRecord($lead, ViewLead::class));
        $this->assertFalse(LeadAttachmentsRelationManager::canViewForRecord($lead, EditLead::class));

        $this->actingAs($owner);
        $this->assertTrue(LeadAttachmentsRelationManager::canViewForRecord($lead, ViewLead::class));
        $this->assertTrue(LeadAttachmentsRelationManager::canViewForRecord($lead, EditLead::class));
    }

    #[Test]
    public function every_subject_registers_its_own_panel_and_uploads_through_it(): void
    {
        $rep = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey()]);
        $contact = Contact::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey()]);
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey()]);

        $this->assertContains(LeadAttachmentsRelationManager::class, LeadResource::getRelations());
        $this->assertContains(ContactAttachmentsRelationManager::class, ContactResource::getRelations());
        $this->assertContains(AccountAttachmentsRelationManager::class, AccountResource::getRelations());
        $this->assertContains(DealAttachmentsRelationManager::class, DealResource::getRelations());

        $subjects = [
            ['contact', $contact, ContactAttachmentsRelationManager::class, ViewContact::class],
            ['account', $account, AccountAttachmentsRelationManager::class, ViewAccount::class],
            ['deal', $deal, DealAttachmentsRelationManager::class, ViewDeal::class],
        ];

        foreach ($subjects as [$entity, $subject, $manager, $page]) {
            $this->actingAs($rep);
            $this->assertTrue($manager::canViewForRecord($subject, $page), $entity);

            Storage::disk($this->diskName())->put("tmp/{$rep->getKey()}/{$entity}.pdf", $this->pdfBytes());

            Livewire::actingAs($rep)
                ->test($manager, ['ownerRecord' => $subject, 'pageClass' => $page])
                ->callTableAction('upload', data: ['file' => ['pending' => "tmp/{$rep->getKey()}/{$entity}.pdf"], 'original_name' => "{$entity}.pdf"])
                ->assertHasNoTableActionErrors();

            $this->assertDatabaseHas('attachments', [
                'attachable_type' => $subject->getMorphClass(),
                'attachable_id' => $subject->getKey(),
                'original_name' => "{$entity}.pdf",
            ]);
            $this->assertTrue($subject->attachments()->exists(), $entity);
        }
    }

    private function storePdf(Lead $lead, User $uploader): Attachment
    {
        $path = tempnam(sys_get_temp_dir(), 'crm-attachment-');
        $this->assertNotFalse($path);
        file_put_contents($path, $this->pdfBytes());
        $this->scratchFiles[] = $path;

        return app(AttachmentStorage::class)->store(new UploadedFile($path, 'quote.pdf', null, null, true), $lead, $uploader);
    }

    private function diskName(): string
    {
        return (string) config('crm.attachments.disk');
    }

    private function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    private function pngBytes(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
    }

    private function executableBytes(): string
    {
        return "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00".str_repeat("\x00", 100).'This program cannot be run in DOS mode.';
    }
}
