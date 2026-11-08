# ADR: Booster's shape decisions

Booster is named for the part of the rocket that does the pushing, and these four records are the places where the
rocket came out lighter than the drawing: what it is (one CLI, no adapters), how it runs Rector (no tools image),
how it gates on policy (PROCTOR from the host) and what its mark is (a PNG set). Each record states the context,
the decision, the consequences and the files that prove it. `DECISIONS.md` at the repo root is the index. The
original plan rows the records answer sit in the coordination repo's research notes, named in each record's
Sources.

- [ADR-0001: One CLI, no adapters, no artisan command](ADR-0001-one-cli-no-adapters.md)
- [ADR-0002: No prepared Rector tools image](ADR-0002-no-tools-image.md)
- [ADR-0003: PROCTOR runs from the host toolchain](ADR-0003-proctor-from-the-host.md)
- [ADR-0004: The mark ships as a PNG](ADR-0004-png-mark.md)
