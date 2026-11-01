<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Reaction extends Model
{
    protected $table = 'reactions';

    protected $guarded = [];

    public $timestamps = false;

    public function reactable(): MorphTo
    {
        return $this->morphTo();
    }
}
