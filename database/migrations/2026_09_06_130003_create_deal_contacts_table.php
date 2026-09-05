<?php

declare(strict_types=1);

use App\Enums\DealContactRole;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The people involved in a deal beyond its primary contact (decision D-6).
 *
 * Each contact appears once per deal with an optional role (CHECK-constrained
 * to DealContactRole). Rows follow their deal and their contact when either is
 * permanently removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('role', 32)->nullable();
            $table->timestamps();

            $table->unique(['deal_id', 'contact_id'], 'deal_contacts_deal_id_contact_id_unique');
        });

        EnumCheck::apply('deal_contacts', 'role', DealContactRole::class);
    }

    public function down(): void
    {
        if (Schema::hasTable('deal_contacts')) {
            EnumCheck::drop('deal_contacts', 'role');
        }

        Schema::dropIfExists('deal_contacts');
    }
};
