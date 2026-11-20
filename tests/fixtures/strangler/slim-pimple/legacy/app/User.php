<?php
namespace App;

class User
{
    public array $roles = [];

    public function __construct(private Session $session)
    {
    }

    public function isAuth(): bool
    {
        return (bool) $this->session->get('id');
    }
}
