<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class HoProfile extends Model
{
    protected $table = 'ho_profiles';

    protected $guarded = [];

    public $timestamps = false;
}
