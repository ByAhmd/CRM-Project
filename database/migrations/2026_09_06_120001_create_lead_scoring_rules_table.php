<?php

declare(strict_types=1);

use App\Enums\LeadScoringRuleKind;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead scoring rules (decision D-7): points per source, per status, per
 * filled field and per recent activity, summed and clamped to 0–100 by
 * LeadScoringService. Rules are configuration; leads carry the result.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_scoring_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 32);
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('field', 50)->nullable();
            $table->unsignedSmallInteger('within_days')->nullable();
            $table->smallInteger('points');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index('kind', 'lead_scoring_rules_kind_index');
            $table->index(['is_active', 'sort'], 'lead_scoring_rules_is_active_sort_index');
            $table->unique(['kind', 'reference_id', 'field', 'within_days'], 'lead_scoring_rules_rule_unique');
        });

        EnumCheck::apply('lead_scoring_rules', 'kind', LeadScoringRuleKind::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_scoring_rules');
    }
};
