<?php

declare(strict_types=1);

namespace App\Services\Contacts;

use App\Enums\ActivityLogEvent;
use App\Models\Account;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Contact;
use App\Models\CustomFieldValue;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Note;
use App\Models\Task;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Merges a duplicate contact or account into the record that is kept (A-11).
 *
 * Inside one transaction:
 *
 * - blank attributes on the kept record are filled from the duplicate;
 * - tags are unioned;
 * - every row that points at the duplicate is repointed to the kept record,
 *   soft-deleted rows included so a later restore lands on the survivor:
 *   an account's contacts, child accounts, deals, tasks, notes, activities,
 *   attachments, the leads converted into it and its custom-field values; a
 *   contact's deals (primary contact), deal roles, tasks, notes, activities,
 *   attachments, the leads converted into it and its custom-field values;
 * - a custom-field value moves only where the kept record has no value for
 *   that field, and a deal role moves only where the kept contact is not
 *   already on that deal (the kept role wins, a blank kept role is filled
 *   from the duplicate's) — the duplicate's leftovers stay on the trashed row;
 * - the duplicate is soft-deleted and the merge is written to the ledger with
 *   both ids and the ids of every relocated row, so it can always be
 *   explained.
 *
 * Deals move through Eloquent so each deal's own history records the new
 * account or primary contact. The other children move with set-based
 * updates: activities are immutable events (A-10) and the converted_* lead
 * columns are workflow-guarded, so an attribute save is not the right tool —
 * their moves are recorded in the merge event instead.
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

            $relocated = [
                'deals' => $this->moveDeals('contact_id', $keep, $duplicate),
                'deal_roles' => $this->moveDealRoles($keep, $duplicate),
                'tasks' => $this->moveColumn(Task::withTrashed(), 'contact_id', $keep, $duplicate),
                'notes' => $this->moveColumn(Note::withTrashed(), 'contact_id', $keep, $duplicate),
                'activities' => $this->moveColumn(Activity::query(), 'contact_id', $keep, $duplicate),
                'converted_leads' => $this->moveColumn(Lead::withTrashed(), 'converted_contact_id', $keep, $duplicate),
                'attachments' => $this->moveAttachments($keep, $duplicate),
                'custom_field_values' => $this->moveCustomFieldValues($keep, $duplicate),
            ];

            $keep->save();
            $duplicate->forceFill(['is_primary' => false])->save();
            $duplicate->delete();

            $this->audit->record(ActivityLogEvent::ContactMerged, $keep, $actor, [
                'subject_label' => $keep->full_name,
                'merged_id' => $duplicate->getKey(),
                'merged_label' => $duplicate->full_name,
                'relocated' => array_filter($relocated),
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

            // Never let the survivor become its own parent, nor keep the trashed duplicate as parent.
            if (in_array((int) $keep->parent_account_id, [(int) $keep->getKey(), (int) $duplicate->getKey()], true)) {
                $keep->parent_account_id = null;
            }

            if ($duplicate->isCustomer() && ! $keep->isCustomer()) {
                $keep->type = $duplicate->type;
            }

            $this->unionTags($keep, $duplicate);

            $relocated = [
                'contacts' => $this->moveColumn(Contact::withTrashed(), 'account_id', $keep, $duplicate),
                'child_accounts' => $this->moveColumn(
                    Account::withTrashed()->whereKeyNot($keep->getKey()),
                    'parent_account_id',
                    $keep,
                    $duplicate,
                ),
                'deals' => $this->moveDeals('account_id', $keep, $duplicate),
                'tasks' => $this->moveColumn(Task::withTrashed(), 'account_id', $keep, $duplicate),
                'notes' => $this->moveColumn(Note::withTrashed(), 'account_id', $keep, $duplicate),
                'activities' => $this->moveColumn(Activity::query(), 'account_id', $keep, $duplicate),
                'converted_leads' => $this->moveColumn(Lead::withTrashed(), 'converted_account_id', $keep, $duplicate),
                'attachments' => $this->moveAttachments($keep, $duplicate),
                'custom_field_values' => $this->moveCustomFieldValues($keep, $duplicate),
            ];

            $keep->save();
            $duplicate->delete();

            $this->audit->record(ActivityLogEvent::AccountMerged, $keep, $actor, [
                'subject_label' => $keep->name,
                'merged_id' => $duplicate->getKey(),
                'merged_label' => $duplicate->name,
                'relocated' => array_filter($relocated),
            ]);

            return $keep;
        });
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

    /**
     * Repoints every row of the query whose column names the duplicate.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return list<int>
     */
    private function moveColumn(Builder $query, string $column, Model $keep, Model $duplicate): array
    {
        $rows = (clone $query)->where($column, $duplicate->getKey());
        $ids = array_map('intval', $rows->pluck($rows->getModel()->getQualifiedKeyName())->all());

        if ($ids !== []) {
            // toBase(): a set-based write that fires no model events and adds no
            // updated_at to tables that have none (activities are append-only).
            $query->getModel()->newQueryWithoutScopes()->toBase()
                ->whereIn($query->getModel()->getKeyName(), $ids)
                ->update([$column => $keep->getKey()]);
        }

        return $ids;
    }

    /**
     * Deals are saved one by one so their own audit history records the move.
     *
     * @return list<int>
     */
    private function moveDeals(string $column, Account|Contact $keep, Account|Contact $duplicate): array
    {
        $ids = [];

        Deal::withTrashed()
            ->where($column, $duplicate->getKey())
            ->orderBy('id')
            ->get()
            ->each(function (Deal $deal) use ($column, $keep, &$ids): void {
                $deal->setAttribute($column, $keep->getKey());
                $deal->save();
                $ids[] = (int) $deal->getKey();
            });

        return $ids;
    }

    /**
     * Moves the duplicate's deal roles to the kept contact, deduplicated on
     * the (deal_id, contact_id) unique key.
     *
     * @return list<int> the deals whose role row was moved or folded
     */
    private function moveDealRoles(Contact $keep, Contact $duplicate): array
    {
        $roles = DB::table('deal_contacts')->where('contact_id', $duplicate->getKey())->get(['id', 'deal_id', 'role']);
        $deals = [];

        foreach ($roles as $row) {
            $existing = DB::table('deal_contacts')
                ->where('deal_id', $row->deal_id)
                ->where('contact_id', $keep->getKey())
                ->first(['id', 'role']);

            if ($existing === null) {
                DB::table('deal_contacts')->where('id', $row->id)->update([
                    'contact_id' => $keep->getKey(),
                    'updated_at' => now(),
                ]);
            } else {
                if ($existing->role === null && $row->role !== null) {
                    DB::table('deal_contacts')->where('id', $existing->id)->update([
                        'role' => $row->role,
                        'updated_at' => now(),
                    ]);
                }

                DB::table('deal_contacts')->where('id', $row->id)->delete();
            }

            $deals[] = (int) $row->deal_id;
        }

        return $deals;
    }

    /**
     * @return list<int>
     */
    private function moveAttachments(Account|Contact $keep, Account|Contact $duplicate): array
    {
        $rows = Attachment::withTrashed()
            ->where('attachable_type', $duplicate->getMorphClass())
            ->where('attachable_id', $duplicate->getKey());

        $ids = array_map('intval', $rows->pluck('id')->all());

        if ($ids !== []) {
            Attachment::withTrashed()->whereKey($ids)->toBase()->update([
                'attachable_type' => $keep->getMorphClass(),
                'attachable_id' => $keep->getKey(),
                'updated_at' => now(),
            ]);
        }

        return $ids;
    }

    /**
     * A value moves only for a field the kept record has no value for; the
     * unique (custom_field_id, entity_type, entity_id) key forbids a second.
     *
     * @return list<int>
     */
    private function moveCustomFieldValues(Account|Contact $keep, Account|Contact $duplicate): array
    {
        $taken = CustomFieldValue::query()
            ->where('entity_type', $keep->getMorphClass())
            ->where('entity_id', $keep->getKey())
            ->pluck('custom_field_id')
            ->all();

        $ids = array_map('intval', CustomFieldValue::query()
            ->where('entity_type', $duplicate->getMorphClass())
            ->where('entity_id', $duplicate->getKey())
            ->whereNotIn('custom_field_id', $taken)
            ->pluck('id')
            ->all());

        if ($ids !== []) {
            CustomFieldValue::query()->whereKey($ids)->toBase()->update([
                'entity_type' => $keep->getMorphClass(),
                'entity_id' => $keep->getKey(),
                'updated_at' => now(),
            ]);
        }

        return $ids;
    }
}
