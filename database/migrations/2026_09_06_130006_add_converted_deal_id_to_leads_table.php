<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The deal a lead was converted into (decision D-7). Deferred from the leads
 * migration because deals did not exist yet; stamped by the conversion
 * workflow only (guarded on the Lead model).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->foreignId('converted_deal_id')->nullable()->after('converted_contact_id')->constrained('deals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('converted_deal_id');
        });
    }
};
