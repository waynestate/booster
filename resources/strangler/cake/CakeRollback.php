<?php

namespace App\Http\Controllers\Legacy\Cake;

/**
 * Written by Booster's strangler (cakephp-to-laravel): rolls a save's
 * transaction back when an associated row fails, as CakePHP's atomic save
 * does.
 */
final class CakeRollback extends \RuntimeException {}
