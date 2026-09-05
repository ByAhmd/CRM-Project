<?php

declare(strict_types=1);

use App\Enums\DealStatus;
use App\Enums\ForecastCategory;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deals (decisions D-6, D-8).
 *
 * A deal moves through the stages of one pipeline; `stage_id`, `status` and
 * the close columns are stamped by DealStageWorkflow only (guarded on the
 * model). `status` is derived from the stage kind and CHECK-constrained to
 * DealStatus; `forecast_category` to ForecastCategory. `amount` is computed
 * from the line items when there are any and entered by hand otherwise;
 * `currency` defaults to the organisation currency (SAR). `probability` is a
 * per-deal override of the stage probability. The account is nullable only
 * for deals converted from a lead without a company (D-6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 150);
            $table->foreignId('account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('pipeline_id')->constrained('pipelines')->restrictOnDelete();
            $table->foreignId('stage_id')->constrained('pipeline_stages')->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default(DealStatus::Open->value);
            $table->decimal('amount', 14, 2)->default(0);
            $table->char('currency', 3)->default('SAR');
            $table->unsignedTinyInteger('probability')->nullable();
            $table->date('expected_close_date')->nullable();
            $table->string('forecast_category', 32)->default(ForecastCategory::Pipeline->value);
            $table->foreignId('lead_source_id')->nullable()->constrained('lead_sources')->restrictOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('close_reason_id')->nullable()->constrained('deal_close_reasons')->restrictOnDelete();
            $table->timestamp('won_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->text('lost_notes')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('title', 'deals_title_index');
            $table->index(['pipeline_id', 'stage_id'], 'deals_pipeline_id_stage_id_index');
            $table->index(['owner_id', 'status'], 'deals_owner_id_status_index');
            $table->index('status', 'deals_status_index');
            $table->index('expected_close_date', 'deals_expected_close_date_index');
            $table->index('forecast_category', 'deals_forecast_category_index');
            $table->index('won_at', 'deals_won_at_index');
            $table->index('last_activity_at', 'deals_last_activity_at_index');
        });

        EnumCheck::apply('deals', 'status', DealStatus::class);
        EnumCheck::apply('deals', 'forecast_category', ForecastCategory::class);

        DB::statement('ALTER TABLE `deals` ADD CONSTRAINT `deals_probability_check` CHECK (`probability` IS NULL OR `probability` BETWEEN 0 AND 100)');
    }

    public function down(): void
    {
        if (Schema::hasTable('deals')) {
            EnumCheck::drop('deals', 'forecast_category');
            EnumCheck::drop('deals', 'status');
        }

        // The probability CHECK is dropped together with the table.
        Schema::dropIfExists('deals');
    }
};
