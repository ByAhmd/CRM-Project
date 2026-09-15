<?php

declare(strict_types=1);

return [

    'navigation' => [
        'label' => 'Lead statuses',
        'model' => 'Lead status',
        'plural_model' => 'Lead statuses',
    ],

    'sections' => [
        'details' => 'Status details',
    ],

    'fields' => [
        'name' => 'Status name',
        'name_ar' => 'Name (Arabic)',
        'name_en' => 'Name (English)',
        'kind' => 'Kind',
        'color' => 'Colour',
        'is_default' => 'Default status',
        'is_active' => 'Active',
        'sort' => 'Sort order',
    ],

    'helpers' => [
        'kind' => 'The kind decides how the workflow treats the status; exactly one status of kind "Converted" may exist.',
        'color' => 'The badge colour the status is shown with in lists and boards.',
        'is_default' => 'The status every new lead starts in. Enabling it here removes it from the current default.',
        'is_active' => 'Inactive statuses are not offered when changing a lead\'s status. The default status cannot be deactivated. A status that leads, their history or a scoring rule use cannot be deleted; deactivate it instead.',
        'sort' => 'Display order in lists; lowest first.',
    ],

    'filters' => [
        'kind' => 'Kind',
        'is_active' => 'Active',
    ],

    'validation' => [
        'name_unique' => 'A status with this name already exists.',
        'converted_exists' => 'A "Converted" status already exists; there can be only one.',
        'converted_kind_locked' => 'The kind of the "Converted" status cannot be changed; exactly one status of this kind must remain.',
        'converted_cannot_be_deleted' => 'The "Converted" status cannot be deleted; the workflow depends on it.',
        'default_cannot_be_deactivated' => 'The default status cannot be deactivated. Make another status the default first.',
        'default_cannot_be_unset' => 'The default status cannot be unset. Make another status the default instead.',
        'default_cannot_be_deleted' => 'The default status cannot be deleted. Make another status the default first.',
        'in_use' => 'This status is used by leads, their status history or a scoring rule and cannot be deleted. Deactivate it instead.',
    ],

    'empty' => [
        'heading' => 'No statuses yet',
        'description' => 'Create a default status and a "Converted" status so the lead workflow can run.',
    ],

];
