@extends('wayne-ui::with-sidebar')

@section('wayne-ui::main-content')
    <ul>
        <li>{!! navActive('forum') !!} Forum</li>
        <li><a href="{{ cacheBustingUrl('/js/app.js') }}"></a></li>
        <li>{{ showUserInfo() }}</li>
        <li>{{ $appUserId }} / {{ $appUserName }}</li>
    </ul>
@endsection
