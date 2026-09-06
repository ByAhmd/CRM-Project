<?php

declare(strict_types=1);

use App\Enums\ActivityDirection;
use App\Enums\ActivityKind;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Activities (decision A-10): immutable events logged against leads,
 * contacts, accounts and deals.
 *
 * `kind` is copied from the activity type at creation so the timeline keeps
 * rendering correctly even if the type is later renamed or deactivated; it
 * is CHECK-constrained to ActivityKind and `direction` to ActivityDirection.
 * At least one of lead/contact/account/deal is required — enforced by
 * ActivityRecorder, not the schema. There is no `updated_at` and no soft
 * delete: ActivityAppendOnlyObserver refuses updates and deletion is a hard
 * delete gated by `activity.delete`.
 *
 * `task_id` and `note_id` are plain indexed columns for now: the tasks and
 * notes tables are created later, and their migrations add the foreign keys
 * (SET NULL) once those tables exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('activity_type_id')->constrained('activity_types')->restrictOnDelete();
            $table->string('kind', 32);
            $table->string('subject', 200);
            $table->text('body')->nullable();
            $table->string('direction', 32)->nullable();
            $table->dateTime('occurred_at');
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('outcome', 100)->nullable();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained('deals')->nullOnDelete();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->unsignedBigInteger('note_id')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('kind', 'activities_kind_index');
            $table->index('occurred_at', 'activities_occurred_at_index');
            $table->index(['lead_id', 'occurred_at'], 'activities_lead_id_occurred_at_index');
            $table->index(['contact_id', 'occurred_at'], 'activities_contact_id_occurred_at_index');
            $table->index(['account_id', 'occurred_at'], 'activities_account_id_occurred_at_index');
            $table->index(['deal_id', 'occurred_at'], 'activities_deal_id_occurred_at_index');
            $table->index('task_id', 'activities_task_id_index');
            $table->index('note_id', 'activities_note_id_index');
            $table->index(['owner_id', 'occurred_at'], 'activities_owner_id_occurred_at_index');
        });

        EnumCheck::apply('activities', 'kind', ActivityKind::class);
        EnumCheck::apply('activities', 'direction', ActivityDirection::class);
    }

    public function down(): void
    {
        if (Schema::hasTable('activities')) {
            EnumCheck::drop('activities', 'direction');
            EnumCheck::drop('activities', 'kind');
        }

        Schema::dropIfExists('activities');
    }
};
