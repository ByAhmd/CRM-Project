<?php

declare(strict_types=1);

// Attachments (module row 13, decision D-13).
return [

    'navigation' => [
        'label' => 'Attachments',
        'model' => 'Attachment',
        'plural_model' => 'Attachments',
    ],

    'fields' => [
        'file' => 'File',
        'original_name' => 'File name',
        'mime_type' => 'Type',
        'size' => 'Size',
        'description' => 'Description',
        'uploaded_by' => 'Uploaded by',
        'created_at' => 'Uploaded',
    ],

    'options' => [
        'mime' => [
            'image' => 'Image',
            'pdf' => 'PDF',
            'document' => 'Document',
            'spreadsheet' => 'Spreadsheet',
            'text' => 'Text',
            'other' => 'Other',
        ],
    ],

    'helpers' => [
        'file' => 'Allowed: :types. Maximum size :size.',
        'list_separator' => ', ',
    ],

    'filters' => [
        'trashed' => 'Deleted',
    ],

    'actions' => [
        'upload' => 'Upload file',
        'upload_heading' => 'Upload a file',
        'upload_submit' => 'Upload',
        'download' => 'Download',
        'delete' => 'Delete',
        'restore' => 'Restore',
    ],

    'notifications' => [
        'uploaded' => 'File uploaded',
        'deleted' => 'File deleted',
        'restored' => 'File restored',
    ],

    'validation' => [
        'mime_not_allowed' => 'Files of type ":mime" are not allowed.',
        'too_large' => 'The file is larger than the :max limit.',
        'file_missing' => 'The uploaded file could not be found. Please upload it again.',
        'storage_failed' => 'The file could not be saved. Please try again.',
    ],

    'empty' => [
        'heading' => 'No files yet',
        'description' => 'Upload a quote, a contract or any document related to this record.',
    ],

];
