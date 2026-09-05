<?php

declare(strict_types=1);

// Deals (decisions D-6, D-8).
return [

    'navigation' => [
        'label' => 'Deals',
        'model' => 'Deal',
        'plural_model' => 'Deals',
    ],

    'tabs' => [
        'open' => 'Open',
        'won' => 'Won',
        'lost' => 'Lost',
        'all' => 'All',
    ],

    'sections' => [
        'details' => 'Deal details',
        'value' => 'Value and forecast',
        'close' => 'Closing',
        'stage_history' => 'Stage history',
        'notes' => 'Notes',
        'contacts' => 'Contacts',
        'competitors' => 'Competitors',
        'pipeline' => 'Pipeline and stage',
        'summary' => 'Summary',
        'line_items' => 'Line items',
    ],

    'fields' => [
        'title' => 'Title',
        'account' => 'Account',
        'contact' => 'Primary contact',
        'pipeline' => 'Pipeline',
        'stage' => 'Stage',
        'owner' => 'Owner',
        'status' => 'Status',
        'amount' => 'Amount',
        'probability' => 'Probability (%)',
        'effective_probability' => 'Effective probability (%)',
        'weighted_amount' => 'Weighted amount',
        'expected_close_date' => 'Expected close date',
        'forecast_category' => 'Forecast category',
        'source' => 'Source',
        'lead' => 'Origin lead',
        'close_reason' => 'Close reason',
        'lost_notes' => 'Loss notes',
        'won_at' => 'Won on',
        'lost_at' => 'Lost on',
        'last_activity_at' => 'Last activity',
        'description' => 'Notes',
        'created_by' => 'Created by',
        'created_at' => 'Created',
        'updated_at' => 'Last updated',
        'stage_note' => 'Note',
        'products_count' => 'Line items',
        'product' => 'Product',
        'line_description' => 'Description',
        'quantity' => 'Quantity',
        'unit_price' => 'Unit price',
        'discount_percent' => 'Discount (%)',
        'line_total' => 'Line total',
        'role' => 'Role',
        'is_winner' => 'Won the deal',
        'competitor_notes' => 'Notes',
        'closed_by' => 'Closed by',
        'total' => 'Total',
    ],

    'helpers' => [
        'amount' => 'Computed from the line items when there are any; otherwise entered by hand.',
        'probability' => 'Leave empty to use the stage probability.',
        'stage_readonly' => 'Use the "Change stage", "Mark as won" and "Mark as lost" actions to move this deal.',
        'initial_stage' => 'The stage the deal starts in. Later changes go through the "Change stage" action so they are logged.',
        'lost_notes' => 'What happened and what could be learned from it.',
    ],

    'filters' => [
        'pipeline' => 'Pipeline',
        'stage' => 'Stage',
        'owner' => 'Owner',
        'status' => 'Status',
        'forecast_category' => 'Forecast category',
        'expected_close_from' => 'Expected close from',
        'expected_close_until' => 'Expected close until',
        'trashed' => 'Deleted',
    ],

    'actions' => [
        'change_stage' => 'Change stage',
        'change_stage_heading' => 'Change deal stage',
        'change_stage_submit' => 'Change stage',
        'mark_won' => 'Mark as won',
        'mark_won_heading' => 'Mark deal as won',
        'mark_won_submit' => 'Mark as won',
        'mark_lost' => 'Mark as lost',
        'mark_lost_heading' => 'Mark deal as lost',
        'mark_lost_submit' => 'Mark as lost',
        'reopen' => 'Reopen',
        'reopen_heading' => 'Reopen deal',
        'reopen_submit' => 'Reopen',
        'attach_contact' => 'Add contact',
        'attach_competitor' => 'Add competitor',
        'add_line' => 'Add line item',
    ],

    'notifications' => [
        'stage_changed' => 'Stage changed to :stage',
        'won' => 'Deal marked as won',
        'lost' => 'Deal marked as lost',
        'reopened' => 'Deal reopened',
    ],

    'history' => [
        'changed_at' => 'When',
        'from' => 'From',
        'to' => 'To',
        'by' => 'By',
        'notes' => 'Note',
        'duration' => 'Time in previous stage',
        'days' => '{0} :count days|{1} :count day|[2,*] :count days',
        'hours' => '{0} :count hours|{1} :count hour|[2,*] :count hours',
        'minutes' => '{0} :count minutes|{1} :count minute|[2,*] :count minutes',
    ],

    'validation' => [
        'stage_outside_pipeline' => 'That stage does not belong to the deal\'s pipeline.',
        'initial_stage_must_be_open' => 'A deal must start in an open stage. Win or lose it afterwards through the "Mark as won" and "Mark as lost" actions.',
        'already_closed' => 'This deal is closed. Reopen it before changing its stage.',
        'not_closed' => 'Only a won or lost deal can be reopened.',
        'close_reason_required' => 'A close reason is required to win or lose a deal.',
        'close_reason_kind_mismatch' => 'The close reason does not match the outcome: pick a win reason for a won deal and a loss reason for a lost deal.',
        'pipeline_has_no_closed_stage' => 'The deal\'s pipeline has no won or lost stage.',
        'pipeline_has_no_open_stage' => 'The deal\'s pipeline has no open stage to reopen into.',
    ],

    'empty' => [
        'heading' => 'No deals yet',
        'description' => 'Add the first deal to start filling the pipeline.',
        'line_items' => 'No line items',
        'stage_history' => 'No stage changes yet',
        'contacts' => 'No contacts on this deal yet',
        'competitors' => 'No competitors named on this deal yet',
    ],

];
