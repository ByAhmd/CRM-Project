<?php

declare(strict_types=1);

// Sending templated email from a contact or a lead (decision D-10).
return [

    'actions' => [
        'send' => 'Send email',
        'send_heading' => 'Send an email',
        'send_submit' => 'Send',
    ],

    'fields' => [
        'template' => 'Template',
        'locale' => 'Language',
        'to' => 'To',
        'subject' => 'Subject',
        'body' => 'Message',
        'preview' => 'Preview',
    ],

    'helpers' => [
        'no_email' => 'This record has no email address.',
        'mail_not_configured' => 'No mail server is configured yet: the message will be written to the application log instead of being delivered, and the activity is still recorded.',
        'locale' => 'The language the template is loaded in and the message is written in.',
        'template' => 'Choosing a template fills the subject and the message; you can edit both before sending.',
        'unknown_tags' => 'Unknown merge tags were replaced with nothing: :tags',
    ],

    'options' => [
        'locale' => [
            'ar' => 'Arabic',
            'en' => 'English',
        ],
    ],

    'notifications' => [
        'sent' => 'Email sent to :to',
    ],

    'validation' => [
        'no_email' => 'The email cannot be sent: the recipient has no email address.',
        'unsupported_recipient' => 'Emails can only be sent to contacts and leads.',
        'subject_too_long' => 'The email cannot be sent: once the merge tags are filled in, the subject exceeds :max characters. Shorten it and try again.',
    ],

    'tags' => [
        'contact.first_name' => 'Contact first name',
        'contact.last_name' => 'Contact last name',
        'contact.full_name' => 'Contact full name',
        'contact.job_title' => 'Contact job title',
        'contact.email' => 'Contact email',
        'contact.mobile' => 'Contact mobile',
        'account.name' => 'Account name',
        'lead.first_name' => 'Lead first name',
        'lead.last_name' => 'Lead last name',
        'lead.full_name' => 'Lead full name',
        'lead.company_name' => 'Lead company',
        'lead.job_title' => 'Lead job title',
        'lead.email' => 'Lead email',
        'lead.phone' => 'Lead phone',
        'user.name' => 'Your name',
        'user.email' => 'Your email',
        'organisation.name' => 'Organisation name',
        'date.today' => 'Today\'s date',
    ],

    'mail' => [
        'greeting' => 'Dear :name,',
        'reply_hint' => 'You can reply to this message directly to reach :name.',
        'footer' => ':organisation',
    ],

];
