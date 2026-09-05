<?php

declare(strict_types=1);

use App\Enums\LeadPriority;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leads (decision D-7).
 *
 * Status is a configurable row whose kind drives the workflow; qualification
 * and conversion are stamped by the workflows only (LeadObserver guards the
 * columns). Score is computed from lead_scoring_rules with an optional manual
 * override. converted_deal_id is added by the deals migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('company_name', 150)->nullable();
            $table->string('job_title', 100)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('email_normalized', 190)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('phone_normalized', 20)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('address_line', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('region', 100)->nullable();
            $table->char('country', 2)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->foreignId('lead_source_id')->nullable()->constrained('lead_sources')->restrictOnDelete();
            $table->foreignId('lead_status_id')->constrained('lead_statuses')->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('priority', 32)->default(LeadPriority::Medium->value);
            $table->unsignedSmallInteger('score')->default(0);
            $table->unsignedSmallInteger('score_override')->nullable();
            $table->timestamp('scored_at')->nullable();
            $table->timestamp('qualified_at')->nullable();
            $table->foreignId('qualified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('converted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('converted_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('converted_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->timestamp('last_activity_at')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['last_name', 'first_name'], 'leads_last_name_first_name_index');
            $table->index('company_name', 'leads_company_name_index');
            $table->index('email_normalized', 'leads_email_normalized_index');
            $table->index('phone_normalized', 'leads_phone_normalized_index');
            $table->index(['owner_id', 'lead_status_id'], 'leads_owner_id_lead_status_id_index');
            $table->index('priority', 'leads_priority_index');
            $table->index('converted_at', 'leads_converted_at_index');
            $table->index('last_activity_at', 'leads_last_activity_at_index');
        });

        EnumCheck::apply('leads', 'priority', LeadPriority::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
