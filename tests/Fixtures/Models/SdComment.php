<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SdComment extends Model
{
    use SoftDeletes;

    protected $table = 'sd_comments';

    protected $guarded = [];

    public $timestamps = false;

    protected $dates = ['deleted_at'];
}
