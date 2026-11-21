<?php

namespace App\Http\Controllers\Legacy\Cake;

/**
 * Written by Booster's strangler (cakephp-to-laravel): an error a moved
 * action ends in, rendered as the legacy app's error page (CakeView::error)
 * with the status and message CakePHP's ExceptionRenderer gave it. `http`
 * is whether it was one of CakePHP's HttpExceptions, whose message the
 * page shows; any other exception shows "Not Found" or "An Internal Error
 * Has Occurred." when debug is off.
 */
final class CakeHttpException extends \RuntimeException
{
    /**
     * @param bool $asGuest the legacy app failed before sign-in ran (a CSRF check ahead of the Authentication middleware), so its page is the one a guest saw
     */
    public function __construct(public readonly int $status, string $message, public readonly bool $http = true, ?\Throwable $previous = null, public readonly bool $asGuest = false)
    {
        parent::__construct($message, $status, $previous);
    }

    /** The message CakePHP's ExceptionRenderer prints with debug off. */
    public function pageMessage(): string
    {
        if ($this->http) {
            return $this->getMessage();
        }

        return $this->status < 500 ? 'Not Found' : 'An Internal Error Has Occurred.';
    }

    public function render(\Illuminate\Http\Request $request): \Illuminate\Http\Response
    {
        return CakeView::error($request, $this->status, $this->pageMessage(), $this->asGuest);
    }
}
