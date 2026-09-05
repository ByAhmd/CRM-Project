<?php

declare(strict_types=1);

namespace App\Services\Contacts;

use App\Enums\ActivityLogEvent;
use App\Models\Account;
use App\Models\Contact;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Merges a duplicate contact or account into the record that is kept (A-11).
 *
 * - blank attributes on the kept record are filled from the duplicate;
 * - tags are unioned;
 * - related rows are repointed to the kept record (contacts of a merged
 *   account now; leads, deals, activities, tasks, notes and attachments are
 *   repointed by their own modules through relocations());
 * - the duplicate is soft-deleted and the merge is written to the ledger with
 *   both ids, so it can always be explained.
 */
final class RecordMerger
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function mergeContacts(Contact $keep, Contact $duplicate, User $actor): Contact
    {
        $this->guard($keep, $duplicate);

        return DB::transaction(function () use ($keep, $duplicate, $actor): Contact {
            $this->fillBlanks($keep, $duplicate, [
                'account_id', 'job_title', 'department', 'email', 'phone', 'mobile', 'preferred_locale',
                'linkedin_url', 'address_line', 'city', 'region', 'country', 'postal_code', 'description',
            ]);
            $this->unionTags($keep, $duplicate);

            foreach (self::relocations('contact') as $relocate) {
                $relocate($keep, $duplicate);
            }

            $keep->save();
            $duplicate->forceFill(['is_primary' => false])->save();
            $duplicate->delete();

            $this->audit->record(ActivityLogEvent::ContactMerged, $keep, $actor, [
                'subject_label' => $keep->full_name,
                'merged_id' => $duplicate->getKey(),
                'merged_label' => $duplicate->full_name,
            ]);

            return $keep;
        });
    }

    public function mergeAccounts(Account $keep, Account $duplicate, User $actor): Account
    {
        $this->guard($keep, $duplicate);

        return DB::transaction(function () use ($keep, $duplicate, $actor): Account {
            $this->fillBlanks($keep, $duplicate, [
                'industry_id', 'size', 'website', 'email', 'phone', 'address_line', 'city', 'region',
                'country', 'postal_code', 'parent_account_id', 'customer_since', 'description',
            ]);

            if ($duplicate->isCustomer() && ! $keep->isCustomer()) {
                $keep->type = $duplicate->type;
            }

            $this->unionTags($keep, $duplicate);

            Contact::query()->where('account_id', $duplicate->getKey())->update(['account_id' => $keep->getKey()]);

            foreach (self::relocations('account') as $relocate) {
                $relocate($keep, $duplicate);
            }

            $keep->save();
            $duplicate->delete();

            $this->audit->record(ActivityLogEvent::AccountMerged, $keep, $actor, [
                'subject_label' => $keep->name,
                'merged_id' => $duplicate->getKey(),
                'merged_label' => $duplicate->name,
            ]);

            return $keep;
        });
    }

    /**
     * Relocation steps registered by later modules (leads, deals, activities,
     * tasks, notes, attachments) so a merge never orphans their rows.
     *
     * @var array<string, list<callable(Model, Model): void>>
     */
    private static array $relocations = [];

    /**
     * @param  callable(Model, Model): void  $relocation
     */
    public static function registerRelocation(string $entity, callable $relocation): void
    {
        self::$relocations[$entity][] = $relocation;
    }

    /**
     * @return list<callable(Model, Model): void>
     */
    public static function relocations(string $entity): array
    {
        return self::$relocations[$entity] ?? [];
    }

    private function guard(Model $keep, Model $duplicate): void
    {
        if ($keep->is($duplicate)) {
            throw new \InvalidArgumentException('A record cannot be merged into itself.');
        }
    }

    /**
     * @param  list<string>  $attributes
     */
    private function fillBlanks(Model $keep, Model $duplicate, array $attributes): void
    {
        foreach ($attributes as $attribute) {
            $current = $keep->getAttribute($attribute);

            if ($current === null || $current === '') {
                $keep->setAttribute($attribute, $duplicate->getAttribute($attribute));
            }
        }
    }

    private function unionTags(Account|Contact $keep, Account|Contact $duplicate): void
    {
        $ids = $duplicate->tags()->pluck('tags.id')->all();

        if ($ids !== []) {
            $keep->tags()->syncWithoutDetaching($ids);
        }
    }
}
