<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmailTemplates\Tables;

use App\Filament\Resources\EmailTemplates\Schemas\EmailTemplateForm;
use App\Models\EmailTemplate;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class EmailTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')
                    ->label(__('email_templates.fields.name'))
                    ->state(fn (EmailTemplate $record): string => $record->display_name)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('name_ar', 'like', "%{$search}%")
                        ->orWhere('name_en', 'like', "%{$search}%"))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(EmailTemplate::localisedNameColumn(), $direction)),

                TextColumn::make('entity')
                    ->label(__('email_templates.fields.entity'))
                    ->badge()
                    ->placeholder(__('email_templates.options.entity_any'))
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label(__('email_templates.fields.is_active'))
                    ->boolean(),

                TextColumn::make('sort')
                    ->label(__('email_templates.fields.sort'))
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort')
            ->reorderable('sort')
            ->filters([
                TernaryFilter::make('is_active')->label(__('email_templates.filters.is_active')),
                SelectFilter::make('entity')
                    ->label(__('email_templates.filters.entity'))
                    ->options(fn (): array => EmailTemplateForm::entityOptions()),
                TrashedFilter::make()->label(__('email_templates.filters.trashed')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                    RestoreBulkAction::make()->authorizeIndividualRecords('restore'),
                ]),
            ])
            ->emptyStateHeading(__('email_templates.empty.heading'))
            ->emptyStateDescription(__('email_templates.empty.description'));
    }
}
