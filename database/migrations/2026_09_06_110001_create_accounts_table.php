<?php

declare(strict_types=1);

use App\Enums\AccountType;
use App\Enums\CompanySize;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts — companies and customers as one entity (decision D-6).
 *
 * A prospect becomes a customer on its first won deal (customer_since). Owner
 * and visibility follow D-4; normalised email/phone back duplicate detection
 * (A-11). Industry is a configurable lookup and cannot be deleted while in use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('type', 32)->default(AccountType::Prospect->value);
            $table->foreignId('industry_id')->nullable()->constrained('industries')->restrictOnDelete();
            $table->string('size', 32)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('email_normalized', 190)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('phone_normalized', 20)->nullable();
            $table->string('address_line', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('region', 100)->nullable();
            $table->char('country', 2)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('parent_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->date('customer_since')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('name', 'accounts_name_index');
            $table->index('type', 'accounts_type_index');
            $table->index('email_normalized', 'accounts_email_normalized_index');
            $table->index('phone_normalized', 'accounts_phone_normalized_index');
            $table->index('country', 'accounts_country_index');
            $table->index(['owner_id', 'type'], 'accounts_owner_id_type_index');
        });

        EnumCheck::apply('accounts', 'type', AccountType::class);
        EnumCheck::apply('accounts', 'size', CompanySize::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
