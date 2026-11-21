<?php

namespace App\Http\Controllers\Legacy\Cake;

use Illuminate\Database\Eloquent\Model;

/**
 * Written by Booster's strangler (cakephp-to-laravel): the row of a table
 * the legacy app has no Entity class for (every field accessible, as
 * CakePHP's plain Entity is).
 */
final class CakePlainEntity extends Model
{
    use CakeEntity;

    public $timestamps = false;

    /** @var array<string, bool> */
    protected array $_accessible = ['*' => true];
}
