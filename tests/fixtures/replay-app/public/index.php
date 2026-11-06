<?php
// The front controller: every request that is not a file comes here.
require __DIR__ . '/../src/app.php';

notes_dispatch($_SERVER['REQUEST_METHOD'], parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
