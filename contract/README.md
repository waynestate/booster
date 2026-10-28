# BOOSTER contract

`expectations.json` is what BOOSTER promises for each of the five stack permutations, using the apps in `examples/`:

- the app type, PHP, Laravel and Vue versions it detects;
- the ordered hops it plans on the fixed date `today`;
- the files Rector changes for chosen hops (a dry run, so nothing is written).

`tests/ContractTest.php` reads this file and fails when the CLI and the contract disagree. Change the contract and
the code in the same commit, never one without the other.
