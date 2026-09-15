<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\AccountType;
use App\Enums\CompanySize;
use App\Enums\DealStatus;
use App\Enums\ForecastCategory;
use App\Enums\LeadPriority;
use App\Filament\Resources\Accounts\AccountResource;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Industry;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Tag;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use Filament\QueryBuilder\Constraints\BooleanConstraint;
use Filament\QueryBuilder\Constraints\Constraint;
use Filament\QueryBuilder\Constraints\DateConstraint;
use Filament\QueryBuilder\Constraints\NumberConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\QueryBuilder\Constraints\SelectConstraint;
use Filament\QueryBuilder\Constraints\TextConstraint;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Enums\Width;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The advanced "query" filter of the four main lists (plan module row 17,
 * decision A-8): a Filament QueryBuilder whose constraints are the columns,
 * enums and lookups of each entity, labelled from lang/query_builder.php.
 *
 * Every rule is applied inside the table's filter clause, on top of the
 * resource's scoped base query (D-4): the builder can only narrow the list.
 * Lookup pickers show localised names; the owner picker offers the users
 * within the actor's reach, like the plain owner filter.
 *
 * Layout: the four tables render their filters in a modal
 * (FiltersLayout::Modal, four-extra-large) — the rule builder needs the
 * room, and a modal keeps the toolbar identical to the other lists.
 */
final class QueryBuilderFilters
{
    public const string NAME = 'query';

    public static function layout(): FiltersLayout
    {
        return FiltersLayout::Modal;
    }

    public static function width(): Width
    {
        return Width::FourExtraLarge;
    }

    public static function forLeads(): QueryBuilder
    {
        return self::make([
            self::text('first_name'),
            self::text('last_name'),
            self::text('company_name', nullable: true),
            self::text('job_title', nullable: true),
            self::text('email', nullable: true),
            self::text('phone', nullable: true),
            self::text('city', nullable: true),
            self::text('country', nullable: true),
            NumberConstraint::make('score')->label(__('query_builder.fields.score'))->integer(),
            self::select('priority', LeadPriority::class),
            self::lookup('status', LeadStatus::localisedNameColumn()),
            self::lookup('source', LeadSource::localisedNameColumn(), nullable: true),
            self::owner(Lead::permissionGroup()),
            self::tags(),
            self::date('created_at'),
            self::date('qualified_at', nullable: true),
            self::date('converted_at', nullable: true),
            self::date('last_activity_at', nullable: true),
        ]);
    }

    public static function forContacts(): QueryBuilder
    {
        return self::make([
            self::text('first_name'),
            self::text('last_name'),
            self::text('job_title', nullable: true),
            self::text('department', nullable: true),
            self::text('email', nullable: true),
            self::text('mobile', nullable: true),
            self::text('phone', nullable: true),
            self::text('city', nullable: true),
            self::text('country', nullable: true),
            BooleanConstraint::make('is_primary')->label(__('query_builder.fields.is_primary')),
            self::account(),
            self::owner(Contact::permissionGroup()),
            self::tags(),
            self::date('created_at'),
        ]);
    }

    public static function forAccounts(): QueryBuilder
    {
        return self::make([
            self::text('name'),
            self::text('email', nullable: true),
            self::text('phone', nullable: true),
            self::text('website', nullable: true),
            self::text('city', nullable: true),
            self::text('country', nullable: true),
            self::select('type', AccountType::class),
            self::select('size', CompanySize::class, nullable: true),
            self::lookup('industry', Industry::localisedNameColumn(), nullable: true),
            self::owner(Account::permissionGroup()),
            self::tags(),
            self::date('customer_since', nullable: true),
            self::date('created_at'),
        ]);
    }

    public static function forDeals(): QueryBuilder
    {
        return self::make([
            self::text('title'),
            NumberConstraint::make('amount')->label(__('query_builder.fields.amount')),
            NumberConstraint::make('probability')->label(__('query_builder.fields.probability'))->integer()->nullable(),
            self::select('status', DealStatus::class),
            self::select('forecast_category', ForecastCategory::class),
            self::lookup('pipeline', Pipeline::localisedNameColumn()),
            self::lookup('stage', PipelineStage::localisedNameColumn()),
            self::account(),
            self::owner(Deal::permissionGroup()),
            self::tags(),
            self::date('expected_close_date', nullable: true),
            self::date('created_at'),
            self::date('last_activity_at', nullable: true),
        ]);
    }

