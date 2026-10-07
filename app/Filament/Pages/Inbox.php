<?php

declare(strict_types=1);

namespace App\Filament\Pages;

/**
 * The Inbox in the home panel (/app/inbox, D-27): reached from the workspace
 * screen's user menu, and by every active user whatever their workspaces.
 * Inside a workspace the reader gets that workspace's own copy instead
 * (InboxPage::forPanel()), so the sidebar stays.
 */
final class Inbox extends InboxPage {}
