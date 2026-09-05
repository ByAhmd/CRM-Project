<?php

declare(strict_types=1);

namespace App\Models;

use Spatie\Activitylog\Models\Activity;

/**
 * The audit ledger (decision A-5) — spatie's activity_log table.
 *
 * Rows are append-only (ActivityLogAppendOnlyObserver refuses updates and
 * deletes; retention pruning is the only way a row disappears). subject() and
 * causer() are deliberately not overridden: spatie's Activity::subject() drops
 * the SoftDeletingScope when activitylog.subject_returns_soft_deleted_models is
 * on, so audit rows keep naming soft-deleted subjects.
 */
final class ActivityLog extends Activity {}