    /**
     * @param  array<Constraint>  $constraints
     */
    private static function make(array $constraints): QueryBuilder
    {
        return QueryBuilder::make(self::NAME)
            ->constraints($constraints)
            ->constraintPickerColumns(2)
            ->constraintPickerWidth(Width::TwoExtraLarge->value);
    }

    private static function text(string $name, bool $nullable = false): TextConstraint
    {
        return TextConstraint::make($name)
            ->label(__('query_builder.fields.'.$name))
            ->nullable($nullable);
    }

    private static function date(string $name, bool $nullable = false): DateConstraint
    {
        return DateConstraint::make($name)
            ->label(__('query_builder.fields.'.$name))
            ->nullable($nullable);
    }

    /**
     * @param  class-string<\BackedEnum&HasLabel>  $enum
     */
    private static function select(string $name, string $enum, bool $nullable = false): SelectConstraint
    {
        return SelectConstraint::make($name)
            ->label(__('query_builder.fields.'.$name))
            ->options($enum)
            ->multiple()
            ->nullable($nullable);
    }

    /**
     * A bilingual lookup (status, source, industry, pipeline, stage): the
     * picker is titled and ordered by the name column of the reader's locale.
     */
    private static function lookup(string $relationship, string $titleColumn, bool $nullable = false): RelationshipConstraint
    {
        return RelationshipConstraint::make($relationship)
            ->label(__('query_builder.fields.'.$relationship))
            ->emptyable($nullable)
            ->selectable(
                IsRelatedToOperator::make()
                    ->titleAttribute($titleColumn)
                    ->getOptionLabelFromRecordUsing(fn (Model $record): string => (string) $record->getAttribute('display_name'))
                    ->multiple()
                    ->searchable()
                    ->preload(),
            );
    }

    /**
     * The account picker offers only the accounts the actor may read (D-4):
     * the rule narrows a list that is already scoped, but the option names
     * themselves must not reveal accounts outside the actor's reach.
     */
    private static function account(): RelationshipConstraint
    {
        return RelationshipConstraint::make('account')
            ->label(__('query_builder.fields.account'))
            ->emptyable()
            ->selectable(
                IsRelatedToOperator::make()
                    ->titleAttribute('name')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->modifyRelationshipQueryUsing(fn (Builder $query): Builder => $query->whereIn(
                        $query->qualifyColumn('id'),
                        AccountResource::getEloquentQuery()->select('accounts.id'),
                    )),
            );
    }

    private static function owner(string $permissionGroup): RelationshipConstraint
    {
        return RelationshipConstraint::make('owner')
            ->label(__('query_builder.fields.owner'))
            ->emptyable()
            ->selectable(
                IsRelatedToOperator::make()
                    ->titleAttribute('name')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->modifyRelationshipQueryUsing(function (Builder $query) use ($permissionGroup): Builder {
                        $user = auth()->user();

                        if (! $user instanceof User) {
                            return $query->whereRaw('1 = 0');
                        }

                        return $query->whereIn(
                            $query->qualifyColumn('id'),
                            app(RecordVisibilityResolver::class)->assignableUsers($user, $permissionGroup)->select('users.id'),
                        );
                    }),
            );
    }

    private static function tags(): RelationshipConstraint
    {
        return RelationshipConstraint::make('tags')
            ->label(__('query_builder.fields.tags'))
            ->multiple()
            ->selectable(
                IsRelatedToOperator::make()
                    ->titleAttribute(Tag::localisedNameColumn())
                    ->getOptionLabelFromRecordUsing(fn (Model $record): string => (string) $record->getAttribute('display_name'))
                    ->multiple()
                    ->searchable()
                    ->preload(),
            );
    }
}
