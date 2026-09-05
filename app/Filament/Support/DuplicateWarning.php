<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Services\Contacts\DuplicateFinder;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Live "possible duplicate" hint on create/edit forms (decision A-11). Warns,
 * never blocks; the matched records are named so the user can decide.
 */
final class DuplicateWarning
{
    public static function forAccount(): Placeholder
    {
        return Placeholder::make('duplicate_warning')
            ->hiddenLabel()
            ->content(function (Get $get, ?Model $record): Htmlable {
                $matches = app(DuplicateFinder::class)->accounts(
                    name: (string) $get('name'),
                    email: (string) $get('email'),
                    phone: (string) $get('phone'),
                    excludeId: $record?->getKey(),
                );

                return self::render($matches->pluck('name')->all());
            })
            ->visible(fn (Get $get, ?Model $record): bool => app(DuplicateFinder::class)->accounts(
                name: (string) $get('name'),
                email: (string) $get('email'),
                phone: (string) $get('phone'),
                excludeId: $record?->getKey(),
            )->isNotEmpty());
    }

    public static function forContact(): Placeholder
    {
        return Placeholder::make('duplicate_warning')
            ->hiddenLabel()
            ->content(function (Get $get, ?Model $record): Htmlable {
                $matches = app(DuplicateFinder::class)->contacts(
                    email: (string) $get('email'),
                    phone: (string) ($get('mobile') ?: $get('phone')),
                    excludeId: $record?->getKey(),
                );

                return self::render($matches->map(fn ($contact): string => $contact->full_name)->all());
            })
            ->visible(fn (Get $get, ?Model $record): bool => app(DuplicateFinder::class)->contacts(
                email: (string) $get('email'),
                phone: (string) ($get('mobile') ?: $get('phone')),
                excludeId: $record?->getKey(),
            )->isNotEmpty());
    }

    /**
     * Leads are checked against other leads AND existing contacts, so a rep
     * learns the person is already a customer before working the lead.
     */
    public static function forLead(): Placeholder
    {
        $matches = static function (Get $get, ?Model $record): array {
            $finder = app(DuplicateFinder::class);
            $email = (string) $get('email');
            $phone = (string) $get('phone');

            $leads = $finder->leads($email, $phone, $record?->getKey())->map(fn ($lead): string => __('merge.warnings.lead', ['name' => $lead->full_name]))->all();
            $contacts = $finder->contacts($email, $phone)->map(fn ($contact): string => __('merge.warnings.contact', ['name' => $contact->full_name]))->all();

            return [...$leads, ...$contacts];
        };

        return Placeholder::make('duplicate_warning')
            ->hiddenLabel()
            ->content(fn (Get $get, ?Model $record): Htmlable => self::render($matches($get, $record)))
            ->visible(fn (Get $get, ?Model $record): bool => $matches($get, $record) !== []);
    }

    /**
     * @param  list<string>  $names
     */
    private static function render(array $names): Htmlable
    {
        $list = implode('، ', array_map(static fn (string $name): string => e($name), $names));

        return new HtmlString(sprintf(
            '<div class="rounded-lg border border-warning-300 bg-warning-50 px-3 py-2 text-sm text-warning-800 dark:border-warning-500/40 dark:bg-warning-500/10 dark:text-warning-200">%s</div>',
            e(__('merge.warnings.possible_duplicates')).' '.$list,
        ));
    }
}
