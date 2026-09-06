<?php

declare(strict_types=1);

use App\Enums\CustomFieldEntity;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bilingual email templates (decision D-10).
 *
 * A template carries a subject and a plain-text body in both languages; the
 * body holds merge tags (`{{contact.first_name}}`, `{{user.name}}`, …) that
 * EmailTemplateRenderer resolves against the recipient and the sender at send
 * time. `entity` narrows the template to leads or to contacts and is
 * constrained to CustomFieldEntity at the database; NULL means the template is
 * offered for both. Templates are business entities the sent activities point
 * back to by id, so they are soft-deleted rather than removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->string('subject_ar', 200);
            $table->string('subject_en', 200);
            $table->text('body_ar');
            $table->text('body_en');
            $table->string('entity', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique('name_ar', 'email_templates_name_ar_unique');
            $table->unique('name_en', 'email_templates_name_en_unique');
            $table->index('entity', 'email_templates_entity_index');
            $table->index(['is_active', 'sort'], 'email_templates_is_active_sort_index');
        });

        EnumCheck::apply('email_templates', 'entity', CustomFieldEntity::class);
    }

    public function down(): void
    {
        if (Schema::hasTable('email_templates')) {
            EnumCheck::drop('email_templates', 'entity');
        }

        Schema::dropIfExists('email_templates');
    }
};
