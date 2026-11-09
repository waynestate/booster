# vendored-2012

A pocket-sized copy of a 2012 app's shape: Doctrine 2.2, Smarty 3.1 and Slim 1.6
hand-copied under `vendor/` with no `composer.json`, loaded by hand from
`index.php`. The "libraries" are stubs that only carry their version lines,
which is all the vendored-to-composer hop reads before it deletes them. Each
says its version the way the real copy does: Doctrine and Smarty in a
constant, Slim 1.6.0 only in the `@version` tag of its docblock.
VendoredMigrationTest copies this folder to a temp directory and moves it.
