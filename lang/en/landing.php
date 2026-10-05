<?php

declare(strict_types=1);

// The public landing page at the site root (decision D-24). Every claim here
// describes a capability the CRM ships today — no customers, testimonials or
// usage figures; tests/Feature/Landing/LandingClaimsTest pins each capability
// point and each answer to the code that backs it. The numbers of the facts
// strip are read from the code, never written here. The product preview is an
// illustration: its names and numbers are sample content, and the page says so.
return [

    'meta' => [
        'title' => 'Sales and tasks in one system',
        'description' => 'A CRM for leads, deals and the sales pipeline, for handing out tasks and following them through, and for reports — in Arabic and English, with fine-grained permissions, an audit trail and weekly backups.',
        // Open Graph locale code of each interface language (not shown to readers).
        'og_locale' => 'en_US',
    ],

    'nav' => [
        'skip' => 'Skip to content',
        'sections' => 'Page sections',
        'features' => 'Features',
        'workspaces' => 'Workspaces',
        'faq' => 'FAQ',
        'sign_in' => 'Sign in',
        'my_workspaces' => 'My workspaces',
        'language' => 'Change the language to :language',
        'theme' => 'Dark mode',
    ],

    'hero' => [
        'eyebrow' => 'Customer relationship management',
        'title' => 'Your sales and your team\'s tasks,',
        'title_emphasis' => 'in one place',
        'lead' => 'Follow leads and deals along the sales pipeline, hand out tasks and track them step by step, and read the results in ready-made reports — in Arabic or English, with permissions that decide what each user sees.',
        'cta_sign_in' => 'Sign in',
        'cta_workspaces' => 'Go to my workspaces',
        'cta_features' => 'Explore the features',
        'invite_note' => 'Accounts are by invitation from the administrator.',
    ],

    'preview' => [
        'caption' => 'Illustration with sample data',
        'window_title' => 'Tasks',
        'task_title' => 'Prepare a quote for the client',
        'task_due' => 'Due tomorrow at 17:00',
        'assignee' => 'Sarah',
        'checklist' => 'Checklist',
        'progress' => ':done/:total',
        'items' => [
            'requirements' => 'Review the client\'s requirements',
            'pricing' => 'Price the line items',
            'discount' => 'Approve the discount',
            'drafting' => 'Draft the quote',
            'sending' => 'Send the quote',
        ],
        'comment_author' => 'Sarah',
        'comment_mention' => '@Huda',
        'comment_body' => 'please check the discount line before it goes out.',
        'pipeline' => 'Sales pipeline',
        'stages' => [
            'qualification' => 'Qualification',
            'proposal' => 'Proposal',
            'negotiation' => 'Negotiation',
            'won' => 'Won',
        ],
        'score' => 'Lead score',
    ],

    'facts' => [
        'heading' => 'Numbers from inside the system',
        'workspaces' => [
            'label' => '{1} workspace|[2,*] workspaces',
            'note' => 'each opened by its own permission',
        ],
        'roles' => [
            'label' => '{1} built-in role|[2,*] built-in roles',
            'note' => 'and more can be added with their own permissions',
        ],
        'languages' => [
            'label' => '{1} language|[2,*] languages',
            'note' => 'each user picks the interface language',
        ],
        'audit_days' => [
            'label' => '{1} day|[2,*] days',
            'note' => 'of audit history kept',
        ],
    ],

    'capabilities' => [
        'heading' => 'What a sales and operations team needs',
        'subheading' => 'Everything on this page works in the system today.',
        'sales' => [
            'title' => 'Sales',
            'body' => 'From the first contact with a lead to the won deal.',
            'points' => [
                'pipeline' => 'Sales pipelines with stages and a closing probability per stage, adjustable per deal',
                'deal_board' => 'A deal board where deals move between stages by drag and drop',
                'lead_scoring' => 'Automatic lead scoring from rules you set, with a manual override',
                'conversion' => 'Converting a qualified lead into a contact and an account, with a deal when needed',
                'products' => 'Products and deal line items in Saudi riyals, with forecast categories',
            ],
        ],
        'tasks' => [
            'title' => 'Tasks',
            'body' => 'Hand out work and follow it through without side messages.',
            'points' => [
                'assignment' => 'Assigning tasks to team members, with an in-app and email notice',
                'progress' => 'Progress updates written by the assignee, and a notice to the assigner on completion',
                'comments' => 'Comments on the task, and @mentions that pull colleagues into it',
                'checklists' => 'Checklists inside a task that show how many items are done',
                'routines' => 'Routine tasks created automatically every week or every month',
                'calendar' => 'A tasks board and a calendar that keep the dates in one place',
            ],
        ],
        'reports' => [
            'title' => 'Reports',
            'body' => 'Your numbers, ready to read without outside spreadsheets.',
            'points' => [
                'pipeline' => 'The sales pipeline, the forecast and why deals are won or lost',
                'funnel' => 'The conversion funnel and how each lead source performs',
                'performance' => 'Sales and activity performance per owner',
                'tasks' => 'A task report with the completion rate and the average time to finish per employee',
                'weekly' => 'A weekly task summary emailed to those who hand out work',
            ],
        ],
        'security' => [
            'title' => 'Security and control',
            'body' => 'Each user sees only what their permissions allow.',
            'points' => [
                'visibility' => 'Record-level permissions: their own records, their team\'s, or all records',
                'workspaces' => 'Workspaces opened by permission',
                'two_step' => 'Optional two-step verification with an authenticator app or email',
                'audit' => 'An audit trail of important changes: who made them and when',
                'backups' => 'An automatic weekly backup of the database and the files',
                'invitations' => 'Invitation-only accounts and strong passwords',
            ],
        ],
    ],

    'workspaces' => [
        'heading' => 'Workspaces opened by permission',
        'subheading' => 'After signing in you see every workspace: you enter the ones your role allows, and the rest stay locked.',
    ],

    'faq' => [
        'heading' => 'Frequently asked questions',
        'items' => [
            'accounts' => [
                'q' => 'How do I get an account?',
                'a' => 'Accounts are by invitation only: the administrator adds you and you receive an email to set your password. There is no self-registration.',
            ],
            'language' => [
                'q' => 'Does the system work in Arabic and English?',
                'a' => 'Yes. Arabic is the default, and every user can switch to English at any time; the interface turns to the matching direction and the choice is saved on the account.',
            ],
            'visibility' => [
                'q' => 'Who sees whose records?',
                'a' => 'A user\'s role decides how far they see: their own records, their team\'s, or all records. Sales reps cannot reassign their own records; that is for holders of the assign permission.',
            ],
            'two_step' => [
                'q' => 'Is two-step verification required?',
                'a' => 'It is optional: each user turns it on from their profile with an authenticator app or a code sent to their email. Passwords are at least 12 characters, with upper- and lower-case letters and numbers.',
            ],
            'backups' => [
                'q' => 'How is the data kept safe?',
                'a' => 'Every week the system backs up its database and attached files and keeps the newest sets, and the administrator is told if a backup fails. The audit trail keeps important changes with who made them and when.',
            ],
            'routines' => [
                'q' => 'Can recurring work be scheduled?',
                'a' => 'Yes. Whoever hands out work creates a weekly or monthly routine task, and on its day the system creates a task for each assignee and tells them.',
            ],
        ],
    ],

    'cta' => [
        'heading' => 'Ready to start?',
        'body' => 'Sign in with the account the administrator invited you to.',
        'body_signed_in' => 'Pick up where you left off in your workspaces.',
    ],

    'footer' => [
        'tagline' => 'Sales, tasks and reports in one system, in Arabic and English.',
        'nav' => 'Footer links',
        'copyright' => '© :year :name. All rights reserved.',
    ],

];
