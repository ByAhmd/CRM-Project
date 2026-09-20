<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmailTemplates\Schemas;

use App\Enums\CustomFieldEntity;
use App\Models\EmailTemplate;
use App\Services\Email\MergeTags;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

/**
 * The template editor (decision D-10). The entity select is limited to the
 * two records an email is sent from, and the body fields list the merge
 * tags the chosen entity supports so an author never has to guess a tag.
 */
final class EmailTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('email_templates.sections.details'))
                    ->schema([
                        TextInput::make('name_ar')
                            ->label(__('email_templates.fields.name_ar'))
                            ->required()
                            ->minLength(2)
                            ->maxLength(100)
                            ->rules([
                                fn (?EmailTemplate $record): object => Rule::unique('email_templates', 'name_ar')
                                    ->ignore($record?->getKey())
                                    ->withoutTrashed(),
                                self::trashedNamesakeRule('name_ar'),
                            ])
                            ->validationMessages(['unique' => __('email_templates.validation.name_unique')]),

                        TextInput::make('name_en')
                            ->label(__('email_templates.fields.name_en'))
                            ->extraInputAttributes(['dir' => 'ltr'])
                            ->required()
                            ->minLength(2)
                            ->maxLength(100)
                            ->rules([
                                fn (?EmailTemplate $record): object => Rule::unique('email_templates', 'name_en')
                                    ->ignore($record?->getKey())
                                    ->withoutTrashed(),
                                self::trashedNamesakeRule('name_en'),
                            ])
                            ->validationMessages(['unique' => __('email_templates.validation.name_unique')]),

                        Select::make('entity')
                            ->label(__('email_templates.fields.entity'))
                            ->helperText(__('email_templates.helpers.entity'))
                            ->placeholder(__('email_templates.options.entity_any'))
                            ->options(fn (): array => self::entityOptions())
                            ->rules([Rule::enum(CustomFieldEntity::class)->only(MergeTags::supportedEntities())])
                            ->nullable()
                            ->live()
                            ->native(false),

                        Toggle::make('is_active')
                            ->label(__('email_templates.fields.is_active'))
                            ->helperText(__('email_templates.helpers.is_active'))
                            ->default(true),

                        TextInput::make('sort')
                            ->label(__('email_templates.fields.sort'))
                            ->helperText(__('email_templates.helpers.sort'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(65535)
                            ->default(0)
                            ->required(),
                    ])
                    ->columns(['default' => 1, 'lg' => 2]),

                Section::make(__('email_templates.sections.content'))
                    ->schema([
                        TextInput::make('subject_ar')
                            ->label(__('email_templates.fields.subject_ar'))
                            ->helperText(__('email_templates.helpers.subject'))
                            ->required()
                            ->maxLength(200),

                        Textarea::make('body_ar')
                            ->label(__('email_templates.fields.body_ar'))
                            ->helperText(fn (Get $get): string => self::bodyHelper($get('entity')))
                            ->rows(10)
                            ->required()
                            ->maxLength(20000),

                        TextInput::make('subject_en')
                            ->label(__('email_templates.fields.subject_en'))
                            ->helperText(__('email_templates.helpers.subject'))
                            ->extraInputAttributes(['dir' => 'ltr'])
                            ->required()
                            ->maxLength(200),

                        Textarea::make('body_en')
                            ->label(__('email_templates.fields.body_en'))
                            ->helperText(fn (Get $get): string => self::bodyHelper($get('entity')))
                            ->extraInputAttributes(['dir' => 'ltr'])
                            ->rows(10)
                            ->required()
                            ->maxLength(20000),
                    ])
                    ->columns(1),
            ])
            ->columns(1);
    }

    /**
     * Only the entities an email is sent from (leads, contacts).
     *
     * @return array<string, string>
     */
    public static function entityOptions(): array
    {
        $options = [];

        foreach (MergeTags::supportedEntities() as $entity) {
            $options[$entity->value] = $entity->getLabel();
        }

        return $options;
    }

    /**
     * The body helper: the generic hint plus the merge tags available for the
     * chosen entity — every tag of both entities when none is chosen.
     */
    public static function bodyHelper(mixed $entity): string
    {
        $case = is_string($entity) && $entity !== '' ? CustomFieldEntity::tryFrom($entity) : null;

        $tags = $case === null
            ? array_merge(...array_map(static fn (CustomFieldEntity $entity): array => MergeTags::available($entity), MergeTags::supportedEntities()))
            : MergeTags::available($case);

        $list = implode(__('email_templates.helpers.tags_separator'), array_map(
            static fn (string $tag, string $label): string => sprintf('{{%s}} — %s', $tag, $label),
            array_keys($tags),
            array_values($tags),
        ));

        return __('email_templates.helpers.body').' '.__('email_templates.helpers.tags_available', ['tags' => $list]);
    }

    /**
     * The name indexes are global, so a soft-deleted template with the same
     * name must be restored rather than recreated; report that as a
     * validation message instead of letting the database index throw.
     */
    private static function trashedNamesakeRule(string $column): Closure
    {
        return fn (?EmailTemplate $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record, $column): void {
            $trashed = EmailTemplate::onlyTrashed()
                ->where($column, $value)
                ->whereKeyNot($record?->getKey())
                ->exists();

            if ($trashed) {
                $fail(__('email_templates.validation.name_unique_trashed'));
            }
        };
    }
}
