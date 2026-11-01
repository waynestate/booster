<?php

namespace App\Http\Controllers;

use WSU\WayneUi\Breadcrumbs;

class MembersController extends Controller
{
    public function index(): \Illuminate\Contracts\Support\Renderable
    {
        Breadcrumbs::push('Home', 'fa-home', '/');
        Breadcrumbs::pushAsHeader('Members');
        Breadcrumbs::pushAsSubHeader('Roster');
        Breadcrumbs::setSubHeader('Roster');
        Breadcrumbs::setHeader('Members');
        Breadcrumbs::setHeader(false);
        Breadcrumbs::setSubTitle('Fall term');
        Breadcrumbs::render();
        Breadcrumbs::pageHeader();

        return view('members.index');
    }
}
