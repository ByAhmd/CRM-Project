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
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * Where a record is opened from outside the panel's own pages — notifications,
 * mails, the timeline. One typed map instead of guessing resource class names
 * from a permission group; anything unknown lands on the panel home.
 */
final class RecordUrl
{
    public static function view(Model $record): string
    {
        $resource = match ($record::class) {
            Lead::class => LeadResource::class,
            Contact::class => ContactResource::class,
            Account::class => AccountResource::class,
            Deal::class => DealResource::class,
            Task::class => TaskResource::class,
            Activity::class => ActivityResource::class,
            default => null,
        };

        if ($resource === null) {
            return self::home();
        }

        return $resource::getUrl('view', ['record' => $record]);
    }

    /** The admin panel's landing page. */
    public static function home(): string
    {
        return Filament::getPanel('admin')->getUrl() ?? url('/admin');
    }
}
