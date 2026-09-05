<?php

declare(strict_types=1);

// Duplicate detection and merging (decision A-11).
return [

    'actions' => [
        'merge' => 'Merge duplicates',
        'heading' => 'Merge two records',
        'description' => 'The record you choose is kept; the other record\'s data and relations move into it and it is then deleted. It can be restored from the deleted records.',
        'submit' => 'Merge',
    ],

    'fields' => [
        'keep' => 'Record to keep',
    ],

    'helpers' => [
        'keep' => 'Blank fields on this record are filled from the other one.',
    ],

    'validation' => [
        'exactly_two' => 'Select exactly two records to merge.',
    ],

    'warnings' => [
        'possible_duplicates' => 'This record may duplicate:',
        'lead' => 'lead :name',
        'contact' => 'existing contact :name',
    ],

    'notifications' => [
        'done' => 'Merged',
    ],

];
