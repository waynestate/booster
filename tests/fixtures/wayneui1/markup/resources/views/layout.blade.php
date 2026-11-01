<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Example App</title>
    <link rel="stylesheet" href="/css/app.css">
    <script src="/js/jquery.min.js"></script>
    <script src="/js/bootstrap.min.js"></script>
    <style>
        .brand { color: #006666; }
        .accent { background: #cfdedc; }
    </style>
</head>
<body class="wayne-ui-main non-production">
    <nav class="navbar navbar-default navbar-fixed-top">
        <div class="container-fluid">
            <button type="button" class="navbar-toggle" data-toggle="collapse" data-target="#menu">
                <span class="sr-only">Toggle navigation</span>
            </button>
            <a class="navbar-brand" href="/">Example App</a>
            <img class="navbar-logo" src="/img/logo.png" alt="">
        </div>
    </nav>
    <div class="row">
        <div class="col-sm-8">
            <div class="page-header">
                <h1>Members</h1>
            </div>
            <ol class="breadcrumb">
                <li><a href="/">Home</a></li>
            </ol>
            <div class="alert alert-warning" data-dismiss="alert">
                <p>Your session will expire soon.</p>
            </div>
            <div class="panel panel-default">
                <div class="panel-heading"><span class="panel-title">Roster</span></div>
                <div class="panel-body">
                    <table class="table table-striped">
                        <thead><tr><th>Name</th></tr></thead>
                        <tbody><tr><td>A. Example</td></tr></tbody>
                    </table>
                </div>
                <div class="panel-footer">
                    <button type="button" class="btn btn-default">Close</button>
                </div>
            </div>
            <div class="well">
                <form action="/search" method="get">
                    <div class="form-group has-error">
                        <label class="control-label" for="q">Query</label>
                        <input class="form-control" id="q" name="q">
                        <span class="help-block">Use the member ID.</span>
                    </div>
                    <div class="checkbox"><input type="checkbox" name="all"><label>All members</label></div>
                    <div class="radio"><input type="radio" name="sort" value="az">A to Z</div>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i> Search</button>
                </form>
            </div>
            <select class="selectpicker" name="team"></select>
            <div class="modal" id="confirm">
                <div class="modal-dialog"><div class="modal-content">Confirm?</div></div>
            </div>
            <ul class="pagination">
                <li class="active"><a href="#">1</a></li>
            </ul>
            <ul class="nav nav-tabs">
                <li class="active"><a href="#one">One</a></li>
            </ul>
            <div class="tab-content"><div class="tab-pane" id="one">One</div></div>
            <span class="label label-success">Active</span>
            <span class="badge">3</span>
            <a class="btn btn-link btn-xs" href="/more">More</a>
            <a class="btn btn-outline btn-sm" href="/less">Less</a>
            <a class="btn btn-lg" href="/big">Big</a>
            <div class="table-responsive">
                <table class="table"><tbody><tr><td>Wide</td></tr></tbody></table>
            </div>
            <div class="thumbnail"><img src="/img/t.png" alt=""></div>
            <p class="text-danger bg-success pull-left center-block hidden-xs visible-xs">Notes</p>
            <button type="button" class="btn"><i class="fa fa-trash"></i></button>
            <button type="button" class="btn"><i class="fa fa-bars"></i></button>
            <button type="button" class="btn"><i class="fa fa-spinner fa-spin"></i></button>
            <button type="button" class="btn"><i class="fa fa-sign-out"></i></button>
        </div>
        <div class="col-sm-4">
            <footer class="wayne-ui-footer footer">
                <p>Example App</p>
            </footer>
        </div>
    </div>
</body>
</html>
