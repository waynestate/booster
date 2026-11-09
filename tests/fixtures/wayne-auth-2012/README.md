# wayne-auth-2012

A pocket-sized copy of how a 2012 raw PHP app signs people in through the old
Wayne Auth service: the classic `WayneAuth` class hand-copied into `lib/`, a
front controller that builds it from the `[wayneauth]` section of its ini
file, and the `validUser` cookie it reads. Every value is fake: the host is
`example.invalid` and nothing here talks to a network. WayneAuthScanTest and
the detect and plan tests read it; the bridged variants are built in a temp
copy.
