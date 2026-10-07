<?php

declare(strict_types=1);

// D-27: the Inbox, every user's notifications on one page.
return [

    'navigation' => [
        'label' => 'Inbox',
    ],

    'pages' => [
        'title' => 'Inbox',
        'subheading' => 'Your notifications, unread first. Opening one marks it read.',
    ],

    'filters' => [
        'all' => 'All',
        'unread' => 'Unread',
    ],

    'fields' => [
        'state' => 'State',
        'unread' => 'Unread',
        'read' => 'Read',
        'notification' => 'Notification',
        'received' => 'Received',
    ],

    'actions' => [
        'open' => 'Open',
        'mark_read' => 'Mark as read',
        'mark_unread' => 'Mark as unread',
        'mark_all_read' => 'Mark all as read',
        'mark_selected_read' => 'Mark selected as read',
        'preferences' => 'Choose what you are notified about',
    ],

    'notifications' => [
        'marked_read_many' => '{0} Nothing was left unread|{1} :count notification marked as read|[2,*] :count notifications marked as read',
    ],

    'empty' => [
        'heading' => 'No notifications yet',
        'description' => 'Assignments, comments, mentions and other notices for you will arrive here.',
        'unread_heading' => 'You are all caught up',
        'unread_description' => 'Every notification has been read. The All tab keeps the earlier ones.',
    ],

];
