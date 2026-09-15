<?php

declare(strict_types=1);

namespace App\Services\Contacts;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Support\Normalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Exact-match duplicate detection on normalised email / phone (decision A-11)
 * and, for accounts, on the case-insensitive name. Warns, never blocks: the
 * caller decides what to do with the matches.
 *
 * Given a viewer, every lookup is confined to the records that viewer may
 * read (RecordVisibilityResolver, D-4), so the answer never names a record
 * outside their reach; existsOutsideReach() then tells whether a match exists
 * beyond it, without identifying it. Without a viewer the lookup is
 * organisation-wide, for trusted server-side callers only (the merge service,
 * tests).
 */
final class DuplicateFinder
{
    public const string ENTITY_ACCOUNT = 'account';

    public const string ENTITY_LEAD = 'lead';

    public const string ENTITY_CONTACT = 'contact';

    private const int LIMIT = 5;

    public function __construct(
        private readonly RecordVisibilityResolver $visibility,
    ) {}

    /**
     * @return Collection<int, Account>
     */
    public function accounts(?string $name, ?string $email, ?string $phone, ?int $excludeId = null, ?User $viewer = null): Collection
    {
        $query = $this->accountQuery($name, $email, $phone, $excludeId);

        if ($query === null) {
            return new Collection;
        }

        return $this->scoped($query, $viewer)
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * @return Collection<int, Lead>
     */
    public function leads(?string $email, ?string $phone, ?int $excludeId = null, ?User $viewer = null): Collection
    {
        $query = $this->contactPointQuery(Lead::query(), $email, $phone, $excludeId);

        if ($query === null) {
            return new Collection;
        }

        return $this->scoped($query, $viewer)
            ->orderBy('last_name')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * @return Collection<int, Contact>
     */
    public function contacts(?string $email, ?string $phone, ?int $excludeId = null, ?User $viewer = null): Collection
    {
        $query = $this->contactPointQuery(Contact::query(), $email, $phone, $excludeId);

        if ($query === null) {
            return new Collection;
        }

        return $this->scoped($query, $viewer)
            ->orderBy('last_name')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * Whether a record of the entity outside the viewer's reach matches. Answers
     * yes or no only, so the caller can warn without naming anything (A-11).
     *
     * @param  self::ENTITY_*  $entity
     */
    public function existsOutsideReach(string $entity, User $viewer, ?string $name, ?string $email, ?string $phone, ?int $excludeId = null): bool
    {
        return match ($entity) {
            self::ENTITY_ACCOUNT => $this->matchesBeyond($this->accountQuery($name, $email, $phone, $excludeId), Account::query(), $viewer),
            self::ENTITY_LEAD => $this->matchesBeyond($this->contactPointQuery(Lead::query(), $email, $phone, $excludeId), Lead::query(), $viewer),
            self::ENTITY_CONTACT => $this->matchesBeyond($this->contactPointQuery(Contact::query(), $email, $phone, $excludeId), Contact::query(), $viewer),
        };
    }

    /**
     * Whether the matching query finds a row the visible query does not.
     *
     * @template TModel of Account|Contact|Lead
     *
     * @param  Builder<TModel>|null  $matches
     * @param  Builder<TModel>  $all
     */
    private function matchesBeyond(?Builder $matches, Builder $all, User $viewer): bool
    {
        if ($matches === null) {
            return false;
        }

        $key = $matches->getModel()->getQualifiedKeyName();
        $visibleIds = $this->visibility->visible($viewer, $all)->select($key);

        return $matches->whereNotIn($key, $visibleIds)->exists();
    }

    /**
     * @template TModel of Account|Contact|Lead
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function scoped(Builder $query, ?User $viewer): Builder
    {
        return $viewer === null ? $query : $this->visibility->visible($viewer, $query);
    }

    /**
     * Accounts matching the name, email or phone; null when nothing was given.
     *
     * @return Builder<Account>|null
     */
    private function accountQuery(?string $name, ?string $email, ?string $phone, ?int $excludeId): ?Builder
    {
        $email = Normalizer::email($email);
        $phone = Normalizer::phone($phone);
        $name = trim((string) $name);

        if ($email === null && $phone === null && $name === '') {
            return null;
        }

        return Account::query()
            ->when($excludeId !== null, fn (Builder $query): Builder => $query->whereKeyNot($excludeId))
            ->where(function (Builder $query) use ($name, $email, $phone): void {
                $query->whereRaw('1 = 0');

                if ($name !== '') {
                    $query->orWhereRaw('LOWER(name) = ?', [mb_strtolower($name)]);
                }

                if ($email !== null) {
                    $query->orWhere('email_normalized', $email);
                }

                if ($phone !== null) {
                    $query->orWhere('phone_normalized', $phone);
                }
            });
    }

    /**
     * Leads or contacts matching the email or phone; null when neither was given.
     *
     * @template TModel of Contact|Lead
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>|null
     */
    private function contactPointQuery(Builder $query, ?string $email, ?string $phone, ?int $excludeId): ?Builder
    {
        $email = Normalizer::email($email);
        $phone = Normalizer::phone($phone);

        if ($email === null && $phone === null) {
            return null;
        }

        return $query
            ->when($excludeId !== null, fn (Builder $scoped): Builder => $scoped->whereKeyNot($excludeId))
            ->where(function (Builder $scoped) use ($email, $phone): void {
                $scoped->whereRaw('1 = 0');

                if ($email !== null) {
                    $scoped->orWhere('email_normalized', $email);
                }

                if ($phone !== null) {
                    $scoped->orWhere('phone_normalized', $phone);
                }
            });
    }
}
