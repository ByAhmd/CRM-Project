<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CustomFieldEntity;
use App\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;

/**
 * Seeds two bilingual starter templates (decision D-10): a follow-up for
 * leads and a thank-you for contacts.
 *
 * Idempotent on either name: a template that already exists under its
 * English or its Arabic name — active, renamed in one language, edited or
 * soft-deleted — is left untouched on the next deploy, and a missing one is
 * recreated.
 */
final class EmailTemplateSeeder extends Seeder
{
    /**
     * @return list<array{name_ar: string, name_en: string, subject_ar: string, subject_en: string, body_ar: string, body_en: string, entity: CustomFieldEntity, sort: int}>
     */
    public static function defaults(): array
    {
        return [
            [
                'name_ar' => 'متابعة العميل المحتمل',
                'name_en' => 'Lead follow-up',
                'subject_ar' => 'متابعة تواصلنا — {{lead.company_name}}',
                'subject_en' => 'Following up on our conversation — {{lead.company_name}}',
                'body_ar' => implode("\n", [
                    'عزيزي/عزيزتي {{lead.first_name}}،',
                    '',
                    'أشكرك على وقتك واهتمامك بخدمات {{organisation.name}}.',
                    'أود متابعة حديثنا ومعرفة ما إذا كانت لديك أي أسئلة يسعدني الإجابة عنها.',
                    '',
                    'يسعدني ترتيب موعد قصير يناسبك لمناقشة احتياجات {{lead.company_name}} بمزيد من التفصيل.',
                    '',
                    'مع أطيب التحيات،',
                    '{{user.name}}',
                    '{{organisation.name}}',
                    '{{user.email}}',
                ]),
                'body_en' => implode("\n", [
                    'Dear {{lead.first_name}},',
                    '',
                    'Thank you for your time and your interest in {{organisation.name}}.',
                    'I wanted to follow up on our conversation and see whether you have any questions I can help with.',
                    '',
                    'I would be glad to arrange a short call at a time that suits you to discuss the needs of {{lead.company_name}} in more detail.',
                    '',
                    'Kind regards,',
                    '{{user.name}}',
                    '{{organisation.name}}',
                    '{{user.email}}',
                ]),
                'entity' => CustomFieldEntity::Lead,
                'sort' => 1,
            ],
            [
                'name_ar' => 'شكراً لتواصلك',
                'name_en' => 'Thank you for contacting us',
                'subject_ar' => 'شكراً لتواصلك مع {{organisation.name}}',
                'subject_en' => 'Thank you for contacting {{organisation.name}}',
                'body_ar' => implode("\n", [
                    'عزيزي/عزيزتي {{contact.first_name}}،',
                    '',
                    'شكراً لتواصلك مع {{organisation.name}}. يسعدنا خدمة {{account.name}} ونحرص على الرد على استفساراتك في أقرب وقت.',
                    '',
                    'إن كانت لديك أي ملاحظات أو احتياجات إضافية، فلا تتردد في الرد على هذه الرسالة مباشرة.',
                    '',
                    'مع أطيب التحيات،',
                    '{{user.name}}',
                    '{{organisation.name}}',
                    '{{user.email}}',
                ]),
                'body_en' => implode("\n", [
                    'Dear {{contact.first_name}},',
                    '',
                    'Thank you for contacting {{organisation.name}}. We are glad to be working with {{account.name}} and will get back to your questions as soon as possible.',
                    '',
                    'If there is anything else you need, simply reply to this message.',
                    '',
                    'Kind regards,',
                    '{{user.name}}',
                    '{{organisation.name}}',
                    '{{user.email}}',
                ]),
                'entity' => CustomFieldEntity::Contact,
                'sort' => 2,
            ],
        ];
    }

    public function run(): void
    {
        foreach (self::defaults() as $default) {
            $exists = EmailTemplate::query()
                ->withTrashed()
                ->where(fn (Builder $query): Builder => $query
                    ->where('name_en', $default['name_en'])
                    ->orWhere('name_ar', $default['name_ar']))
                ->exists();

            if ($exists) {
                continue;
            }

            EmailTemplate::query()->create($default + ['is_active' => true]);
        }
    }
}
