<?php

declare(strict_types=1);

// Lead scoring rules (decision D-7).
return [

    'navigation' => [
        'label' => 'Lead scoring',
        'model' => 'Scoring rule',
        'plural_model' => 'Scoring rules',
    ],

    'sections' => [
        'rule' => 'Rule',
    ],

    'fields' => [
        'kind' => 'Rule type',
        'source' => 'Lead source',
        'status' => 'Lead status',
        'field' => 'Field',
        'within_days' => 'Within the last (days)',
        'within_days_value' => '{1} Activity within :days day|[2,*] Activity within :days days',
        'points' => 'Points',
        'is_active' => 'Active',
        'sort' => 'Sort order',
        'target' => 'Applies to',
    ],

    'helpers' => [
        'rule' => 'A lead\'s score is the sum of the points of every active rule that matches it, kept between 0 and 100.',
        'within_days' => 'The rule matches when the lead had activity within this many days.',
        'points' => 'Negative points lower the score.',
    ],

    'validation' => [
        'duplicate' => 'A rule with the same type and target already exists. Change its points instead of adding a second rule.',
    ],

    'filters' => [
        'kind' => 'Rule type',
        'is_active' => 'Active',
    ],

    'empty' => [
        'heading' => 'No scoring rules',
        'description' => 'Add rules to score leads automatically.',
    ],

];
