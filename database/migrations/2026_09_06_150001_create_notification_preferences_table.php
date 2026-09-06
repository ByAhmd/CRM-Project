<?php

declare(strict_types=1);

use App\Enums\NotificationEvent;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user notification preferences (plan section 3.6, decision D-10).
 *
 * One row per user and event decides the channels: `database` is the in-app
 * bell (on unless switched off), `mail` is opt-in and only ever used when a
 * real mailer is configured. A missing row means the enum defaults, so the
 * table only holds what a user has actually chosen. Rows go with the user
 * (CASCADE) and are bookkeeping, not business entities: no soft deletes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('event', 64);
            $table->boolean('database')->default(true);
            $table->boolean('mail')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'event'], 'notification_preferences_user_id_event_unique');
            $table->index('event', 'notification_preferences_event_index');
        });

        EnumCheck::apply('notification_preferences', 'event', NotificationEvent::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
