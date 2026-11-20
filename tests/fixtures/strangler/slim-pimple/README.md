A Slim 4 app on a Pimple container, already mounted for the strangler, for `tests/StranglerSlimBootstrapTest.php`.
Synthetic, shaped like the fleet's older apps (no fleet code): a front controller that requires an init script
with define()d paths, an App class that loads `'key' => function ($c)` service arrays, a middleware array with the
app's own CSRF guard, and a routes array whose closures call static `addRoutes()` methods behind role `if`s, with
`/[login[/]]`, a guarded `/logout/` and route middleware. Step 0 is done. Not a runnable app: no vendor/.
