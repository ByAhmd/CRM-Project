<?php

declare(strict_types=1);

// Projects: the partitions of the customer base (decision D-30).
return [

    'navigation' => [
        'label' => 'Projects',
        'model' => 'Project',
        'plural_model' => 'Projects',
    ],

    'sections' => [
        'details' => 'Project details',
    ],

    'fields' => [
        'name' => 'Project',
        'name_ar' => 'Arabic name',
        'name_en' => 'English name',
        'color' => 'Colour',
        'is_active' => 'Active',
        'is_default' => 'Default project',
        'sort' => 'Order',
        'leads_count' => 'Leads',
        'contacts_count' => 'Contacts',
        'accounts_count' => 'Accounts',
        'deals_count' => 'Deals',
        'open_deals_count' => 'Open deals',
        'open_deals_amount' => 'Open deals value',
    ],

    'helpers' => [
        'color' => 'The colour of the project badge in the switcher and on records.',
        'is_active' => 'An inactive project is offered neither in the switcher nor in the import dialog; its records stay as they are and open from their links. A project that holds records cannot be deleted; deactivate it instead.',
        'is_default' => 'The project every new user starts in. There is always exactly one default; it can be neither deactivated nor deleted — make another project the default first.',
        'sort' => 'The order in the switcher and on the projects page; lowest first. Rows can also be dragged in the table.',
    ],

    'filters' => [
        'is_active' => 'Active',
    ],

    'actions' => [
        'reorder' => 'Reorder',
        'enter' => 'Enter',
        'switch' => 'Switch project',
        'manage' => 'Manage projects',
    ],

    'validation' => [
        'name_unique' => 'A project with this name exists.',
        'default_must_be_active' => 'The default project must be active; make another project the default first.',
        'last_default' => 'The default project cannot be unset; make another project the default instead.',
        'in_use' => 'This project holds records and cannot be deleted; deactivate it instead.',
        'inactive' => 'This project is inactive.',
    ],

    'switcher' => [
        'label' => 'Project',
        'aria' => 'Current project: :project. Choose a project to work in',
        'current' => 'Current project',
    ],

    'pages' => [
        'projects' => [
            'title' => 'Projects',
            'subheading' => 'Each project is a separate customer base: enter one to work in its records alone.',
            'current' => 'You are here now',
            'counts_scope' => 'Counts are within your visibility.',
        ],
    ],

    'notifications' => [
        'switched' => [
            'title' => 'Project switched',
            'body' => 'You are now in the project ":project".',
        ],
    ],

    'empty' => [
        'heading' => 'No projects yet',
        'description' => 'Add a project for each separate customer base, such as "Iqan".',
    ],

];
