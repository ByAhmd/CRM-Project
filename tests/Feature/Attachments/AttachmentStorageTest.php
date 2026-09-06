<?php

declare(strict_types=1);

namespace Tests\Feature\Attachments;

use App\Enums\ActivityLogEvent;
use App\Exceptions\Attachments\InvalidAttachmentException;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Services\Attachments\AttachmentStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * AttachmentStorage (module row 13, D-13): validation on the bytes, the
 * storage path, the row, the audit trail and the deletion rules.
 *
 * Every fixture file carries real bytes — a fake UploadedFile is empty and
 * would be sniffed as application/x-empty.
 */
final class AttachmentStorageTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

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
    public function stores_a_pdf_with_the_sniffed_type_the_uuid_path_and_an_upload_audit_row(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $upload = $this->upload('quote.pdf', $this->pdfBytes());

        $attachment = $this->storage()->store($upload, $lead, $rep, '  Signed quote  ');

        $this->assertSame('application/pdf', $attachment->mime_type);
        $this->assertSame('quote.pdf', $attachment->original_name);
        $this->assertSame('Signed quote', $attachment->description);
        $this->assertSame(strlen($this->pdfBytes()), $attachment->size);
        $this->assertSame((string) config('crm.attachments.disk'), $attachment->disk);
        $this->assertSame($rep->getKey(), $attachment->uploaded_by);
        $this->assertTrue(Str::isUuid($attachment->uuid));
        $this->assertSame(sprintf('crm/lead/%d/%s.pdf', $lead->getKey(), $attachment->uuid), $attachment->path);
        $this->assertTrue($attachment->attachable->is($lead));
        $this->assertTrue($lead->attachments()->whereKey($attachment->getKey())->exists());

        Storage::disk($attachment->disk)->assertExists($attachment->path);
        $this->assertSame($this->pdfBytes(), Storage::disk($attachment->disk)->get($attachment->path));

        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::AttachmentUploaded->value,
            'subject_type' => $attachment->getMorphClass(),
            'subject_id' => $attachment->getKey(),
            'causer_id' => $rep->getKey(),
        ]);
    }

    #[Test]
    public function the_type_and_the_extension_come_from_the_bytes_not_from_the_client_name(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $attachment = $this->storage()->store($this->upload('photo.pdf', $this->pngBytes()), $lead, $rep);

        $this->assertSame('image/png', $attachment->mime_type);
        $this->assertStringEndsWith('.png', $attachment->path);
        $this->assertSame('photo.pdf', $attachment->original_name);
        $this->assertTrue($attachment->isImage());
        $this->assertTrue($attachment->isInline());
        $this->assertSame('image', $attachment->mimeFamily());
    }

    #[Test]
    public function refuses_an_executable_renamed_as_a_pdf(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        try {
            $this->storage()->store($this->upload('invoice.pdf', $this->executableBytes()), $lead, $rep);
            $this->fail('An executable must be refused.');
        } catch (InvalidAttachmentException $exception) {
            $this->assertSame(__('attachments.validation.mime_not_allowed', ['mime' => 'application/x-dosexec']), $exception->getMessage());
        }

        $this->assertDatabaseCount('attachments', 0);
        $this->assertSame([], Storage::disk((string) config('crm.attachments.disk'))->allFiles());
    }

    /**
     * libmagic builds that predate OOXML report a .docx/.xlsx as a bare zip
     * (Herd's does): the package is accepted only when its
     * `[Content_Types].xml` declares the Word or Excel part.
     */
    #[Test]
    public function accepts_an_ooxml_package_reported_as_a_zip_when_its_content_types_declare_the_office_part(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $word = $this->storage()->store($this->upload('contract.docx', $this->ooxmlBytes('word/document.xml', 'wordprocessingml.document')), $lead, $rep);
        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $word->mime_type);
        $this->assertStringEndsWith('.docx', $word->path);
        $this->assertSame('document', $word->mimeFamily());

        $sheet = $this->storage()->store($this->upload('prices.xlsx', $this->ooxmlBytes('xl/workbook.xml', 'spreadsheetml.sheet')), $lead, $rep);
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $sheet->mime_type);
        $this->assertStringEndsWith('.xlsx', $sheet->path);
        $this->assertSame('spreadsheet', $sheet->mimeFamily());
    }

    #[Test]
    public function refuses_a_plain_zip_renamed_as_an_office_document(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $fixtures = [
            'archive.docx' => $this->plainZipBytes(),
            'mislabelled.xlsx' => $this->ooxmlBytes('word/document.xml', 'wordprocessingml.document'),
        ];

        foreach ($fixtures as $name => $bytes) {
            try {
                $this->storage()->store($this->upload($name, $bytes), $lead, $rep);
                $this->fail($name.' must be refused.');
            } catch (InvalidAttachmentException $exception) {
                $this->assertSame(__('attachments.validation.mime_not_allowed', ['mime' => 'application/zip']), $exception->getMessage(), $name);
            }
        }

        $this->assertDatabaseCount('attachments', 0);
    }

    #[Test]
    public function a_legacy_ole_document_is_accepted_under_the_type_its_name_claims(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $word = $this->storage()->store($this->upload('old.doc', $this->oleBytes()), $lead, $rep);
        $this->assertSame('application/msword', $word->mime_type);
        $this->assertStringEndsWith('.doc', $word->path);

        $sheet = $this->storage()->store($this->upload('old.xls', $this->oleBytes()), $lead, $rep);
        $this->assertSame('application/vnd.ms-excel', $sheet->mime_type);
        $this->assertStringEndsWith('.xls', $sheet->path);
    }

    #[Test]
    public function a_csv_body_is_stored_as_text_csv_whatever_alias_libmagic_uses(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $attachment = $this->storage()->store($this->upload('leads.csv', "name,email\nAhmed,ahmed@example.com\nSara,sara@example.com\n"), $lead, $rep);

        $this->assertSame('text/csv', $attachment->mime_type);
        $this->assertStringEndsWith('.csv', $attachment->path);
        $this->assertSame('spreadsheet', $attachment->mimeFamily());
        $this->assertFalse($attachment->isInline());
    }

    #[Test]
    public function refuses_an_empty_file(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $this->expectException(InvalidAttachmentException::class);

        $this->storage()->store($this->upload('empty.pdf', ''), $lead, $rep);
    }

    #[Test]
    public function refuses_a_file_over_the_configured_limit(): void
    {
        config()->set('crm.attachments.max_kb', 1);

        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $twoKilobytes = $this->pdfBytes().str_repeat('%', 2048);

        try {
            $this->storage()->store($this->upload('big.pdf', $twoKilobytes), $lead, $rep);
            $this->fail('An oversize file must be refused.');
        } catch (InvalidAttachmentException $exception) {
            $this->assertSame(__('attachments.validation.too_large', ['max' => '1 KB']), $exception->getMessage());
        }

        $this->assertDatabaseCount('attachments', 0);
        $this->assertSame([], Storage::disk((string) config('crm.attachments.disk'))->allFiles());
    }

    #[Test]
    public function accepts_a_temporary_path_on_the_disk_and_leaves_the_copy_to_the_caller(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $disk = Storage::disk((string) config('crm.attachments.disk'));
        $disk->put('tmp/01J0000000000000000000000.pdf', $this->pdfBytes());

        $attachment = $this->storage()->store('tmp/01J0000000000000000000000.pdf', $lead, $rep);

        $this->assertSame('application/pdf', $attachment->mime_type);
        $this->assertSame('01J0000000000000000000000.pdf', $attachment->original_name);
        $this->assertSame(strlen($this->pdfBytes()), $attachment->size);
        $disk->assertExists($attachment->path);
        $disk->assertExists('tmp/01J0000000000000000000000.pdf');
    }

    #[Test]
    public function refuses_a_temporary_path_that_is_not_on_the_disk(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        try {
            $this->storage()->store('tmp/missing.pdf', $lead, $rep);
            $this->fail('A missing temporary file must be refused.');
        } catch (InvalidAttachmentException $exception) {
            $this->assertSame(__('attachments.validation.file_missing'), $exception->getMessage());
        }

        $this->assertDatabaseCount('attachments', 0);
    }

    #[Test]
    public function the_original_name_is_reduced_to_a_safe_basename_of_at_most_255_characters(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $traversal = $this->storage()->store($this->upload("..\\..\\evil\x00 report .pdf", $this->pdfBytes()), $lead, $rep);
        $this->assertSame('evil report .pdf', $traversal->original_name);

        $long = $this->storage()->store($this->upload(str_repeat('a', 300).'.pdf', $this->pdfBytes()), $lead, $rep);
        $this->assertSame(255, mb_strlen($long->original_name));

        $arabic = $this->storage()->store($this->upload('عرض السعر.pdf', $this->pdfBytes()), $lead, $rep);
        $this->assertSame('عرض السعر.pdf', $arabic->original_name);
    }

    #[Test]
    public function the_storage_folder_names_the_subject_entity(): void
    {
        $rep = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey()]);
        $contact = Contact::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey()]);
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey()]);

        foreach ([['account', $account], ['contact', $contact], ['deal', $deal]] as [$entity, $subject]) {
            $attachment = $this->storage()->store($this->upload('file.txt', "plain text body\n"), $subject, $rep);

            $this->assertSame('text/plain', $attachment->mime_type);
            $this->assertFalse($attachment->isInline());
            $this->assertSame(sprintf('crm/%s/%d/%s.txt', $entity, $subject->getKey(), $attachment->uuid), $attachment->path);
            $this->assertTrue($subject->attachments()->whereKey($attachment->getKey())->exists());
        }
    }

    #[Test]
    public function a_soft_delete_keeps_the_file_and_a_restore_brings_the_row_back(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $attachment = $this->storage()->store($this->upload('quote.pdf', $this->pdfBytes()), $lead, $rep);
        $disk = Storage::disk($attachment->disk);

        $this->storage()->delete($attachment, $rep);

        $this->assertSoftDeleted('attachments', ['id' => $attachment->getKey()]);
        $disk->assertExists($attachment->path);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::AttachmentDeleted->value,
            'subject_id' => $attachment->getKey(),
            'causer_id' => $rep->getKey(),
        ]);

        $this->storage()->restore($attachment, $rep);

        $this->assertNull($attachment->refresh()->deleted_at);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::AttachmentRestored->value,
            'subject_id' => $attachment->getKey(),
            'causer_id' => $rep->getKey(),
        ]);
    }

    #[Test]
    public function a_force_delete_removes_the_file_and_the_row(): void
    {
        $rep = $this->salesRep();
        $admin = $this->superAdmin();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $attachment = $this->storage()->store($this->upload('quote.pdf', $this->pdfBytes()), $lead, $rep);
        $disk = Storage::disk($attachment->disk);
        $path = $attachment->path;

        $this->storage()->delete($attachment, $rep);
        $this->storage()->forceDelete($attachment, $admin);

        $this->assertDatabaseMissing('attachments', ['id' => $attachment->getKey()]);
        $disk->assertMissing($path);
    }

    #[Test]
    public function the_uuid_is_filled_on_creation_when_a_caller_leaves_it_empty(): void
    {
        $attachment = Attachment::factory()->create(['uuid' => '']);

        $this->assertTrue(Str::isUuid($attachment->uuid));
        $this->assertSame('uuid', $attachment->getRouteKeyName());
        $this->assertSame(route('attachments.download', $attachment->uuid), $attachment->downloadUrl());
    }

    #[Test]
    public function the_human_size_uses_one_decimal_at_most(): void
    {
        $this->assertSame('1.5 KB', Attachment::factory()->make(['size' => 1536])->humanSize());
        $this->assertSame('2 MB', Attachment::factory()->make(['size' => 2 * 1024 * 1024])->humanSize());
    }

    private function storage(): AttachmentStorage
    {
        return app(AttachmentStorage::class);
    }

    private function upload(string $clientName, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'crm-attachment-');
        $this->assertNotFalse($path);
        file_put_contents($path, $bytes);
        $this->scratchFiles[] = $path;

        return new UploadedFile($path, $clientName, null, null, true);
    }

    private function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    private function pngBytes(): string
    {
        return (string) base64_decode(self::PNG_1X1, true);
    }

    private function executableBytes(): string
    {
        return "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00".str_repeat("\x00", 100).'This program cannot be run in DOS mode.';
    }

    /** A minimal OOXML package: `[Content_Types].xml` declaring one main part, plus that part. */
    private function ooxmlBytes(string $part, string $kind): string
    {
        return $this->zipBytes([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/'.$part.'" ContentType="application/vnd.openxmlformats-officedocument.'.$kind.'.main+xml"/>'
                .'</Types>',
            $part => '<?xml version="1.0" encoding="UTF-8"?><root/>',
        ]);
    }

    private function plainZipBytes(): string
    {
        return $this->zipBytes(['readme.txt' => "not an office document\n"]);
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function zipBytes(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crm-zip-');
        $this->assertNotFalse($path);
        $this->scratchFiles[] = $path;

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::OVERWRITE));

        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();

        return (string) file_get_contents($path);
    }

    /** An OLE2 compound-file header (what Word 97 / Excel 97 files begin with). */
    private function oleBytes(): string
    {
        return "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00", 600);
    }
}
