<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Account;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Note;
use App\Models\Product;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Builds one demo dataset (step 13, `app:demo-data`) and records what it
 * created in DemoRegistry.
 *
 * The whole run is one database transaction: a refusal from any workflow
 * rolls every row back, and the files already written for attachments are
 * removed again. While it runs:
 *
 * - the clock is moved into the past for each step (DemoContext::at()) and
 *   restored afterwards, whatever happens;
 * - the queue connection is `sync`, so the notifications the workflows send
 *   are delivered into the demo users' bells when the transaction commits
 *   instead of waiting in the database queue for records a `--fresh` may
 *   already have removed (mail stays off: no demo user opts in to it);
 * - the acting demo user is signed in on the default guard for the audit
 *   causer, and whoever was signed in before is put back.
 *
 * Once the records exist, the rows the services wrote on the side — status
 * and stage logs, line items, the next occurrence of the recurring task, the
 * system activities of completed tasks, preferences, views and every audit
 * row caused by a demo user or about a demo record — are collected by their
 * relation to the demo users and records, so the registry lists every row
 * of the run.
 */
final class DemoDataBuilder
{
    private const CHUNK = 1000;

    public function __construct(
        private readonly DemoUsersBuilder $users,
        private readonly DemoAccountsBuilder $accounts,
        private readonly DemoLeadsBuilder $leads,
        private readonly DemoDealsBuilder $deals,
        private readonly DemoWorkBuilder $work,
        private readonly DemoRegistry $registry,
    ) {}

    /**
     * Existing rows a demo run would collide with (unique demo e-mails, team
     * names, product codes and names), as readable lines; empty when none.
     *
     * @return list<string>
     */
    public function conflicts(): array
    {
        $conflicts = [];

        $emails = array_map(static fn (array $user): string => $user['key'].'@'.DemoDataset::USER_DOMAIN, DemoDataset::users());
        $users = User::withTrashed()->whereIn('email', $emails)->pluck('email')->all();

        if ($users !== []) {
            $conflicts[] = 'users already exist with the demo e-mail addresses: '.implode(', ', $users);
        }

        $teams = Team::withTrashed()
            ->where(fn (Builder $query): Builder => $query
                ->whereIn('name_en', array_column(DemoDataset::teams(), 'name_en'))
                ->orWhereIn('name_ar', array_column(DemoDataset::teams(), 'name_ar')))
            ->pluck('name_en')
            ->all();

        if ($teams !== []) {
            $conflicts[] = 'teams already exist with the demo team names: '.implode(', ', $teams);
        }

        $products = Product::withTrashed()
            ->where(fn (Builder $query): Builder => $query
                ->whereIn('code', array_column(DemoDataset::products(), 'code'))
                ->orWhereIn('name_en', array_column(DemoDataset::products(), 'name_en'))
                ->orWhereIn('name_ar', array_column(DemoDataset::products(), 'name_ar')))
            ->pluck('code')
            ->all();

        if ($products !== []) {
            $conflicts[] = 'products already exist with the demo product codes or names: '.implode(', ', $products);
        }

        return $conflicts;
    }

    public function build(int $scale, string $password): DemoContext
    {
        $previousNow = Carbon::getTestNow();
        $previousQueue = config('queue.default');
        $guard = Auth::guard();
        $previousUser = $guard->hasUser() ? $guard->user() : null;
        $context = new DemoContext(Carbon::now(), $scale);

        config(['queue.default' => 'sync']);

        try {
            DB::transaction(function () use ($context, $scale, $password, $previousNow): void {
                $this->users->build($context, $password);

                for ($batch = 0; $batch < $scale; $batch++) {
                    $this->accounts->build($context, $batch);
                    $this->leads->build($context, $batch);
                    $this->deals->build($context, $batch);
                    $this->work->build($context, $batch);
                }

                Carbon::setTestNow($previousNow);

                $this->registry->save($this->collect($context), $scale, $context->now->toIso8601String());
            });
        } catch (Throwable $exception) {
            foreach ($context->attachments as $attachment) {
                Storage::disk($attachment->disk)->delete($attachment->path);
            }

            throw $exception;
        } finally {
            Carbon::setTestNow($previousNow);
            config(['queue.default' => $previousQueue]);
            $this->restoreUser($previousUser);
        }

        return $context;
    }

    /**
     * Every row of the run, by table.
     *
     * @return array<string, list<int>>
     */
    private function collect(DemoContext $context): array
    {
        $ids = $context->ids();
        $users = $context->idsOf('users');
        $leads = $context->idsOf('leads');
        $deals = $context->idsOf('deals');

        $ids['lead_status_logs'] = $this->pluck('lead_status_logs', 'lead_id', $leads);
        $ids['deal_stage_logs'] = $this->pluck('deal_stage_logs', 'deal_id', $deals);
        $ids['deal_products'] = $this->pluck('deal_products', 'deal_id', $deals);
        $ids['activities'] = $this->pluck('activities', 'created_by', $users);
        $ids['tasks'] = $this->pluck('tasks', 'created_by', $users);
        $ids['notes'] = $this->pluck('notes', 'author_id', $users);
        $ids['saved_views'] = $this->pluck('saved_views', 'user_id', $users);
        $ids['notification_preferences'] = $this->pluck('notification_preferences', 'user_id', $users);

        $audit = $this->pluck('activity_log', 'causer_id', $users, ['causer_type' => (new User)->getMorphClass()]);

        $subjects = [
            User::class => $users,
            Team::class => $context->idsOf('teams'),
            Account::class => $context->idsOf('accounts'),
            Contact::class => $context->idsOf('contacts'),
            Lead::class => $leads,
            Deal::class => $deals,
            Task::class => $ids['tasks'],
            Activity::class => $ids['activities'],
            Note::class => $ids['notes'],
            Attachment::class => $context->idsOf('attachments'),
            Product::class => $context->idsOf('products'),
        ];

        foreach ($subjects as $class => $subjectIds) {
            $audit = [...$audit, ...$this->pluck('activity_log', 'subject_id', $subjectIds, ['subject_type' => (new $class)->getMorphClass()])];
        }

        $ids['activity_log'] = $audit;

        return array_map(static fn (array $tableIds): array => array_values(array_unique($tableIds)), $ids);
    }

    /**
     * @param  list<int>  $values
     * @param  array<string, string>  $where
     * @return list<int>
     */
    private function pluck(string $table, string $column, array $values, array $where = []): array
    {
        $ids = [];

        foreach (array_chunk($values, self::CHUNK) as $chunk) {
            $query = DB::table($table)->whereIn($column, $chunk);

            foreach ($where as $key => $value) {
                $query->where($key, $value);
            }

            foreach ($query->pluck('id') as $id) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    private function restoreUser(?Authenticatable $previous): void
    {
        $guard = Auth::guard();

        if ($previous !== null) {
            $guard->setUser($previous);

            return;
        }

        $guard->forgetUser();
    }
}
