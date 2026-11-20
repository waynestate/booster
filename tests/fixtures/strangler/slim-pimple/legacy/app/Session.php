<?php
namespace App;

class Session
{
    public function get(string $key)
    {
        return $_SESSION[$key] ?? null;
    }
}
