<?php

declare(strict_types=1);

// Email templates (decision D-10).
return [

    'navigation' => [
        'label' => 'Email templates',
        'model' => 'Email template',
        'plural_model' => 'Email templates',
    ],

    'sections' => [
        'details' => 'Template details',
        'content' => 'Subject and body',
    ],

    'fields' => [
        'name' => 'Template name',
        'name_ar' => 'Name (Arabic)',
        'name_en' => 'Name (English)',
        'entity' => 'Used for',
        'subject_ar' => 'Subject (Arabic)',
        'subject_en' => 'Subject (English)',
        'body_ar' => 'Body (Arabic)',
        'body_en' => 'Body (English)',
        'is_active' => 'Active',
        'sort' => 'Sort order',
    ],

    'helpers' => [
        'entity' => 'Leave empty to offer the template on both leads and contacts; choose one to offer it there only.',
        'subject' => 'One line; merge tags are allowed.',
        'body' => 'Plain text; line breaks are kept. Write a merge tag as {{tag}} and it is replaced with the recipient\'s value when the email is sent.',
        'tags_available' => 'Available merge tags: :tags',
        'tags_separator' => ', ',
        'is_active' => 'Inactive templates are not offered when sending an email.',
        'sort' => 'Display order in the template list; lowest first.',
    ],

    'options' => [
        'entity_any' => 'Leads and contacts',
    ],

    'filters' => [
        'entity' => 'Used for',
        'is_active' => 'Active',
        'trashed' => 'Deleted',
    ],

    'validation' => [
        'name_unique' => 'A template with this name already exists.',
        'name_unique_trashed' => 'A deleted template has this name; restore it from the "Deleted" filter instead of creating it again.',
    ],

    'empty' => [
        'heading' => 'No email templates yet',
        'description' => 'Create bilingual templates so the team can send consistent emails from leads and contacts.',
    ],

];
