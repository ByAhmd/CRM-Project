<?php

declare(strict_types=1);

namespace App\Services\Contacts;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Lead;
use App\Support\Normalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Exact-match duplicate detection on normalised email / phone (decision A-11)
 * and, for accounts, on the case-insensitive name. Warns, never blocks: the
 * caller decides what to do with the matches.
 */
final class DuplicateFinder
{
    /**
     * @return Collection<int, Account>
     */
    public function accounts(?string $name, ?string $email, ?string $phone, ?int $excludeId = null): Collection
    {
        $email = Normalizer::email($email);
        $phone = Normalizer::phone($phone);
        $name = trim((string) $name);

        if ($email === null && $phone === null && $name === '') {
            return new Collection;
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
            })
            ->orderBy('name')
            ->limit(5)
            ->get();
    }

    /**
     * @return Collection<int, Lead>
     */
    public function leads(?string $email, ?string $phone, ?int $excludeId = null): Collection
    {
        $email = Normalizer::email($email);
        $phone = Normalizer::phone($phone);

        if ($email === null && $phone === null) {
            return new Collection;
        }

        return Lead::query()
            ->when($excludeId !== null, fn (Builder $query): Builder => $query->whereKeyNot($excludeId))
            ->where(function (Builder $query) use ($email, $phone): void {
                $query->whereRaw('1 = 0');

                if ($email !== null) {
                    $query->orWhere('email_normalized', $email);
                }

                if ($phone !== null) {
                    $query->orWhere('phone_normalized', $phone);
                }
            })
            ->orderBy('last_name')
            ->limit(5)
            ->get();
    }

    /**
     * @return Collection<int, Contact>
     */
    public function contacts(?string $email, ?string $phone, ?int $excludeId = null): Collection
    {
        $email = Normalizer::email($email);
        $phone = Normalizer::phone($phone);

        if ($email === null && $phone === null) {
            return new Collection;
        }

        return Contact::query()
            ->when($excludeId !== null, fn (Builder $query): Builder => $query->whereKeyNot($excludeId))
            ->where(function (Builder $query) use ($email, $phone): void {
                $query->whereRaw('1 = 0');

                if ($email !== null) {
                    $query->orWhere('email_normalized', $email);
                }

                if ($phone !== null) {
                    $query->orWhere('phone_normalized', $phone);
                }
            })
            ->orderBy('last_name')
            ->limit(5)
            ->get();
    }
}
