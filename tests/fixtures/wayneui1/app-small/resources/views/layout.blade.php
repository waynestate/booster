<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>@yield('title') - Example App</title>
    <style>
        .brand-bar { background: #006666; }
        .quiet { color: #cfdedc; }
    </style>
</head>
<body class="non-production">
    <nav class="navbar navbar-default">
        <a class="navbar-brand" href="/">Example App</a>
        <button class="navbar-toggle" data-toggle="collapse" data-target="#main-nav">
            <i class="fa fa-bars"></i>
        </button>
        <ul class="nav navbar-nav">
            <li><a href="/members"><i class="fa fa-users"></i> Members</a></li>
            <li><a href="/help"><i class="fa fa-book"></i> Help</a></li>
        </ul>
    </nav>
    <div class="wayne-ui-main">
        @yield('content')
    </div>
    <footer class="wayne-ui-footer footer">
        <span class="sr-only">Skip back to top</span>
    </footer>
    <script src="/vendor/jquery/jquery.min.js"></script>
    <script src="/vendor/bootstrap/js/bootstrap.min.js"></script>
</body>
</html>
