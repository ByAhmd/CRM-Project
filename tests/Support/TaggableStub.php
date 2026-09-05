<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;

/**
 * A minimal taggable record for exercising the HasTags trait before the real
 * taggable entities (lead, contact, account, deal) exist. Backed by a
 * temporary table the test creates for itself.
 */
final class TaggableStub extends Model
{
    use HasTags;

    protected $table = 'taggable_stubs';

    protected $guarded = [];
}
