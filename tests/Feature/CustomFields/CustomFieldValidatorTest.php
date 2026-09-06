<?php

declare(strict_types=1);

namespace Tests\Feature\CustomFields;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Services\CustomFields\CustomFieldValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The rules a definition produces (decision D-9) — the single place the form,
 * the value service and the importers all take their validation from.
 */
final class CustomFieldValidatorTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    #[Test]
    public function every_type_carries_the_rules_of_its_storage(): void
    {
        $expected = [
            CustomFieldType::Text->value => ['nullable', 'string', 'max:500'],
            CustomFieldType::Textarea->value => ['nullable', 'string', 'max:'.CustomFieldValidator::TEXT_LENGTH],
            CustomFieldType::Number->value => ['nullable', 'integer'],
            CustomFieldType::Decimal->value => ['nullable', 'numeric', 'decimal:0,4'],
            CustomFieldType::Date->value => ['nullable', 'date', 'date_format:Y-m-d'],
            CustomFieldType::DateTime->value => ['nullable', 'date', 'date_format:Y-m-d H:i,Y-m-d H:i:s,Y-m-d'],
            CustomFieldType::Boolean->value => ['nullable', 'boolean'],
            CustomFieldType::Url->value => ['nullable', 'string', 'url', 'max:500'],
            CustomFieldType::Email->value => ['nullable', 'string', 'email', 'max:500'],
        ];

        foreach ($expected as $type => $rules) {
            $field = CustomField::factory()->ofType(CustomFieldType::from($type))->make();

            $this->assertSame($rules, CustomFieldValidator::rulesFor($field), 'rules of '.$type);
        }
    }

    #[Test]
    public function a_choice_is_restricted_to_its_options_and_a_multiple_choice_is_an_array_of_them(): void
    {
        $select = CustomField::factory()->select(['low', 'high'])->make();
        $multi = CustomField::factory()->multiSelect(['low', 'high'])->make();

        $selectRules = CustomFieldValidator::rulesFor($select);
        $multiRules = CustomFieldValidator::rulesFor($multi);

        $this->assertSame(['nullable', 'string'], array_slice($selectRules, 0, 2));
        $this->assertSame('in:"low","high"', (string) $selectRules[2]);
        $this->assertSame(['nullable', 'array'], array_slice($multiRules, 0, 2));
        $this->assertSame('in:"low","high"', (string) $multiRules[2]);

        $this->assertTrue(Validator::make(['v' => ['low']], ['v' => $multiRules])->passes());
        $this->assertFalse(Validator::make(['v' => ['low', 'unknown']], ['v' => $multiRules])->passes());
        $this->assertFalse(Validator::make(['v' => 'unknown'], ['v' => $selectRules])->passes());
    }

    #[Test]
    public function a_required_field_is_required_and_a_required_toggle_only_has_to_be_present(): void
    {
        $text = CustomField::factory()->required()->make();
        $toggle = CustomField::factory()->ofType(CustomFieldType::Boolean)->required()->make();

        $this->assertSame('required', CustomFieldValidator::rulesFor($text)[0]);
        $this->assertSame('present', CustomFieldValidator::rulesFor($toggle)[0]);

        $this->assertFalse(Validator::make(['v' => null], ['v' => CustomFieldValidator::rulesFor($text)])->passes());
        $this->assertTrue(Validator::make(['v' => false], ['v' => CustomFieldValidator::rulesFor($toggle)])->passes());
    }

    #[Test]
    public function numeric_bounds_become_min_and_max_rules_and_step_stays_out_of_them(): void
    {
        $field = CustomField::factory()->ofType(CustomFieldType::Number)->make([
            'validation' => ['min' => 1, 'max' => 10, 'step' => 2],
        ]);

        $rules = CustomFieldValidator::rulesFor($field);

        $this->assertSame(['nullable', 'integer', 'min:1', 'max:10'], $rules);
        $this->assertTrue(Validator::make(['v' => 5], ['v' => $rules])->passes());
        $this->assertFalse(Validator::make(['v' => 11], ['v' => $rules])->passes());
    }

    #[Test]
    public function text_bounds_become_length_and_pattern_rules(): void
    {
        $field = CustomField::factory()->make([
            'validation' => ['min_length' => 3, 'max_length' => 20, 'regex' => '^[A-Z]{3}$'],
        ]);

        $rules = CustomFieldValidator::rulesFor($field);

        $this->assertSame(['nullable', 'string', 'max:20', 'min:3', 'regex:/^[A-Z]{3}$/'], $rules);
        $this->assertTrue(Validator::make(['v' => 'ABC'], ['v' => $rules])->passes());
        $this->assertFalse(Validator::make(['v' => 'abc'], ['v' => $rules])->passes());
        $this->assertFalse(Validator::make(['v' => 'AB'], ['v' => $rules])->passes());
    }

    #[Test]
    public function a_pattern_is_always_compiled_inside_the_same_delimiter(): void
    {
        $plain = CustomField::factory()->make(['validation' => ['regex' => '^[a-z]+$']]);
        $delimited = CustomField::factory()->make(['validation' => ['regex' => '/^[a-z]+$/']]);
        $modified = CustomField::factory()->make(['validation' => ['regex' => '#^[a-z]+$#iu']]);

        $this->assertContains('regex:/^[a-z]+$/', CustomFieldValidator::rulesFor($plain));
        $this->assertContains('regex:/^[a-z]+$/', CustomFieldValidator::rulesFor($delimited));

        // The delimiters an administrator writes are stripped with whatever
        // trails them, so no modifier reaches the compiled pattern.
        $this->assertContains('regex:/^[a-z]+$/', CustomFieldValidator::rulesFor($modified));
        $this->assertFalse(Validator::make(['v' => 'ABC'], ['v' => CustomFieldValidator::rulesFor($modified)])->passes());
    }

    #[Test]
    public function a_configured_maximum_length_never_exceeds_the_storage_column(): void
    {
        $text = CustomField::factory()->make(['validation' => ['max_length' => 900]]);
        $textarea = CustomField::factory()->ofType(CustomFieldType::Textarea)->make(['validation' => ['max_length' => 900]]);
        $huge = CustomField::factory()->ofType(CustomFieldType::Textarea)->make(['validation' => ['max_length' => 65535]]);

        $this->assertSame(500, CustomFieldValidator::maxLength($text));
        $this->assertSame(900, CustomFieldValidator::maxLength($textarea));

        // A TEXT column holds 65 535 *bytes*, and `max` counts characters.
        $this->assertSame(CustomFieldValidator::TEXT_LENGTH, CustomFieldValidator::maxLength($huge));
        $this->assertSame(16383, CustomFieldValidator::TEXT_LENGTH);
    }

    #[Test]
    public function the_messages_name_the_field_the_way_the_user_reads_it(): void
    {
        $field = CustomField::factory()->required()->make(['label_ar' => 'الميزانية', 'label_en' => 'Budget']);

        $messages = CustomFieldValidator::messagesFor($field, 'budget');
        $attributes = CustomFieldValidator::attributesFor($field, 'budget');

        $this->assertSame(__('custom_fields.validation.value_required', ['label' => $field->display_label]), $messages['budget.required']);
        $this->assertSame(__('custom_fields.validation.value_invalid', ['label' => $field->display_label]), $messages['budget.max']);
        $this->assertSame($field->display_label, $attributes['budget']);
        $this->assertStringContainsString($field->display_label, $messages['budget.required']);
    }

    #[Test]
    public function every_type_names_the_constraints_it_can_express(): void
    {
        $this->assertSame(['min', 'max', 'step'], CustomFieldValidator::allowedConstraints(CustomFieldType::Number));
        $this->assertSame(['min', 'max', 'step'], CustomFieldValidator::allowedConstraints(CustomFieldType::Decimal));
        $this->assertSame(['min_length', 'max_length', 'regex'], CustomFieldValidator::allowedConstraints(CustomFieldType::Text));
        $this->assertSame(['min_length', 'max_length', 'regex'], CustomFieldValidator::allowedConstraints(CustomFieldType::Email));
        $this->assertSame([], CustomFieldValidator::allowedConstraints(CustomFieldType::Boolean));
        $this->assertSame([], CustomFieldValidator::allowedConstraints(CustomFieldType::Date));
        $this->assertSame([], CustomFieldValidator::allowedConstraints(CustomFieldType::Select));
    }
}
