<?php

declare(strict_types=1);

namespace Tests\Feature\Attachments;

use App\Enums\ActivityLogEvent;
use App\Enums\UserStatus;
use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Models\Lead;
use App\Models\User;
use App\Services\Attachments\AttachmentStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsUploadBytes;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The download route and AttachmentPolicy (module row 13, D-4, D-13): a file
 * is streamed only to a user who may view its subject, never to a guest,
 * never to an account that may no longer sign in, never once the row is
 * soft-deleted; every download is audited.
 */
final class AttachmentDownloadTest extends TestCase
{
    use BuildsUploadBytes;
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

        Storage::fake((string) config('crm.attachments.disk'));
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
    public function the_owner_of_the_lead_streams_a_pdf_inline_and_the_download_is_audited(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $attachment = $this->storePdf($lead, $rep, 'quote.pdf');

        $response = $this->actingAs($rep)->get($attachment->downloadUrl());

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('quote.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame($this->pdfBytes(), $response->streamedContent());

        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::AttachmentDownloaded->value,
            'subject_type' => $attachment->getMorphClass(),
            'subject_id' => $attachment->getKey(),
            'causer_id' => $rep->getKey(),
        ]);

        $properties = (array) json_decode((string) $this->firstAuditProperties($attachment), true);
        $this->assertSame('quote.pdf', $properties['subject_label']);
        $this->assertSame($rep->getKey(), $properties['downloaded_by']);
    }

    #[Test]
    public function a_document_that_is_not_an_image_or_a_pdf_is_served_as_a_download(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $attachment = $this->store($lead, $rep, 'notes.txt', "plain text body\n");

        $response = $this->actingAs($rep)->get($attachment->downloadUrl());

        $response->assertOk();
        $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
    }

    #[Test]
    public function a_rep_who_cannot_see_the_lead_is_forbidden(): void
    {
        $owner = $this->salesRep();
        $stranger = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $owner->getKey()]);
        $attachment = $this->storePdf($lead, $owner);

        $this->actingAs($stranger)->get($attachment->downloadUrl())->assertForbidden();

        $this->assertDatabaseMissing('activity_log', ['description' => ActivityLogEvent::AttachmentDownloaded->value]);
    }

    #[Test]
    public function support_and_read_only_users_who_read_everything_may_download(): void
    {
        $owner = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $owner->getKey()]);
        $attachment = $this->storePdf($lead, $owner);

        $this->actingAs($this->support())->get($attachment->downloadUrl())->assertOk();
        $this->actingAs($this->readOnly())->get($attachment->downloadUrl())->assertOk();
    }

    #[Test]
    public function a_manager_reaches_a_team_members_file_but_not_another_teams(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $member = $this->salesRep($team);
        $outsider = $this->salesRep($this->makeTeam('Jeddah Team', 'فريق جدة'));

        $mine = $this->storePdf(Lead::factory()->create(['owner_id' => $member->getKey()]), $member);
        $theirs = $this->storePdf(Lead::factory()->create(['owner_id' => $outsider->getKey()]), $outsider);

        $this->actingAs($manager)->get($mine->downloadUrl())->assertOk();
        $this->actingAs($manager)->get($theirs->downloadUrl())->assertForbidden();
    }

    #[Test]
    public function a_soft_deleted_attachment_is_not_found(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $attachment = $this->storePdf($lead, $rep);

        app(AttachmentStorage::class)->delete($attachment, $rep);

        $this->actingAs($rep)->get($attachment->downloadUrl())->assertNotFound();
    }

    #[Test]
    public function a_row_whose_file_left_the_disk_is_not_found(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $attachment = $this->storePdf($lead, $rep);

        Storage::disk($attachment->disk)->delete($attachment->path);

        $this->actingAs($rep)->get($attachment->downloadUrl())->assertNotFound();
    }

    #[Test]
    public function an_unknown_uuid_is_not_found(): void
    {
        $this->actingAs($this->salesRep())
            ->get(route('attachments.download', '00000000-0000-0000-0000-000000000000'))
            ->assertNotFound();
    }

    #[Test]
    public function a_guest_is_redirected_to_the_panel_login(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $attachment = $this->storePdf($lead, $rep);

        $this->get($attachment->downloadUrl())->assertRedirect(route('filament.admin.auth.login'));
    }

    #[Test]
    public function a_disabled_account_with_a_live_session_is_forbidden_like_in_the_panel(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $attachment = $this->storePdf($lead, $rep);

        $rep->forceFill(['status' => UserStatus::Disabled])->save();

        $this->actingAs($rep)->get($attachment->downloadUrl())->assertForbidden();

        $this->assertFalse($rep->fresh()?->can('download', $attachment));
        $this->assertDatabaseMissing('activity_log', ['description' => ActivityLogEvent::AttachmentDownloaded->value]);
    }

    #[Test]
    public function view_and_download_follow_the_subject_visibility(): void
    {
        $owner = $this->salesRep();
        $stranger = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $owner->getKey()]);
        $attachment = $this->storePdf($lead, $owner);

        foreach (['view', 'download'] as $ability) {
            $this->assertTrue($owner->can($ability, $attachment), $ability);
            $this->assertTrue($this->support()->can($ability, $attachment), $ability);
            $this->assertTrue($this->readOnly()->can($ability, $attachment), $ability);
            $this->assertFalse($stranger->can($ability, $attachment), $ability);
        }
    }

    #[Test]
    public function create_requires_the_permission_and_a_visible_subject(): void
    {
        $owner = $this->salesRep();
        $stranger = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $owner->getKey()]);

        $this->assertTrue($owner->can('create', [Attachment::class, $lead]));
        $this->assertTrue($this->support()->can('create', [Attachment::class, $lead]));
        $this->assertFalse($stranger->can('create', [Attachment::class, $lead]));
        $this->assertFalse($this->readOnly()->can('create', [Attachment::class, $lead]));

        // A soft-deleted subject is frozen until restored (D-13): nobody may attach to it.
        $trashedLead = Lead::factory()->create(['owner_id' => $owner->getKey()]);
        $trashedLead->delete();

        $this->assertFalse($owner->can('create', [Attachment::class, $trashedLead]));
        $this->assertFalse($this->admin()->can('create', [Attachment::class, $trashedLead]));
    }

    #[Test]
    public function delete_and_restore_belong_to_the_uploader_or_to_whoever_may_update_the_subject(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $owner = $this->salesRep($team);
        $colleague = $this->salesRep($team);
        $support = $this->support();
        $lead = Lead::factory()->create(['owner_id' => $owner->getKey()]);

        $bySupport = $this->storePdf($lead, $support, 'by-support.pdf');
        $byOwner = $this->storePdf($lead, $owner, 'by-owner.pdf');

        foreach (['delete', 'restore'] as $ability) {
            // The uploader, when the role carries attachment.delete.
            $this->assertTrue($owner->can($ability, $byOwner), $ability);
            // Whoever may update the lead: the team manager.
            $this->assertTrue($manager->can($ability, $bySupport), $ability);
            // Support uploaded it but the role lacks attachment.delete.
            $this->assertFalse($support->can($ability, $bySupport), $ability);
            // A colleague at own level neither uploaded it nor reaches the lead.
            $this->assertFalse($colleague->can($ability, $byOwner), $ability);
            // The owner did not upload it but may update the lead.
            $this->assertTrue($owner->can($ability, $bySupport), $ability);
        }
    }

    #[Test]
    public function permanent_deletion_is_never_granted_through_the_policy(): void
    {
        $owner = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $owner->getKey()]);
        $attachment = $this->storePdf($lead, $owner);

        $this->assertFalse($owner->can('forceDelete', $attachment));
        $this->assertFalse($this->superAdmin()->can('forceDelete', $attachment));
        $this->assertFalse($this->superAdmin()->can('forceDeleteAny', Attachment::class));
    }

    private function storePdf(Lead $lead, User $uploader, string $name = 'quote.pdf'): Attachment
    {
        return $this->store($lead, $uploader, $name, $this->pdfBytes());
    }

    private function store(Lead $lead, User $uploader, string $name, string $bytes): Attachment
    {
        $path = tempnam(sys_get_temp_dir(), 'crm-attachment-');
        $this->assertNotFalse($path);
        file_put_contents($path, $bytes);
        $this->scratchFiles[] = $path;

        return app(AttachmentStorage::class)->store(new UploadedFile($path, $name, null, null, true), $lead, $uploader);
    }

    private function firstAuditProperties(Attachment $attachment): ?string
    {
        $row = ActivityLog::query()
            ->where('description', ActivityLogEvent::AttachmentDownloaded->value)
            ->where('subject_id', $attachment->getKey())
            ->first();

        return $row === null ? null : (string) json_encode($row->properties);
    }
}
