<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notes (decision A-10).
 *
 * Authored, timestamped, pinnable plain-text notes on a lead, a contact, an
 * account or a deal. At least one subject column is required — enforced by
 * NoteService, since a CHECK across nullable FKs would refuse the SET NULL
 * the subject's deletion triggers. A contact or deal note also carries the
 * account so the account page lists it. Edits are kept in place with
 * `edited_at` stamped and the body diff written to the audit ledger by
 * LogsActivity; soft deletes keep the row restorable (D-13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table): void {
            $table->id();
            $table->text('body');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained('deals')->nullOnDelete();
            $table->boolean('is_pinned')->default(false);
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('author_id', 'notes_author_id_index');
            $table->index('lead_id', 'notes_lead_id_index');
            $table->index('contact_id', 'notes_contact_id_index');
            $table->index('account_id', 'notes_account_id_index');
            $table->index('deal_id', 'notes_deal_id_index');
            $table->index('is_pinned', 'notes_is_pinned_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
