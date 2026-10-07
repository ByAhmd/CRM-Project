<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Enums\MyWorkBucket;
use App\Models\Task;

/**
 * One bucket of «عملي» (My Work, D-27) as MyWorkFeed reads it: how many of
 * the reader's tasks fall in it, and the first of them in the bucket's own
 * order (soonest due first; the recently completed newest first), cut to
 * MyWorkFeed::TASKS_PER_BUCKET.
 */
final readonly class MyWorkSection
{
    /**
     * @param  list<Task>  $tasks
     */
    public function __construct(
        public MyWorkBucket $bucket,
        public int $total,
        public array $tasks,
    ) {}

    /** How many tasks of the bucket the page does not list. */
    public function more(): int
    {
        return max(0, $this->total - count($this->tasks));
    }

    public function isEmpty(): bool
    {
        return $this->total === 0;
    }
}
