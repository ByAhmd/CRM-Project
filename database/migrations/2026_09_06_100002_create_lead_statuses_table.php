<?php

declare(strict_types=1);

use App\Enums\BadgeColor;
use App\Enums\LeadStatusKind;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable lead statuses (decisions D-7, A-4).
 *
 * Statuses are rows the administrator names, colours and orders; `kind` is
 * the behaviour code reasons about (CHECK-constrained to LeadStatusKind).
 * Exactly one row is the default for new leads and exactly one row is of
 * kind `converted` — both invariants are enforced by LeadStatusService and
 * established by LeadStatusSeeder. No soft deletes: a status referenced by a
 * lead is protected by the RESTRICT foreign key on leads.lead_status_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->string('kind', 32);
            $table->string('color', 20)->default(BadgeColor::Gray->value);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique('name_ar', 'lead_statuses_name_ar_unique');
            $table->unique('name_en', 'lead_statuses_name_en_unique');
            $table->index('kind', 'lead_statuses_kind_index');
            $table->index(['is_active', 'sort'], 'lead_statuses_is_active_sort_index');
        });

        EnumCheck::apply('lead_statuses', 'kind', LeadStatusKind::class);
        EnumCheck::apply('lead_statuses', 'color', BadgeColor::class);
    }

    public function down(): void
    {
        if (Schema::hasTable('lead_statuses')) {
            EnumCheck::drop('lead_statuses', 'color');
            EnumCheck::drop('lead_statuses', 'kind');
        }

        Schema::dropIfExists('lead_statuses');
    }
};
