# Booster decisions

One section per record in `ADR/`. Each summary is two sentences; the record carries the context, the consequences
and the sources.

## ADR-0001: One CLI, no adapters, no artisan command

Booster is one Symfony Console CLI (`bin/booster`, also a single-file phar) that runs against any of the five app
shapes with `--path`; there are no adapter classes, no service provider and no artisan command. The app's own PHP
floor never limits Booster, because Rector and the scans run from Booster's vendor tree on PHP 8.3+.
[Record](ADR/ADR-0001-one-cli-no-adapters.md)

## ADR-0002: No prepared Rector tools image

There is no separate tools image: Rector runs from Booster's own vendor directory (or a temporary copy from the
phar) on the host PHP 8.3+, so an app being rewritten never gains Rector in its `composer.json`. The only image
Booster builds is the target image in `docker/Dockerfile`, where the app's tests run on the destination PHP.
[Record](ADR/ADR-0002-no-tools-image.md)

## ADR-0003: PROCTOR runs from the host toolchain

PROCTOR runs from Booster's own vendor directory on the host, with `--php-runtime` set to the version being
measured: a baseline before the first hop and a final check after the last, compared so only new findings block.
The target-image runner keeps the per-hop test gate in the container, and `--proctor-command` can still run extra
commands in-image. [Record](ADR/ADR-0003-proctor-from-the-host.md)

## ADR-0004: The mark ships as a PNG

The mark (a syringe that is also a rocket) is a ChatGPT-generated raster set: avatars at 1024, 512 and 256,
favicons at 16, 32 and 64 plus `.ico`, and the 11-panel brand board, all in `site/brand/`. The PNGs are canonical;
if a vector is ever needed it is traced from the PNG, never redrawn. [Record](ADR/ADR-0004-png-mark.md)
