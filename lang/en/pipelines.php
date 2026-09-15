<?php

declare(strict_types=1);

return [

    'navigation' => [
        'label' => 'Pipelines',
        'model' => 'Pipeline',
        'plural_model' => 'Pipelines',
    ],

    'sections' => [
        'details' => 'Pipeline details',
    ],

    'fields' => [
        'name' => 'Pipeline name',
        'name_ar' => 'Name (Arabic)',
        'name_en' => 'Name (English)',
        'is_default' => 'Default pipeline',
        'is_active' => 'Active',
        'sort' => 'Sort order',
        'stages_count' => 'Stages',
        'default_stage' => 'Default stage',
    ],

    'placeholders' => [
        'name_ar' => 'The pipeline name in Arabic',
        'name_en' => 'e.g. Sales',
        'no_default_stage' => 'No default stage',
    ],

    'helpers' => [
        'is_default' => 'New deals start in it. There is exactly one default pipeline and it cannot be deactivated or deleted; to change it, make another pipeline the default.',
        'is_active' => 'Inactive pipelines are not offered when creating a deal; existing deals stay in them. A pipeline that holds deals cannot be deleted; deactivate it instead.',
        'sort' => 'Display order in lists; lowest first. Rows can also be dragged in the table.',
    ],

    'filters' => [
        'is_active' => 'Active',
        'is_default' => 'Default',
        'trashed' => 'Deleted',
    ],

    'validation' => [
        'name_unique' => 'A pipeline with this name already exists.',
        'name_ar_unique_trashed' => 'A deleted pipeline has this Arabic name. Restore it from the deleted pipelines instead of creating it again.',
        'name_en_unique_trashed' => 'A deleted pipeline has this English name. Restore it from the deleted pipelines instead of creating it again.',
        'default_cannot_be_unset' => 'The default pipeline cannot be unset; make another pipeline the default instead.',
        'default_cannot_be_deactivated' => 'The default pipeline cannot be deactivated; make another pipeline the default first.',
        'default_cannot_be_deleted' => 'The default pipeline cannot be deleted; make another pipeline the default first.',
        'deleted_cannot_be_default' => 'A deleted pipeline cannot be the default; restore it first.',
        'in_use' => 'This pipeline holds deals and cannot be deleted; deactivate it instead.',
    ],

    'empty' => [
        'heading' => 'No pipelines yet',
        'description' => 'Create a pipeline, then arrange the stages a deal moves through until it is won or lost.',
    ],

    'stages' => [

        'defaults' => [
            'qualification' => 'Qualification',
            'won' => 'Won',
            'lost' => 'Lost',
        ],

        'navigation' => [
            'label' => 'Stages',
            'model' => 'Stage',
            'plural_model' => 'Stages',
        ],

        'sections' => [
            'details' => 'Stage details',
        ],

        'fields' => [
            'name' => 'Stage name',
            'name_ar' => 'Name (Arabic)',
            'name_en' => 'Name (English)',
            'kind' => 'Kind',
            'probability' => 'Close probability',
            'color' => 'Colour',
            'is_default' => 'Default stage',
            'sort' => 'Sort order',
        ],

        'placeholders' => [
            'name_ar' => 'The stage name in Arabic',
            'name_en' => 'e.g. Negotiation',
        ],

        'helpers' => [
            'kind' => 'Every pipeline has exactly one Won stage, exactly one Lost stage and at least one Open stage.',
            'probability' => 'A percentage from 0 to 100 used in forecasts. The Won stage is always stored at 100 and the Lost stage at 0.',
            'color' => 'The colour of the stage badge in lists and on the deals board.',
            'is_default' => 'New deals of this pipeline start here. It must be an Open stage, and there is exactly one default stage.',
        ],

        'validation' => [
            'name_unique' => 'A stage with this name already exists in this pipeline.',
            'open_required' => 'A pipeline must have at least one Open stage.',
            'won_exactly_one' => 'A pipeline must have exactly one Won stage.',
            'lost_exactly_one' => 'A pipeline must have exactly one Lost stage.',
            'default_exactly_one' => 'A pipeline must have exactly one default stage; make another stage the default first.',
            'default_must_be_open' => 'The default stage must be an Open stage.',
            'last_of_kind' => 'The only ":kind" stage of this pipeline cannot be deleted.',
            'probability_range' => 'The close probability must be between 0 and 100.',
            'in_use' => 'This stage holds deals or appears in their stage history and cannot be deleted.',
        ],

        'empty' => [
            'heading' => 'No stages yet',
            'description' => 'Add the stages a deal moves through in this pipeline.',
        ],

    ],

];
