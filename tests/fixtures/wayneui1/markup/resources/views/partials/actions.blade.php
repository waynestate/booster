@extends('wayne-ui::with-sidebar')

@section('wayne-ui::main-content')
    <div class="btn-group">
        <button type="button" class="btn {{ $state }}">Save</button>
        <button type="button" class="wsu-btn">Already New</button>
    </div>
    <label for="name">Name</label>
    <span class="label">Old</span>
    <span class="label label-success label-info">Badges</span>
    <button type="button" class="btn btn-xs btn-sm">Small</button>
    <i class="fa fa-trash fa-lg"></i>
    <i class="fa fa-heart"></i>
    <i class="fa fa-unknown-icon"></i>
    <i class="glyphicon glyphicon-star"></i>
    <i class="fa fa-book">book</i>
    <input type="text" class="form-control" style="color: #007a7a; border-color: #066666;">
@endsection
