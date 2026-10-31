<?php

namespace Example;

class Timestamps
{
    public function render(int $t): string
    {
        return Carbon::createFromTimestamp($t)->toDateTimeString();
    }
}
