<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * What one demo run has built so far, shared by the builders.
 *
 * - `now` is the real moment the run started; history is spread before it by
 *   running each step at a moment in the past (`at()`, Carbon::setTestNow(),
 *   restored by DemoDataBuilder in a finally);
 * - `as()` runs a step signed in as the acting demo user, so the audit rows
 *   LogsActivity and AuditLogger write name the right causer even though the
 *   command runs outside a web request;
 * - the created ids are collected per table for DemoRegistry.
 */
final class DemoContext
{
    /** @var array<string, User> key => user */
    public array $users = [];

    /** @var array<string, Team> key => team */
    public array $teams = [];

    /** @var list<Product> */
    public array $products = [];

    /** @var list<Account> */
    public array $accounts = [];

    /** @var array<int, list<Account>> batch => the accounts the batch created directly */
    public array $accountsByBatch = [];

    /** @var array<int, list<Contact>> account id => contacts */
    public array $contactsByAccount = [];

    /** @var list<Contact> */
    public array $contacts = [];

    /** @var list<Lead> */
    public array $leads = [];

    /** @var list<Deal> */
    public array $deals = [];

    /** @var array<int, list<Deal>> batch => every deal of the batch (direct and converted) */
    public array $dealsByBatch = [];

    /** @var array<int, Deal> overall lead position => the deal its conversion opened */
    public array $convertedDeals = [];

    /** @var list<Attachment> */
    public array $attachments = [];

    /** @var array<string, list<int>> table => ids */
    private array $ids = [];

    public function __construct(
        public readonly Carbon $now,
        public readonly int $scale,
    ) {}

    public function user(string $key): User
    {
        return $this->users[$key] ?? throw new InvalidArgumentException(sprintf('Unknown demo user "%s".', $key));
    }

    /** The manager of the team the user belongs to, or the user when they have no team. */
    public function managerOf(User $user): User
    {
        $managerId = (int) ($this->teamOf($user)->manager_user_id ?? 0);

        foreach ($this->users as $candidate) {
            if ((int) $candidate->getKey() === $managerId) {
                return $candidate;
            }
        }

        return $user;
    }

    /**
     * A moment in the past: now minus the given days and hours, never later
     * than a minute before the run started.
     */
    public function ago(float $days, int $hours = 0): Carbon
    {
        $moment = $this->now->copy()->subMinutes((int) round($days * 24 * 60))->subHours($hours);
        $latest = $this->now->copy()->subMinute();

        return $moment->greaterThan($latest) ? $latest : $moment;
    }

    /**
     * Runs the step with the clock set to the given moment (at the latest a
     * minute before the run started, so no history lies in the future).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $step
     * @return TReturn
     */
    public function at(Carbon $moment, Closure $step): mixed
    {
        $latest = $this->now->copy()->subMinute();

        Carbon::setTestNow($moment->greaterThan($latest) ? $latest : $moment);

        return $step();
    }

    /**
     * Runs the step at the given moment, signed in as the actor.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $step
     * @return TReturn
     */
    public function as(User $actor, Carbon $moment, Closure $step): mixed
    {
        Auth::guard()->setUser($actor);

        return $this->at($moment, $step);
    }

    /**
     * @param  int|list<int>  $ids
     */
    public function record(string $table, int|array $ids): void
    {
        foreach ((array) $ids as $id) {
            $this->ids[$table][] = (int) $id;
        }
    }

    /**
     * @return array<string, list<int>>
     */
    public function ids(): array
    {
        return $this->ids;
    }

    /** @return list<int> */
    public function idsOf(string $table): array
    {
        return array_values(array_unique($this->ids[$table] ?? []));
    }

    /** The demo user with the given id. */
    public function userById(mixed $id): User
    {
        foreach ($this->users as $user) {
            if ((int) $user->getKey() === (int) $id) {
                return $user;
            }
        }

        throw new InvalidArgumentException(sprintf('No demo user has the id %s.', is_scalar($id) ? (string) $id : '?'));
    }

    private function teamOf(User $user): ?Team
    {
        foreach ($this->teams as $team) {
            if ((int) $team->getKey() === (int) $user->team_id) {
                return $team;
            }
        }

        return null;
    }
}
