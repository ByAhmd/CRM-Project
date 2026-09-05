<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * The pivot side of tagging (decision A-4).
 *
 * Any entity that can carry tags (lead, contact, account, deal) uses this
 * trait and gains the `tags` relation through the keyless `taggables` pivot.
 * The Tag model itself stays ignorant of who uses it, so adding a taggable
 * entity is one `use` statement and nothing else.
 */
trait HasTags
{
    /**
     * @return MorphToMany<Tag, $this>
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable', 'taggables');
    }
}
