<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Enums\ActivityLogEvent;
use App\Enums\CustomFieldEntity;
use App\Filament\Resources\EmailTemplates\EmailTemplateResource;
use App\Filament\Resources\EmailTemplates\Pages\CreateEmailTemplate;
use App\Filament\Resources\EmailTemplates\Pages\EditEmailTemplate;
use App\Filament\Resources\EmailTemplates\Pages\ListEmailTemplates;
use App\Filament\Resources\EmailTemplates\Schemas\EmailTemplateForm;
use App\Models\EmailTemplate;
use App\Services\Email\MergeTags;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The email template resource (decision D-10): administrators manage the
 * templates, sales roles only read them, every save is audited as a lookup
 * change, and the seeded starters are created once.
 */
final class EmailTemplateResourceTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->usePanel();
    }

    #[Test]
    public function admins_manage_templates_while_sales_roles_only_list_them(): void
    {
        $admin = $this->admin();
        $manager = $this->salesManager();
        $rep = $this->salesRep();
        $template = EmailTemplate::factory()->create();

        $this->actingAs($admin)->get(EmailTemplateResource::getUrl('index'))->assertOk();
        $this->actingAs($admin)->get(EmailTemplateResource::getUrl('create'))->assertOk();
        $this->actingAs($admin)->get(EmailTemplateResource::getUrl('edit', ['record' => $template]))->assertOk();

        $this->actingAs($manager)->get(EmailTemplateResource::getUrl('index'))->assertOk();
        $this->actingAs($manager)->get(EmailTemplateResource::getUrl('create'))->assertForbidden();
        $this->actingAs($manager)->get(EmailTemplateResource::getUrl('edit', ['record' => $template]))->assertForbidden();

        $this->actingAs($rep)->get(EmailTemplateResource::getUrl('index'))->assertOk();
        $this->actingAs($rep)->get(EmailTemplateResource::getUrl('create'))->assertForbidden();

        Livewire::actingAs($manager)
            ->test(ListEmailTemplates::class)
            ->assertCanSeeTableRecords([$template])
            ->assertTableActionHidden('edit', $template)
            ->assertTableActionHidden('delete', $template);

        $this->assertTrue($manager->can('viewAny', EmailTemplate::class));
        $this->assertFalse($manager->can('create', EmailTemplate::class));
        $this->assertFalse($manager->can('update', $template));
        $this->assertFalse($manager->can('delete', $template));
        $this->assertFalse($manager->can('reorder', EmailTemplate::class));
        $this->assertTrue($admin->can('delete', $template));
        $this->assertTrue($admin->can('restore', $template));
        $this->assertTrue($admin->can('reorder', EmailTemplate::class));
        $this->assertFalse($admin->can('forceDelete', $template));
        $this->assertFalse($admin->can('forceDeleteAny', EmailTemplate::class));
    }

    #[Test]
    public function a_template_is_created_with_both_languages_and_audited(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(CreateEmailTemplate::class)
            ->fillForm([
                'name_ar' => 'ترحيب',
                'name_en' => 'Welcome',
                'entity' => CustomFieldEntity::Lead->value,
                'subject_ar' => 'أهلاً {{lead.first_name}}',
                'subject_en' => 'Welcome {{lead.first_name}}',
                'body_ar' => "مرحباً {{lead.full_name}}\nسطر ثانٍ",
                'body_en' => "Hello {{lead.full_name}}\nSecond line",
                'is_active' => true,
                'sort' => 3,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $template = EmailTemplate::query()->where('name_en', 'Welcome')->firstOrFail();

        $this->assertSame(CustomFieldEntity::Lead, $template->entity);
        $this->assertSame("Hello {{lead.full_name}}\nSecond line", $template->body_en);
        $this->assertSame('أهلاً {{lead.first_name}}', $template->subjectFor('ar'));
        $this->assertSame('Welcome {{lead.first_name}}', $template->subjectFor('en'));
        $this->assertTrue($template->is_active);
        $this->assertSame(3, $template->sort);
        $this->assertTrue($template->appliesTo(CustomFieldEntity::Lead));
        $this->assertFalse($template->appliesTo(CustomFieldEntity::Contact));
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupCreated->value,
            'subject_type' => EmailTemplate::class,
            'subject_id' => $template->getKey(),
        ]);
    }

    #[Test]
    public function both_names_subjects_and_bodies_are_required_and_names_are_unique(): void
    {
        $admin = $this->admin();
        EmailTemplate::factory()->create(['name_ar' => 'ترحيب', 'name_en' => 'Welcome']);

        Livewire::actingAs($admin)
            ->test(CreateEmailTemplate::class)
            ->fillForm(['name_ar' => 'ترحيب', 'name_en' => '', 'subject_ar' => '', 'subject_en' => '', 'body_ar' => '', 'body_en' => ''])
            ->call('create')
            ->assertHasFormErrors([
                'name_ar' => 'unique',
                'name_en' => 'required',
                'subject_ar' => 'required',
                'subject_en' => 'required',
                'body_ar' => 'required',
                'body_en' => 'required',
            ]);

        Livewire::actingAs($admin)
            ->test(CreateEmailTemplate::class)
            ->fillForm(['name_ar' => '', 'name_en' => 'Welcome', 'subject_ar' => str_repeat('a', 201)])
            ->call('create')
            ->assertHasFormErrors(['name_ar' => 'required', 'name_en' => 'unique', 'subject_ar' => 'max']);
    }

    #[Test]
    public function a_deleted_namesake_must_be_restored_rather_than_recreated(): void
    {
        $admin = $this->admin();
        $deleted = EmailTemplate::factory()->create(['name_ar' => 'ترحيب', 'name_en' => 'Welcome']);
        $deleted->delete();

        Livewire::actingAs($admin)
            ->test(CreateEmailTemplate::class)
            ->fillForm([
                'name_ar' => 'ترحيب',
                'name_en' => 'Welcome',
                'subject_ar' => 'موضوع',
                'subject_en' => 'Subject',
                'body_ar' => 'نص',
                'body_en' => 'Body',
            ])
            ->call('create')
            ->assertHasFormErrors(['name_ar', 'name_en']);

        $this->assertSame(1, EmailTemplate::query()->withTrashed()->count());
    }

    #[Test]
    public function the_entity_options_are_limited_to_leads_and_contacts(): void
    {
        $admin = $this->admin();

        $this->assertSame([CustomFieldEntity::Lead->value, CustomFieldEntity::Contact->value], array_keys(EmailTemplateForm::entityOptions()));
        $this->assertSame(CustomFieldEntity::Lead->getLabel(), EmailTemplateForm::entityOptions()[CustomFieldEntity::Lead->value]);

        Livewire::actingAs($admin)
            ->test(CreateEmailTemplate::class)
            ->fillForm([
                'name_ar' => 'حساب',
                'name_en' => 'Account template',
                'entity' => CustomFieldEntity::Account->value,
                'subject_ar' => 'موضوع',
                'subject_en' => 'Subject',
                'body_ar' => 'نص',
                'body_en' => 'Body',
            ])
            ->call('create')
            ->assertHasFormErrors(['entity']);

        $this->assertDatabaseMissing('email_templates', ['name_en' => 'Account template']);

        Livewire::actingAs($admin)
            ->test(CreateEmailTemplate::class)
            ->fillForm([
                'name_ar' => 'عام',
                'name_en' => 'Generic',
                'entity' => null,
                'subject_ar' => 'موضوع',
                'subject_en' => 'Subject',
                'body_ar' => 'نص',
                'body_en' => 'Body',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $generic = EmailTemplate::query()->where('name_en', 'Generic')->firstOrFail();

        $this->assertNull($generic->entity);
        $this->assertTrue($generic->appliesTo(CustomFieldEntity::Lead));
        $this->assertTrue($generic->appliesTo(CustomFieldEntity::Contact));
    }

    #[Test]
    public function the_body_helper_lists_the_merge_tags_of_the_chosen_entity(): void
    {
        $lead = EmailTemplateForm::bodyHelper(CustomFieldEntity::Lead->value);
        $contact = EmailTemplateForm::bodyHelper(CustomFieldEntity::Contact->value);
        $any = EmailTemplateForm::bodyHelper(null);

        $this->assertStringContainsString('{{lead.company_name}}', $lead);
        $this->assertStringNotContainsString('{{contact.first_name}}', $lead);
        $this->assertStringContainsString('{{contact.first_name}}', $contact);
        $this->assertStringContainsString('{{account.name}}', $contact);
        $this->assertStringNotContainsString('{{lead.first_name}}', $contact);
        $this->assertStringContainsString('{{lead.first_name}}', $any);
        $this->assertStringContainsString('{{contact.first_name}}', $any);

        foreach (array_keys(MergeTags::available(CustomFieldEntity::Lead)) as $tag) {
            $this->assertStringContainsString('{{'.$tag.'}}', $lead);
        }
    }

    #[Test]
    public function an_admin_edits_deletes_and_restores_a_template_and_each_step_is_audited(): void
    {
        $admin = $this->admin();
        $template = EmailTemplate::factory()->create(['name_en' => 'Welcome', 'name_ar' => 'ترحيب']);

        Livewire::actingAs($admin)
            ->test(EditEmailTemplate::class, ['record' => $template->getRouteKey()])
            ->fillForm(['name_en' => 'Welcome aboard', 'subject_en' => 'Welcome aboard {{contact.first_name}}', 'is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $template->refresh();

        $this->assertSame('Welcome aboard', $template->name_en);
        $this->assertSame('Welcome aboard {{contact.first_name}}', $template->subject_en);
        $this->assertFalse($template->is_active);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupUpdated->value,
            'subject_type' => EmailTemplate::class,
            'subject_id' => $template->getKey(),
        ]);

        Livewire::actingAs($admin)
            ->test(EditEmailTemplate::class, ['record' => $template->getRouteKey()])
            ->callAction('delete');

        $this->assertSoftDeleted('email_templates', ['id' => $template->getKey()]);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupDeleted->value,
            'subject_id' => $template->getKey(),
        ]);

        Livewire::actingAs($admin)
            ->test(EditEmailTemplate::class, ['record' => $template->getRouteKey()])
            ->callAction('restore');

        $this->assertNull($template->refresh()->deleted_at);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupRestored->value,
            'subject_id' => $template->getKey(),
        ]);
    }

    #[Test]
    public function bulk_delete_only_removes_records_the_actor_may_delete(): void
    {
        $admin = $this->admin();
        $manager = $this->salesManager();
        $first = EmailTemplate::factory()->create();
        $second = EmailTemplate::factory()->create();

        // The bulk delete is not even offered to a role without email_template.delete (deleteAny).
        Livewire::actingAs($manager)
            ->test(ListEmailTemplates::class)
            ->assertTableBulkActionHidden('delete');

        $this->assertFalse($manager->can('deleteAny', EmailTemplate::class));
        $this->assertNull($first->refresh()->deleted_at);
        $this->assertNull($second->refresh()->deleted_at);

        Livewire::actingAs($admin)
            ->test(ListEmailTemplates::class)
            ->callTableBulkAction('delete', [$first]);

        $this->assertSoftDeleted('email_templates', ['id' => $first->getKey()]);
        $this->assertNull($second->refresh()->deleted_at);
    }

    #[Test]
    public function the_display_name_and_the_localised_texts_follow_the_locale(): void
    {
        $template = EmailTemplate::factory()->create([
            'name_ar' => 'ترحيب',
            'name_en' => 'Welcome',
            'subject_ar' => 'أهلاً',
            'subject_en' => 'Hello',
            'body_ar' => '',
            'body_en' => 'English body',
        ]);

        app()->setLocale('ar');
        $this->assertSame('ترحيب', $template->display_name);

        app()->setLocale('en');
        $this->assertSame('Welcome', $template->display_name);

        app()->setLocale('ar');

        $this->assertSame('أهلاً', $template->subjectFor('ar'));
        $this->assertSame('Hello', $template->subjectFor('en'));
        // A blank Arabic body falls back to the English one so a message is never empty.
        $this->assertSame('English body', $template->bodyFor('ar'));
    }

    #[Test]
    public function the_scopes_offer_active_templates_for_the_entity_or_for_both(): void
    {
        $lead = EmailTemplate::factory()->forLeads()->create();
        $contact = EmailTemplate::factory()->forContacts()->create();
        $any = EmailTemplate::factory()->create();
        $inactive = EmailTemplate::factory()->forLeads()->inactive()->create();

        $forLeads = EmailTemplate::query()->active()->forEntity(CustomFieldEntity::Lead)->pluck('id')->all();
        $forContacts = EmailTemplate::query()->active()->forEntity(CustomFieldEntity::Contact)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$lead->getKey(), $any->getKey()], $forLeads);
        $this->assertEqualsCanonicalizing([$contact->getKey(), $any->getKey()], $forContacts);
        $this->assertNotContains($inactive->getKey(), $forLeads);
    }

    #[Test]
    public function the_seeder_creates_the_two_starters_idempotently_and_respects_renamed_rows(): void
    {
        $this->seed(EmailTemplateSeeder::class);
        $this->seed(EmailTemplateSeeder::class);

        $this->assertSame(2, EmailTemplate::query()->count());

        $lead = EmailTemplate::query()->where('name_en', 'Lead follow-up')->firstOrFail();
        $contact = EmailTemplate::query()->where('name_en', 'Thank you for contacting us')->firstOrFail();

        $this->assertSame('متابعة العميل المحتمل', $lead->name_ar);
        $this->assertSame(CustomFieldEntity::Lead, $lead->entity);
        $this->assertStringContainsString('{{lead.first_name}}', $lead->body_en);
        $this->assertStringContainsString('{{lead.first_name}}', $lead->body_ar);
        $this->assertStringContainsString('{{user.name}}', $lead->body_en);
        $this->assertTrue($lead->is_active);
        $this->assertSame(1, $lead->sort);

        $this->assertSame('شكراً لتواصلك', $contact->name_ar);
        $this->assertSame(CustomFieldEntity::Contact, $contact->entity);
        $this->assertStringContainsString('{{contact.first_name}}', $contact->body_en);
        $this->assertStringContainsString('{{organisation.name}}', $contact->subject_ar);
        $this->assertSame(2, $contact->sort);

        foreach ([$lead, $contact] as $template) {
            $this->assertLessThanOrEqual(200, mb_strlen($template->subject_ar));
            $this->assertLessThanOrEqual(200, mb_strlen($template->subject_en));
        }

        // A renamed (in one language) or deleted starter is not recreated.
        $lead->update(['name_en' => 'Follow-up call']);
        $contact->delete();

        $this->seed(EmailTemplateSeeder::class);

        $this->assertSame(2, EmailTemplate::query()->withTrashed()->count());
        $this->assertDatabaseMissing('email_templates', ['name_en' => 'Lead follow-up']);
    }
}
