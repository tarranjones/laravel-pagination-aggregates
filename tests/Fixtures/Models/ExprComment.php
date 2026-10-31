<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class ExprComment extends Model
{
    protected $table = 'expr_comments';

    protected $guarded = [];

    public $timestamps = false;
}
