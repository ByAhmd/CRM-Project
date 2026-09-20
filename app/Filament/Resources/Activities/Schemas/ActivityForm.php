<?php

declare(strict_types=1);

namespace App\Filament\Resources\Activities\Schemas;

use App\Enums\ActivityDirection;
use App\Enums\ActivityKind;
use App\Enums\Permission;
use App\Filament\Support\OwnerSelect;
use App\Filament\Support\SubjectPickers;
use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Activities\ActivityRecorder;
use App\Services\Activities\ActivitySubject;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

/**
 * Log an activity (decision A-10). There is no edit form: activities are
 * immutable. The type's kind decides which fields show — direction for
 * calls and emails, duration for calls and meetings — and the recorder
 * applies the same rule server-side. With $withSubjects the form carries
 * the related-record pickers; on a relation manager the owner record is
 * the subject and the pickers are left out.
 */
final class ActivityForm
{
    public static function configure(Schema $schema, bool $withSubjects = true): Schema
    {
        return $schema
            ->components([
                Section::make(__('activities.sections.details'))
                    ->schema([
                        Select::make('activity_type_id')
                            ->label(__('activities.fields.type'))
                            ->helperText(__('activities.helpers.type'))
                            ->options(fn (): array => ActivityType::query()
                                ->where('is_active', true)
                                ->where('kind', '!=', ActivityKind::System->value)
                                ->orderBy('sort')
                                ->orderBy('id')
                                ->get()
                                ->mapWithKeys(fn (ActivityType $type): array => [$type->getKey() => $type->display_name])
                                ->all())
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set, mixed $state) => $set('kind', self::kindOf($state)?->value))
                            ->searchable()
                            ->preload()
                            ->native(false),

                        DateTimePicker::make('occurred_at')
                            ->label(__('activities.fields.occurred_at'))
                            ->default(fn (): Carbon => now())
                            ->required()
                            ->native(false)
                            ->seconds(false),

                        Hidden::make('kind')
                            ->dehydrated(false),

                        TextInput::make('subject')
                            ->label(__('activities.fields.subject'))
                            ->placeholder(__('activities.placeholders.subject'))
                            ->required()
                            ->maxLength(200)
                            ->columnSpanFull(),

                        Select::make('direction')
                            ->label(__('activities.fields.direction'))
                            ->options(ActivityDirection::class)
                            ->nullable()
                            ->native(false)
                            ->visible(fn (Get $get): bool => self::kindFor($get)?->hasDirection() ?? false),

                        TextInput::make('duration_minutes')
                            ->label(__('activities.fields.duration_minutes'))
                            ->integer()
                            ->minValue(1)
                            ->maxValue(65535)
                            ->nullable()
                            ->visible(fn (Get $get): bool => self::kindFor($get)?->hasDuration() ?? false),

                        TextInput::make('outcome')
                            ->label(__('activities.fields.outcome'))
                            ->placeholder(__('activities.placeholders.outcome'))
                            ->maxLength(100),

                        Textarea::make('body')
                            ->label(__('activities.fields.body'))
                            ->rows(4)
                            ->maxLength(5000)
                            ->columnSpanFull(),
                    ])
                    ->columns(['default' => 1, 'lg' => 2]),

                ...$withSubjects ? [
                    Section::make(__('activities.sections.related'))
                        ->description(__('activities.helpers.related'))
                        ->schema(SubjectPickers::components())
                        ->columns(['default' => 1, 'lg' => 2]),
                ] : [],

                Section::make(__('activities.sections.ownership'))
                    ->schema(OwnerSelect::components(Activity::permissionGroup()))
                    ->columns(['default' => 1, 'lg' => 2]),
            ])
            ->columns(1);
    }

    /**
     * Turn the submitted form data into a recorder call. The subject is
     * either built from the pickers (the resource page) or given by the
     * relation manager; the type and the owner are resolved to models here
     * so the recorder never sees raw form input.
     *
     * @param  array<string, mixed>  $data
     */
    public static function log(ActivitySubject $subject, array $data, User $actor): Activity
    {
        $type = ActivityType::query()->findOrFail((int) $data['activity_type_id']);

        // Another owner needs `activity.assign` and must be an active user
        // inside the actor's reach (D-4); anything else falls back to the actor.
        $ownerId = $data['owner_id'] ?? null;
        $owner = $ownerId === null || $ownerId === '' || ((int) $ownerId !== (int) $actor->getKey() && ! $actor->can(Permission::ActivityAssign->value))
            ? null
            : app(RecordVisibilityResolver::class)->assignableUsers($actor, Activity::permissionGroup())->whereKey((int) $ownerId)->first();

        // The Select is enum-backed, so the state is already a case; a raw string is accepted too.
        $rawDirection = $data['direction'] ?? null;
        $direction = $rawDirection instanceof ActivityDirection
            ? $rawDirection
            : (is_string($rawDirection) ? ActivityDirection::tryFrom($rawDirection) : null);
        $duration = $data['duration_minutes'] ?? null;
        $occurredAt = $data['occurred_at'] ?? null;

        return app(ActivityRecorder::class)->record(
            subject: $subject,
            type: $type,
            actor: $actor,
            subjectLine: (string) $data['subject'],
            body: isset($data['body']) ? (string) $data['body'] : null,
            occurredAt: $occurredAt === null || $occurredAt === '' ? null : Carbon::parse((string) $occurredAt),
            direction: $direction,
            durationMinutes: $duration === null || $duration === '' ? null : (int) $duration,
            outcome: isset($data['outcome']) ? (string) $data['outcome'] : null,
            owner: $owner,
        );
    }

    /**
     * The subject assembled from the four pickers: only records the actor
     * may read resolve, so a crafted id silently drops out and the
     * "at least one" guard catches an empty result. The links are then
     * widened exactly like ActivitySubject::for() does on a record page —
     * a deal brings its account and primary contact, a contact its
     * account — so an entry reads the same whichever page logged it.
     *
     * @param  array<string, mixed>  $data
     */
    public static function subjectFrom(array $data): ActivitySubject
    {
        $lead = SubjectPickers::lead($data['lead_id'] ?? null);
        $contact = SubjectPickers::contact($data['contact_id'] ?? null);
        $account = SubjectPickers::account($data['account_id'] ?? null);
        $deal = SubjectPickers::deal($data['deal_id'] ?? null);

        $contact ??= $deal?->contact;
        $account ??= $deal->account ?? $contact?->account;

        return new ActivitySubject(lead: $lead, contact: $contact, account: $account, deal: $deal);
    }

    private static function kindFor(Get $get): ?ActivityKind
    {
        $kind = $get('kind');

        if (is_string($kind) && $kind !== '') {
            return ActivityKind::tryFrom($kind);
        }

        return self::kindOf($get('activity_type_id'));
    }

    private static function kindOf(mixed $typeId): ?ActivityKind
    {
        if ($typeId === null || $typeId === '') {
            return null;
        }

        return ActivityType::query()->whereKey((int) $typeId)->first()?->kind;
    }
}
