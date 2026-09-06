<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attachments (module row 13, decision D-13).
 *
 * A file uploaded against a lead, contact, account or deal (polymorphic
 * `attachable`). The row carries the storage coordinates (`disk`, `path`),
 * the sanitised original name, the server-sniffed MIME type and the size in
 * bytes; `uuid` is the public download route key so a numeric id can never
 * be enumerated. Soft-deleted rows keep their file (D-13); the file is
 * removed by AttachmentObserver on a force delete only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36);
            $table->string('attachable_type', 100);
            $table->unsignedBigInteger('attachable_id');
            $table->string('disk', 30);
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size');
            $table->string('description', 255)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('uuid', 'attachments_uuid_unique');
            $table->index(['attachable_type', 'attachable_id'], 'attachments_attachable_index');
            $table->index('uploaded_by', 'attachments_uploaded_by_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
