<?php

declare(strict_types=1);

return [

    'navigation' => [
        'label' => 'Contacts',
        'model' => 'Contact',
        'plural_model' => 'Contacts',
    ],

    'sections' => [
        'details' => 'Basic details',
        'contact' => 'Contact details',
        'ownership' => 'Owner and tags',
        'notes' => 'Notes',
    ],

    'fields' => [
        'name' => 'Name',
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'account' => 'Company',
        'job_title' => 'Job title',
        'department' => 'Department',
        'email' => 'Email',
        'mobile' => 'Mobile',
        'phone' => 'Phone',
        'preferred_locale' => 'Correspondence language',
        'linkedin_url' => 'LinkedIn URL',
        'is_primary' => 'Primary contact',
        'owner' => 'Owner',
        'description' => 'Notes',
        'created_by' => 'Created by',
        'created_at' => 'Created',
        'updated_at' => 'Last updated',
    ],

    'helpers' => [
        'account' => 'A contact belongs to at most one company.',
        'is_primary' => 'One primary contact per company; enabling it here removes it from the others.',
        'preferred_locale' => 'Messages to this contact are sent in this language.',
    ],

    'filters' => [
        'account' => 'Company',
        'owner' => 'Owner',
        'is_primary' => 'Primary contact',
        'trashed' => 'Deleted',
    ],

    'validation' => [
        'account_out_of_reach' => 'That company does not exist or is outside the records you can access.',
    ],

    'empty' => [
        'heading' => 'No contacts yet',
        'description' => 'Add the first contact or create one from a company page.',
    ],

];
