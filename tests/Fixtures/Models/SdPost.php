<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SdPost extends Model
{
    use SoftDeletes;

    protected $table = 'sd_posts';

    protected $guarded = [];

    public $timestamps = false;

    protected $dates = ['deleted_at'];

    public function sdComments(): HasMany
    {
        return $this->hasMany(SdComment::class);
    }
}
