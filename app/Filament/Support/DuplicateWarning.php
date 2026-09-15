<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\User;
use App\Services\Contacts\DuplicateFinder;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Live "possible duplicate" hint on create/edit forms (decision A-11). Warns,
 * never blocks.
 *
 * Matches inside the signed-in user's reach are named so the user can decide;
 * a match outside it is only acknowledged with a generic sentence that carries
 * no identifying data, so typing an email or phone never reveals whose record
 * it is (D-4). Without a signed-in user nothing is shown.
 */
final class DuplicateWarning
{
    public static function forAccount(): Placeholder
    {
        $warning = static function (Get $get, ?Model $record): ?string {
            $viewer = self::viewer();

            if ($viewer === null) {
                return null;
            }

            $finder = app(DuplicateFinder::class);
            $name = (string) $get('name');
            $email = (string) $get('email');
            $phone = (string) $get('phone');
            $excludeId = self::key($record);

            $names = $finder->accounts($name, $email, $phone, $excludeId, $viewer)
                ->map(fn (Account $account): string => $account->name)
                ->all();

            return self::message($names, $finder->existsOutsideReach(DuplicateFinder::ENTITY_ACCOUNT, $viewer, $name, $email, $phone, $excludeId));
        };

        return self::placeholder($warning);
    }

    public static function forContact(): Placeholder
    {
        $warning = static function (Get $get, ?Model $record): ?string {
            $viewer = self::viewer();

            if ($viewer === null) {
                return null;
            }

            $finder = app(DuplicateFinder::class);
            $email = (string) $get('email');
            $phone = (string) ($get('mobile') ?: $get('phone'));
            $excludeId = self::key($record);

            $names = $finder->contacts($email, $phone, $excludeId, $viewer)
                ->map(fn (Contact $contact): string => $contact->full_name)
                ->all();

            return self::message($names, $finder->existsOutsideReach(DuplicateFinder::ENTITY_CONTACT, $viewer, null, $email, $phone, $excludeId));
        };

        return self::placeholder($warning);
    }

    /**
     * Leads are checked against other leads AND existing contacts, so a rep
     * learns the person is already a customer before working the lead.
     */
    public static function forLead(): Placeholder
    {
        $warning = static function (Get $get, ?Model $record): ?string {
            $viewer = self::viewer();

            if ($viewer === null) {
                return null;
            }

            $finder = app(DuplicateFinder::class);
            $email = (string) $get('email');
            $phone = (string) $get('phone');
            $excludeId = self::key($record);

            $leads = $finder->leads($email, $phone, $excludeId, $viewer)
                ->map(fn (Lead $lead): string => __('merge.warnings.lead', ['name' => $lead->full_name]))
                ->all();
            $contacts = $finder->contacts($email, $phone, null, $viewer)
                ->map(fn (Contact $contact): string => __('merge.warnings.contact', ['name' => $contact->full_name]))
                ->all();

            $hidden = $finder->existsOutsideReach(DuplicateFinder::ENTITY_LEAD, $viewer, null, $email, $phone, $excludeId)
                || $finder->existsOutsideReach(DuplicateFinder::ENTITY_CONTACT, $viewer, null, $email, $phone);

            return self::message([...$leads, ...$contacts], $hidden);
        };

        return self::placeholder($warning);
    }

    /**
     * @param  callable(Get, ?Model): ?string  $warning
     */
    private static function placeholder(callable $warning): Placeholder
    {
        return Placeholder::make('duplicate_warning')
            ->hiddenLabel()
            ->content(fn (Get $get, ?Model $record): Htmlable => self::render((string) $warning($get, $record)))
            ->visible(fn (Get $get, ?Model $record): bool => $warning($get, $record) !== null);
    }

    /**
     * The warning text: the named matches in reach, then — when a match exists
     * only beyond reach, or in addition — the generic sentence. Null when
     * there is nothing to warn about.
     *
     * @param  list<string>  $names
     */
    private static function message(array $names, bool $existsOutsideReach): ?string
    {
        $parts = [];

        if ($names !== []) {
            $parts[] = __('merge.warnings.possible_duplicates').' '.implode(__('common.separators.list'), $names);
        }

        if ($existsOutsideReach) {
            $parts[] = __('merge.warnings.outside_reach');
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    private static function render(string $message): Htmlable
    {
        return new HtmlString(sprintf(
            '<div class="rounded-lg border border-warning-300 bg-warning-50 px-3 py-2 text-sm text-warning-800 dark:border-warning-500/40 dark:bg-warning-500/10 dark:text-warning-200">%s</div>',
            e($message),
        ));
    }

    private static function viewer(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    private static function key(?Model $record): ?int
    {
        $key = $record?->getKey();

        return $key === null ? null : (int) $key;
    }
}
