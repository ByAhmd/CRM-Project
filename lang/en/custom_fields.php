<?php

declare(strict_types=1);

// Custom field engine (decision D-9). Field labels themselves are data
// (label_ar / label_en on the definition), never keys in this file.
return [

    'navigation' => [
        'label' => 'Custom fields',
        'model' => 'Custom field',
        'plural_model' => 'Custom fields',
    ],

    'sections' => [
        'definition' => 'Definition',
        'options' => 'Choices',
        'validation' => 'Validation',
        'custom' => 'Custom fields',
    ],

    'fields' => [
        'entity' => 'Applies to',
        'key' => 'Key',
        'label' => 'Label',
        'label_ar' => 'Label (Arabic)',
        'label_en' => 'Label (English)',
        'type' => 'Type',
        'options' => 'Choices',
        'option_value' => 'Stored value',
        'option_label_ar' => 'Label (Arabic)',
        'option_label_en' => 'Label (English)',
        'is_required' => 'Required',
        'is_filterable' => 'Filterable',
        'is_listed' => 'Available as a column',
        'is_active' => 'Active',
        'sort' => 'Sort order',
        'values_count' => 'Values',
        'min' => 'Minimum',
        'max' => 'Maximum',
        'step' => 'Step',
        'min_length' => 'Minimum length',
        'max_length' => 'Maximum length',
        'regex' => 'Pattern',
    ],

    'helpers' => [
        'key' => 'Lowercase letters, numbers and underscores. The key is how imports, exports and saved views address the field, so it cannot be changed later.',
        'type_locked' => 'The type cannot be changed once the field holds values, because values are stored in the column the type names.',
        'options' => 'The stored value is what is written to the record; the labels are what users read.',
        'regex' => 'A regular expression the value must match, with or without delimiters.',
        'delete_blocked' => 'This field holds values and cannot be deleted. Deactivate it instead: it disappears from forms, tables and filters while the stored values are kept.',
    ],

    'filters' => [
        'entity' => 'Applies to',
        'type' => 'Type',
        'is_active' => 'Active',
        'from' => 'From',
        'to' => 'To',
    ],

    'actions' => [
        'add_option' => 'Add a choice',
    ],

    'validation' => [
        'key_format' => 'The key must start with a letter and contain only lowercase letters, numbers and underscores (2 to 50 characters).',
        'entity_unknown' => 'Choose the entity the field applies to.',
        'type_unknown' => 'Choose the type of the field.',
        'invalid_pattern' => 'The pattern :pattern is not a valid regular expression, or is longer than 200 characters.',
        'key_reserved' => 'The key :key is already used by a built-in field of this entity.',
        'key_taken' => 'Another custom field of this entity already uses this key.',
        'key_locked' => 'The key cannot be changed after the field is created.',
        'entity_locked' => 'The entity cannot be changed after the field is created.',
        'type_locked' => 'The type cannot be changed while the field holds values.',
        'options_required' => 'A choice field needs at least one choice.',
        'options_forbidden' => 'This field type does not take choices.',
        'invalid_option' => 'The choice :value is empty, too long or repeated.',
        'validation_not_supported' => 'This field type does not support the :key constraint.',
        'delete_has_values' => 'This field holds :count values. Deactivate it instead of deleting it.',
        'unsupported_entity' => 'The :model records do not carry custom fields.',
        'value_required' => ':label is required.',
        'value_invalid' => 'The value of :label is not valid.',
    ],

    'notifications' => [
        'created' => 'The custom field was created.',
        'saved' => 'The custom field was saved.',
        'deleted' => 'The custom field was deleted.',
    ],

    'values' => [
        'yes' => 'Yes',
        'no' => 'No',
    ],

    'empty' => [
        'heading' => 'No custom fields',
        'description' => 'Add a field to capture what your business tracks beyond the built-in ones.',
        'no_fields' => 'No custom fields are defined for this entity.',
    ],

];
