<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $table = 'orders';

    protected $guarded = [];

    public $timestamps = false;

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }
}
