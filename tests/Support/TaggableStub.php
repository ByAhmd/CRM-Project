<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;

/**
 * A minimal taggable record that isolates the HasTags trait from the real
 * taggable entities (lead, contact, account, deal) and their observers,
 * audit logging and policies. Backed by a temporary table the test creates
 * for itself.
 */
final class TaggableStub extends Model
{
    use HasTags;

    protected $table = 'taggable_stubs';

    protected $guarded = [];
}
