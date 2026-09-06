<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * The subject side of attachments (module row 13).
 *
 * Any entity that can carry files (lead, contact, account, deal) uses this
 * trait and gains the `attachments` relation, newest first. Reading an
 * attachment follows the visibility of the subject; the relation itself
 * carries no scope because the subject was already resolved by its policy.
 */
trait HasAttachments
{
    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}
