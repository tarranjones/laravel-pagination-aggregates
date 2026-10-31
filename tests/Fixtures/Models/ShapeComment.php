<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class ShapeComment extends Model
{
    protected $table = 'shape_comments';

    protected $guarded = [];

    public $timestamps = false;
}
