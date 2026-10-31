<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShapePost extends Model
{
    protected $table = 'shape_posts';

    protected $guarded = [];

    public $timestamps = false;

    public function shapeComments(): HasMany
    {
        return $this->hasMany(ShapeComment::class);
    }
}
