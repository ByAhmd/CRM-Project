<?php

declare(strict_types=1);

namespace App\Support;

use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Account;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Support\Filament\PanelUrl;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;

/**
 * Where a record is opened from outside its own pages — notifications,
 * mails, the timeline. One typed map instead of guessing resource class names
 * from a permission group; anything unknown lands on the workspace screen.
 *
 * The URL always points into the workspace that owns the record's page
 * (D-23), whatever panel, job or command builds it; forReader() additionally
 * withholds it from a reader who may not enter that workspace, so a link
 * never dead-ends on a 403.
 */
final class RecordUrl
{
    public static function view(Model $record): string
    {
        $resource = self::resourceFor($record);

        if ($resource === null) {
            return self::home();
        }

        return $resource::getUrl('view', ['record' => $record]);
    }

    /**
     * The record's page for this reader, or null when the reader may not
     * enter the workspace that owns it. An unknown record type leads to the
     * workspace screen, which every reader may open.
     */
    public static function forReader(?User $reader, Model $record): ?string
    {
        $resource = self::resourceFor($record);

        if ($resource === null) {
            return self::home();
        }

        return PanelUrl::forReader($reader, $resource, static fn (): string => $resource::getUrl('view', ['record' => $record]));
    }

    /** The workspace screen: the landing page every signed-in user may open. */
    public static function home(): string
    {
        return PanelUrl::workspaceScreen();
    }

    /**
     * @return class-string<\Filament\Resources\Resource>|null
     */
    private static function resourceFor(Model $record): ?string
    {
        return match ($record::class) {
            Lead::class => LeadResource::class,
            Contact::class => ContactResource::class,
            Account::class => AccountResource::class,
            Deal::class => DealResource::class,
            Task::class => TaskResource::class,
            Activity::class => ActivityResource::class,
            default => null,
        };
    }
}
