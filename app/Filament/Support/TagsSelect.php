<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tag;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tag pickers, columns and filters shared by every taggable entity.
 */
final class TagsSelect
{
    public static function make(): Select
    {
        return Select::make('tags')
            ->label(__('common.fields.tags'))
            ->relationship('tags', Tag::localisedNameColumn(), fn (Builder $query): Builder => $query->where('is_active', true))
            ->getOptionLabelFromRecordUsing(fn (Model $record): string => (string) $record->getAttribute('display_name'))
            ->multiple()
            ->searchable()
            ->preload()
            ->native(false);
    }

    public static function column(): TextColumn
    {
        return TextColumn::make('tags')
            ->label(__('common.fields.tags'))
            ->state(fn (Model $record): array => $record->getRelationValue('tags')
                ->map(fn (Model $tag): string => (string) $tag->getAttribute('display_name'))
                ->all())
            ->badge()
            ->color('gray')
            ->toggleable();
    }

    public static function filter(): SelectFilter
    {
        return SelectFilter::make('tags')
            ->label(__('common.fields.tags'))
            ->relationship('tags', Tag::localisedNameColumn())
            ->getOptionLabelFromRecordUsing(fn (Model $record): string => (string) $record->getAttribute('display_name'))
            ->multiple()
            ->preload();
    }
}
