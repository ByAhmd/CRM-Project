<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * An owned record that remembers who handed it to its current owner (D-14).
 *
 * RecordAssignmentService fills the assigner column with the acting user on
 * every reassignment — and clears it when the record is unassigned — and the
 * creating service stamps it when a record is born already assigned. The
 * column is written through forceFill by those trusted services only: it is
 * never fillable and never part of a form.
 */
interface TracksAssigner
{
    public static function assignerColumn(): string;
}
