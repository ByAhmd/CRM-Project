<?php

declare(strict_types=1);

use App\Enums\UserStatus;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * CRM columns on the Laravel users table (decisions D-4, D-5, D-11).
 *
 * - password becomes nullable: accounts are invite-only and the invitee sets it.
 * - status gates authentication (pending until the invitation is accepted).
 * - locale persists the language switch per user; timezone is a per-user
 *   preference that falls back to the organisation setting.
 * - team_id is the single team membership visibility resolves against.
 * - MFA columns back Filament's app and email authentication providers.
 * - soft deletes keep audit history intact when an account is removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('password')->nullable()->change();
            $table->string('phone', 30)->nullable()->after('email');
            $table->string('locale', 5)->nullable()->after('phone');
            $table->string('timezone', 64)->nullable()->after('locale');
            $table->string('status', 32)->default(UserStatus::Pending->value)->after('timezone');
            $table->foreignId('team_id')->nullable()->after('status')->constrained('teams')->nullOnDelete();
            $table->timestamp('last_login_at')->nullable()->after('remember_token');
            $table->text('app_authentication_secret')->nullable()->after('last_login_at');
            $table->text('app_authentication_recovery_codes')->nullable()->after('app_authentication_secret');
            $table->boolean('has_email_authentication')->default(false)->after('app_authentication_recovery_codes');
            $table->softDeletes();

            $table->index('status', 'users_status_index');
        });

        EnumCheck::apply('users', 'status', UserStatus::class);
    }

    /**
     * Invited users who never accepted have no password, and MODIFY to NOT NULL
     * fails on the first NULL ("Invalid use of NULL value") after the other
     * columns are already gone — MySQL DDL is not transactional, so the table
     * would be left half rolled back. Those rows receive an unusable random
     * hash first: nobody knows it, so the account stays unusable until a
     * password reset, exactly as it was while the invitation was pending.
     */
    public function down(): void
    {
        DB::table('users')
            ->whereNull('password')
            ->update(['password' => Hash::make(Str::random(64))]);

        EnumCheck::drop('users', 'status');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_status_index');
            $table->dropConstrainedForeignId('team_id');
            $table->dropSoftDeletes();
            $table->dropColumn([
                'phone', 'locale', 'timezone', 'status', 'last_login_at',
                'app_authentication_secret', 'app_authentication_recovery_codes', 'has_email_authentication',
            ]);
            $table->string('password')->nullable(false)->change();
        });
    }
};
