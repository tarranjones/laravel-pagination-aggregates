<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class HoUser extends Model
{
    protected $table = 'ho_users';

    protected $guarded = [];

    public $timestamps = false;

    public function hoProfile(): HasOne
    {
        return $this->hasOne(HoProfile::class);
    }
}
