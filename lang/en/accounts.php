<?php

declare(strict_types=1);

return [

    'navigation' => [
        'label' => 'Accounts',
        'model' => 'Account',
        'plural_model' => 'Accounts',
    ],

    'tabs' => [
        'all' => 'All',
    ],

    'sections' => [
        'details' => 'Account details',
        'contact' => 'Contact details',
        'ownership' => 'Owner and tags',
        'notes' => 'Notes',
    ],

    'fields' => [
        'name' => 'Company name',
        'type' => 'Type',
        'industry' => 'Industry',
        'size' => 'Company size',
        'parent' => 'Parent company',
        'website' => 'Website',
        'email' => 'Email',
        'phone' => 'Phone',
        'owner' => 'Owner',
        'customer_since' => 'Customer since',
        'contacts_count' => 'Contacts',
        'description' => 'Notes',
        'created_by' => 'Created by',
        'created_at' => 'Created',
        'updated_at' => 'Last updated',
    ],

    'placeholders' => [
        'name' => 'e.g. Horizon Trading Co.',
    ],

    'helpers' => [
        'type' => 'A prospect becomes a customer automatically when its first deal is won.',
        'parent' => 'Optional: the company this account belongs to.',
    ],

    'filters' => [
        'type' => 'Type',
        'industry' => 'Industry',
        'owner' => 'Owner',
        'trashed' => 'Deleted',
    ],

    'empty' => [
        'heading' => 'No accounts yet',
        'description' => 'Add the first company or convert a lead.',
    ],

];
