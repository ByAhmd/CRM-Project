<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Support\RecordLabel;
use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The "related records" pickers shared by everything logged against a
 * lead, a contact, an account or a deal (activities now; tasks and notes
 * later).
 *
 * Each Select searches the matching Resource's own query, so only records
 * the actor may read are offered — and a submitted id is re-checked against
 * that query on the server, so a crafted request cannot link to a record
 * outside the actor's reach. The guard field carries the schema-level rule
 * that at least one record is chosen; it is never part of the saved data.
 */
final class SubjectPickers
{
    /** @var list<string> */
    public const COLUMNS = ['lead_id', 'contact_id', 'account_id', 'deal_id'];

    /**
     * With $required the guard field demands at least one record (activities,
     * notes); tasks pass false because a task may be about nothing.
     *
     * @return list<Component>
     */
    public static function components(bool $required = true): array
    {
        return [
            Select::make('lead_id')
                ->label(__('activities.fields.lead'))
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => self::labels(
                    LeadResource::getEloquentQuery()->where(function (Builder $query) use ($search): void {
                        $query->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('company_name', 'like', "%{$search}%");
                    })->orderBy('last_name')->orderBy('first_name')->limit(50)->get(),
                ))
                ->getOptionLabelUsing(fn (mixed $value): ?string => self::label(LeadResource::getEloquentQuery(), $value))
                ->rules([self::readableRule(fn (): Builder => LeadResource::getEloquentQuery())])
                ->nullable()
                ->native(false),

            Select::make('contact_id')
                ->label(__('activities.fields.contact'))
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => self::labels(
                    ContactResource::getEloquentQuery()->where(function (Builder $query) use ($search): void {
                        $query->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })->orderBy('last_name')->orderBy('first_name')->limit(50)->get(),
                ))
                ->getOptionLabelUsing(fn (mixed $value): ?string => self::label(ContactResource::getEloquentQuery(), $value))
                ->rules([self::readableRule(fn (): Builder => ContactResource::getEloquentQuery())])
                ->nullable()
                ->native(false),

            Select::make('account_id')
                ->label(__('activities.fields.account'))
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => self::labels(
                    AccountResource::getEloquentQuery()->where('name', 'like', "%{$search}%")->orderBy('name')->limit(50)->get(),
                ))
                ->getOptionLabelUsing(fn (mixed $value): ?string => self::label(AccountResource::getEloquentQuery(), $value))
                ->rules([self::readableRule(fn (): Builder => AccountResource::getEloquentQuery())])
                ->nullable()
                ->native(false),

            Select::make('deal_id')
                ->label(__('activities.fields.deal'))
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => self::labels(
                    DealResource::getEloquentQuery()->where('title', 'like', "%{$search}%")->orderBy('title')->limit(50)->get(),
                ))
                ->getOptionLabelUsing(fn (mixed $value): ?string => self::label(DealResource::getEloquentQuery(), $value))
                ->rules([self::readableRule(fn (): Builder => DealResource::getEloquentQuery())])
                ->nullable()
                ->native(false),

            Hidden::make('related_guard')
                ->default(1)
                ->dehydrated(false)
                ->visible($required)
                ->validatedWhenNotDehydrated()
                ->validationAttribute(__('activities.fields.related'))
                ->rules([
                    fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                        foreach (self::COLUMNS as $column) {
                            if (filled($get($column))) {
                                return;
                            }
                        }

                        $fail(__('activities.validation.subject_required'));
                    },
                ]),
        ];
    }

    /**
     * The readable record with the given id, or null when it is not one the
     * actor may see.
     */
    public static function lead(mixed $id): ?Lead
    {
        $record = self::find(LeadResource::getEloquentQuery(), $id);

        return $record instanceof Lead ? $record : null;
    }

    public static function contact(mixed $id): ?Contact
    {
        $record = self::find(ContactResource::getEloquentQuery(), $id);

        return $record instanceof Contact ? $record : null;
    }

    public static function account(mixed $id): ?Account
    {
        $record = self::find(AccountResource::getEloquentQuery(), $id);

        return $record instanceof Account ? $record : null;
    }

    public static function deal(mixed $id): ?Deal
    {
        $record = self::find(DealResource::getEloquentQuery(), $id);

        return $record instanceof Deal ? $record : null;
    }

    /**
     * A closure rule wrapped the way Filament expects: the outer closure is
     * evaluated by Filament to produce the rule, the inner one is the rule.
     *
     * @template TModel of Model
     *
     * @param  Closure(): Builder<TModel>  $query
     */
    private static function readableRule(Closure $query): Closure
    {
        return static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($query): void {
            if (! $query()->whereKey($value)->exists()) {
                $fail(__('activities.validation.record_not_accessible'));
            }
        };
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel|null
     */
    private static function find(Builder $query, mixed $id): ?Model
    {
        if ($id === null || $id === '') {
            return null;
        }

        return $query->whereKey((int) $id)->first();
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private static function label(Builder $query, mixed $id): ?string
    {
        $record = self::find($query, $id);

        return $record === null ? null : RecordLabel::of($record);
    }

    /**
     * @param  iterable<Model>  $records
     * @return array<int, string>
     */
    private static function labels(iterable $records): array
    {
        $options = [];

        foreach ($records as $record) {
            $options[(int) $record->getKey()] = RecordLabel::of($record);
        }

        return $options;
    }
}
