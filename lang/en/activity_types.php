<?php

declare(strict_types=1);

return [

    'navigation' => [
        'label' => 'Activity types',
        'model' => 'Activity type',
        'plural_model' => 'Activity types',
    ],

    'sections' => [
        'details' => 'Activity type details',
        'appearance' => 'Appearance',
    ],

    'fields' => [
        'name' => 'Activity type name',
        'name_ar' => 'Name (Arabic)',
        'name_en' => 'Name (English)',
        'kind' => 'Kind',
        'icon' => 'Icon',
        'color' => 'Colour',
        'is_system' => 'System',
        'is_active' => 'Active',
        'sort' => 'Sort order',
    ],

    'placeholders' => [
        'no_icon' => 'No icon',
    ],

    'helpers' => [
        'kind' => 'Decides how the activity renders on the timeline and which fields apply to it.',
        'kind_locked' => 'The kind of a system activity type cannot be changed.',
        'icon' => 'Shown on the timeline; defaults to the icon of the kind.',
        'color' => 'Badge colour on the timeline and in lists.',
        'is_active' => 'Inactive types are not offered when logging an activity. A type that logged activities use cannot be deleted; deactivate it instead.',
        'sort' => 'Display order in lists; lowest first.',
    ],

    'filters' => [
        'is_active' => 'Active',
        'kind' => 'Kind',
        'is_system' => 'System',
    ],

    'validation' => [
        'name_unique' => 'An activity type with this name already exists.',
    ],

    'empty' => [
        'heading' => 'No activity types yet',
        'description' => 'Create an activity type so activities can be logged against it.',
    ],

    'options' => [
        'icons' => [
            'OutlinedPhone' => 'Phone',
            'OutlinedCalendarDays' => 'Calendar',
            'OutlinedEnvelope' => 'Envelope',
            'OutlinedDocumentText' => 'Document',
            'OutlinedCheckCircle' => 'Check circle',
            'OutlinedCog6Tooth' => 'Cog',
            'OutlinedClipboardDocumentList' => 'Clipboard',
            'OutlinedChatBubbleLeftRight' => 'Chat bubbles',
            'OutlinedVideoCamera' => 'Video camera',
            'OutlinedUserGroup' => 'People',
            'OutlinedBuildingOffice' => 'Office building',
            'OutlinedDevicePhoneMobile' => 'Mobile phone',
            'OutlinedPaperAirplane' => 'Paper plane',
            'OutlinedBell' => 'Bell',
        ],
    ],

];
