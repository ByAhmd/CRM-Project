<?php

declare(strict_types=1);

return [

    'navigation' => [
        'label' => 'Users',
        'model' => 'User',
        'plural_model' => 'Users',
    ],

    'sections' => [
        'details' => 'User details',
        'access' => 'Roles and team',
        'status' => 'Account status',
    ],

    'fields' => [
        'name' => 'Full name',
        'email' => 'Email',
        'phone' => 'Mobile number',
        'locale' => 'Interface language',
        'roles' => 'Roles',
        'team' => 'Team',
        'status' => 'Status',
        'last_login' => 'Last sign-in',
    ],

    'placeholders' => [
        'name' => 'e.g. Ahmed Alessa',
        'email' => 'name@company.com',
        'phone' => '05XXXXXXXX',
        'no_team' => 'No team',
        'never_logged_in' => 'Never signed in',
    ],

    'helpers' => [
        'locale' => 'The language this user sees the interface and notifications in. They can change it later from their account menu.',
        'roles' => 'The role decides what the user may do. A sales representative sees only their own records; a sales manager sees the team\'s.',
        'team' => 'The team this user belongs to; team managers see the records owned by their team.',
        'status' => 'A disabled account cannot sign in; its records and history are kept.',
    ],

    'filters' => [
        'role' => 'Role',
        'team' => 'Team',
        'status' => 'Status',
        'trashed' => 'Deleted',
    ],

    'actions' => [
        'invite' => 'Invite user',
    ],

    'invitation' => [
        'subject' => 'You are invited to :app',
        'greeting' => 'Hello :name,',
        'intro' => ':inviter has invited you to :app. Use the button below to set your password and activate your account.',
        'action' => 'Set my password',
        'expiry' => 'This link expires in :count minutes. If it has expired, request a new one from the sign-in page.',
        'ignore' => 'If you were not expecting this invitation, you can ignore this message.',
        'resend' => 'Resend invitation',
        'resend_heading' => 'Resend the invitation?',
        'resend_description' => 'A new password link will be sent and the previous link will stop working.',
        'sent_title' => 'Invitation sent',
        'sent_body' => 'A password link was sent to :email.',
    ],

    'validation' => [
        'email_unique' => 'This email is already used by another account.',
        'roles_required' => 'Choose at least one role.',
        'last_super_admin' => 'This cannot be completed: this is the only active super administrator.',
    ],

    'empty' => [
        'heading' => 'No users yet',
        'description' => 'Invite the first user so they can sign in.',
    ],

];
