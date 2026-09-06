<?php

declare(strict_types=1);

// Notes on leads, contacts, accounts and deals (decision A-10).
return [

    'navigation' => [
        'label' => 'Notes',
        'model' => 'Note',
        'plural_model' => 'Notes',
    ],

    'fields' => [
        'body' => 'Note',
        'is_pinned' => 'Pinned',
        'author' => 'Author',
        'created_at' => 'Added',
        'edited_at' => 'Edited',
        'excerpt' => 'Note',
    ],

    'helpers' => [
        'body' => 'Plain text; line breaks are kept. Up to 5,000 characters.',
    ],

    'actions' => [
        'add' => 'Add note',
        'add_heading' => 'Add a note',
        'add_submit' => 'Add note',
        'pin' => 'Pin',
        'unpin' => 'Unpin',
        'edit' => 'Edit note',
        'view' => 'View note',
        'delete' => 'Delete note',
        'restore' => 'Restore note',
    ],

    'notifications' => [
        'added' => 'Note added',
        'updated' => 'Note updated',
        'pinned' => 'Note pinned',
        'unpinned' => 'Note unpinned',
        'deleted' => 'Note deleted',
        'restored' => 'Note restored',
    ],

    'validation' => [
        'subject_required' => 'A note must belong to a lead, a contact, an account or a deal.',
        'body_required' => 'The note cannot be empty.',
        'subject_unsupported' => 'Notes can only be written on leads, contacts, accounts and deals.',
        'body_too_long' => 'The note may not exceed :max characters.',
        'trashed' => 'Restore the note before editing or pinning it.',
    ],

    'filters' => [
        'trashed' => 'Deleted',
    ],

    'empty' => [
        'heading' => 'No notes yet',
        'description' => 'Add a note to keep what you learn next to the record.',
    ],

];
