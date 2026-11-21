<?php

namespace App\Http\Controllers\Legacy\Cake;

use Cake\Collection\Collection;

/**
 * Written by Booster's strangler (cakephp-to-laravel): a query's results,
 * a CakePHP Collection (cakephp/collection) as CakePHP's ResultSet is, so
 * extract(), combine(), reduce(), first() and the rest behave the same.
 */
final class CakeResultSet extends Collection {}
