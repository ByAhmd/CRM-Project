<?php

declare(strict_types=1);

namespace App\Services\CustomFields;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use Illuminate\Validation\Rule;

/**
 * The rules one definition produces (decision D-9).
 *
 * This is the single translation of a definition into Laravel rules, so the
 * form component, the value service, the importer column and any future
 * consumer enforce exactly the same thing — the server-side rules are the
 * authority, the Filament component only mirrors them for the user.
 *
 * The rules of a type are its storage shape plus the constraints the
 * administrator configured; a constraint a type cannot express is refused by
 * CustomFieldService and pruned by CustomFieldObserver, so nothing here has to
 * defend against one.
 *
 * `step` is the exception: it shapes the number input, not the stored value,
 * so it produces no rule.
 */
final class CustomFieldValidator
{
    /** The longest a `value_string` column can hold — MySQL counts VARCHAR in characters. */
    public const int STRING_LENGTH = 500;

    /**
     * The longest a `value_text` column can hold, in *characters*.
     *
     * MySQL sizes TEXT in bytes (65 535) while Laravel's `max` rule counts
     * characters, so the ceiling is the worst case of utf8mb4: four bytes per
     * character. An Arabic note is two bytes per character, so this is the
     * bound that keeps a long paste a form error instead of a 1406 truncation
     * error on insert.
     */
    public const int TEXT_LENGTH = 16383;

    /** The longest `regex` constraint an administrator may configure. */
    public const int PATTERN_LENGTH = 200;

    /** Textual date formats accepted for a date field (the picker sends the first). */
    public const string DATE_FORMATS = 'Y-m-d';

    /** Textual formats accepted for a date and time field (the picker sends the first). */
    public const string DATETIME_FORMATS = 'Y-m-d H:i,Y-m-d H:i:s,Y-m-d';

    /** Rule names whose failure is reported as "invalid value" with the field's label. */
    private const array INVALID_RULES = [
        'string', 'integer', 'numeric', 'decimal', 'date', 'date_format', 'boolean',
        'in', 'url', 'email', 'array', 'min', 'max', 'regex',
    ];

    /**
     * The constraint keys a type can express — the form shows these inputs,
     * the service refuses the others.
     *
     * @return list<string>
     */
    public static function allowedConstraints(CustomFieldType $type): array
    {
        return match ($type) {
            CustomFieldType::Number, CustomFieldType::Decimal => ['min', 'max', 'step'],
            CustomFieldType::Text, CustomFieldType::Textarea, CustomFieldType::Url, CustomFieldType::Email => ['min_length', 'max_length', 'regex'],
            CustomFieldType::Boolean, CustomFieldType::Date, CustomFieldType::DateTime, CustomFieldType::Select, CustomFieldType::MultiSelect => [],
        };
    }

    /**
     * The rules a value of this definition must satisfy.
     *
     * @return list<mixed>
     */
    public static function rulesFor(CustomField $field): array
    {
        $rules = [self::presenceRule($field)];

        foreach (self::typeRules($field) as $rule) {
            $rules[] = $rule;
        }

        foreach (self::constraintRules($field) as $rule) {
            $rules[] = $rule;
        }

        return $rules;
    }

    /**
     * Messages naming the field the way the user sees it — a custom field has
     * no translation key, its label is data (D-9).
     *
     * @return array<string, string>
     */
    public static function messagesFor(CustomField $field, string $attribute): array
    {
        $label = $field->display_label;

        $messages = [
            $attribute.'.required' => (string) __('custom_fields.validation.value_required', ['label' => $label]),
            $attribute.'.present' => (string) __('custom_fields.validation.value_required', ['label' => $label]),
        ];

        foreach (self::INVALID_RULES as $rule) {
            $messages[$attribute.'.'.$rule] = (string) __('custom_fields.validation.value_invalid', ['label' => $label]);
        }

        return $messages;
    }

    /**
     * @return array<string, string>
     */
    public static function attributesFor(CustomField $field, string $attribute): array
    {
        return [$attribute => $field->display_label];
    }

