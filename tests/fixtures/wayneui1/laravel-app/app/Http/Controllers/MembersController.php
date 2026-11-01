<?php

namespace App\Http\Controllers;

use WSU\WayneUi\Breadcrumbs;

class MembersController extends Controller
{
    public function index(): \Illuminate\Contracts\Support\Renderable
    {
        Breadcrumbs::pushAsHeader('Members');
        Breadcrumbs::setSubTitle('Fall term');

        return view('members');
    }
}
