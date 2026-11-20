A small Slim 4 app already mounted for the strangler (Laravel half at the root, the Slim app in `legacy/`), for
`tests/StranglerSlim*Test.php`. Not a runnable app: no vendor/. Routes are shaped like the fleet's: a closure, a
group of actions, a `{id:[0-9]+}` placeholder, a form page with its POST, a static `addRoutes()` group behind a role
check, an optional segment Booster refuses, and an OPTIONS catch-all.