    /** The longest value the field's storage column accepts. */
    public static function maxLength(CustomField $field): int
    {
        $ceiling = $field->type === CustomFieldType::Textarea ? self::TEXT_LENGTH : self::STRING_LENGTH;
        $configured = $field->constraint('max_length');

        if (is_numeric($configured) && (int) $configured > 0) {
            return min((int) $configured, $ceiling);
        }

        return $ceiling;
    }

    /**
     * A boolean is `present` rather than `required` when it is mandatory: a
     * toggle always carries a value and `false` is one of them.
     */
    private static function presenceRule(CustomField $field): string
    {
        if (! $field->is_required) {
            return 'nullable';
        }

        return $field->type === CustomFieldType::Boolean ? 'present' : 'required';
    }

    /**
     * @return list<mixed>
     */
    private static function typeRules(CustomField $field): array
    {
        return match ($field->type) {
            CustomFieldType::Text => ['string', 'max:'.self::maxLength($field)],
            CustomFieldType::Textarea => ['string', 'max:'.self::maxLength($field)],
            CustomFieldType::Url => ['string', 'url', 'max:'.self::maxLength($field)],
            CustomFieldType::Email => ['string', 'email', 'max:'.self::maxLength($field)],
            CustomFieldType::Number => ['integer'],
            CustomFieldType::Decimal => ['numeric', 'decimal:0,4'],
            CustomFieldType::Date => ['date', 'date_format:'.self::DATE_FORMATS],
            CustomFieldType::DateTime => ['date', 'date_format:'.self::DATETIME_FORMATS],
            CustomFieldType::Boolean => ['boolean'],
            CustomFieldType::Select => ['string', Rule::in($field->optionValues())],
            CustomFieldType::MultiSelect => ['array', Rule::in($field->optionValues())],
        };
    }

    /**
     * @return list<string>
     */
    private static function constraintRules(CustomField $field): array
    {
        $rules = [];
        $type = $field->type;

        if ($type === CustomFieldType::Number || $type === CustomFieldType::Decimal) {
            $min = $field->constraint('min');
            $max = $field->constraint('max');

            if (is_numeric($min)) {
                $rules[] = 'min:'.self::number($min);
            }

            if (is_numeric($max)) {
                $rules[] = 'max:'.self::number($max);
            }

            return $rules;
        }

        if (in_array($type, [CustomFieldType::Text, CustomFieldType::Textarea, CustomFieldType::Url, CustomFieldType::Email], true)) {
            $min = $field->constraint('min_length');
            $regex = $field->constraint('regex');

            if (is_numeric($min) && (int) $min > 0) {
                $rules[] = 'min:'.(int) $min;
            }

            if (is_string($regex) && $regex !== '') {
                $rules[] = 'regex:'.self::delimited($regex);
            }
        }

        return $rules;
    }

    /** A configured bound as a rule parameter: whole numbers stay whole. */
    private static function number(int|float|string $value): string
    {
        $number = (float) $value;

        return $number === floor($number) && abs($number) < PHP_INT_MAX
            ? (string) (int) $number
            : (string) $number;
    }

    /**
     * The pattern as a rule parameter, always inside the same delimiter.
     *
     * An administrator may write the pattern with or without delimiters, but
     * the delimiters they wrote are never honoured: they are stripped together
     * with anything trailing them, so no modifier (`u`, `i`, and least of all a
     * pathological one) can be smuggled into the compiled pattern. What is
     * compiled is exactly the body, between `/` and `/`.
     *
     * CustomFieldService compiles the same string before storing the
     * constraint, so a pattern that reaches here is one PCRE accepted.
     */
    public static function delimited(string $pattern): string
    {
        $body = self::body($pattern);

        return '/'.str_replace('/', '\/', str_replace('\/', '/', $body)).'/';
    }

    /** The pattern without a caller-supplied delimiter pair and its modifiers. */
    private static function body(string $pattern): string
    {
        return preg_match('/^([\/#~])(.*)\1[a-zA-Z]*$/s', $pattern, $matches) === 1
            ? $matches[2]
            : $pattern;
    }
}
