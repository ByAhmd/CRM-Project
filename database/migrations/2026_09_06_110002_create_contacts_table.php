<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contacts — people, each belonging to at most one account (decision D-6).
 *
 * One contact per account may be primary (ContactService keeps the rule).
 * preferred_locale drives the language of outbound mail (D-5, D-10). The
 * origin lead (lead_id) is added by the leads migration once that table exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('job_title', 100)->nullable();
            $table->string('department', 100)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('email_normalized', 190)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('mobile', 30)->nullable();
            $table->string('phone_normalized', 20)->nullable();
            $table->string('preferred_locale', 5)->nullable();
            $table->string('linkedin_url', 255)->nullable();
            $table->string('address_line', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('region', 100)->nullable();
            $table->char('country', 2)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['last_name', 'first_name'], 'contacts_last_name_first_name_index');
            $table->index('email_normalized', 'contacts_email_normalized_index');
            $table->index('phone_normalized', 'contacts_phone_normalized_index');
            $table->index('owner_id', 'contacts_owner_id_index');
            $table->index(['account_id', 'is_primary'], 'contacts_account_id_is_primary_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
