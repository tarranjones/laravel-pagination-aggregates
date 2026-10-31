<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExprPost extends Model
{
    protected $table = 'expr_posts';

    protected $guarded = [];

    public $timestamps = false;

    public function exprComments(): HasMany
    {
        return $this->hasMany(ExprComment::class);
    }
}
