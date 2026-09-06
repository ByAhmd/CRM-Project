<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CustomFieldEntity;
use App\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailTemplate>
 */
final class EmailTemplateFactory extends Factory
{
    public function definition(): array
    {
        $suffix = fake()->unique()->numberBetween(1, 9999);

        return [
            'name_ar' => 'قالب '.$suffix,
            'name_en' => 'Template '.$suffix,
            'subject_ar' => 'مرحباً {{contact.first_name}}',
            'subject_en' => 'Hello {{contact.first_name}}',
            'body_ar' => "عزيزي {{contact.full_name}}،\n\nشكراً لتواصلك مع {{organisation.name}}.\n\n{{user.name}}",
            'body_en' => "Dear {{contact.full_name}},\n\nThank you for contacting {{organisation.name}}.\n\n{{user.name}}",
            'entity' => null,
            'is_active' => true,
            'sort' => 0,
        ];
    }

    public function forLeads(): static
    {
        return $this->state(fn (): array => [
            'entity' => CustomFieldEntity::Lead,
            'subject_ar' => 'متابعة {{lead.first_name}}',
            'subject_en' => 'Following up, {{lead.first_name}}',
            'body_ar' => "عزيزي {{lead.full_name}}،\n\nيسعدنا التواصل معك بخصوص {{lead.company_name}}.\n\n{{user.name}}",
            'body_en' => "Dear {{lead.full_name}},\n\nWe are glad to be in touch about {{lead.company_name}}.\n\n{{user.name}}",
        ]);
    }

    public function forContacts(): static
    {
        return $this->state(fn (): array => ['entity' => CustomFieldEntity::Contact]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
