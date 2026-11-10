---
title: Install
description: Every route to get Booster running, from a single file to Docker only.
---

# Install

This page covers every install route. Next: [Quick start](guides/quick-start.md).

Run Booster from inside the app you want to upgrade: `--path` defaults to `.`, so `booster plan` needs no flags.
Booster needs PHP 8.3 or newer on the machine; the app it upgrades does not, because Rector runs from Booster's own
`vendor/`.

## Single file (PHAR)

<!-- docs-test: skip needs network -->
```
mkdir -p ~/bin
curl -L -o ~/bin/booster.phar https://github.com/waynestate/booster/releases/latest/download/booster.phar
alias booster='php ~/bin/booster.phar'    # put this line in ~/.bashrc or ~/.zshrc to keep it
cd /path/to/your-app
booster detect
booster plan
booster run --dry-run
booster run
```

`detect` reads the app and prints its type and versions. `plan` lists the steps. `run --dry-run` previews the edits
and changes nothing. `run` edits files and makes no commit. A real `run` also needs git, a clean git folder (commit
or stash first) and a Docker daemon for its test gate.

On Windows PowerShell, the same single file (`curl.exe` ships with Windows 10 and 11):

<!-- docs-test: skip needs network -->
```
New-Item -ItemType Directory -Force "$HOME\bin" | Out-Null
curl.exe -L -o "$HOME\bin\booster.phar" https://github.com/waynestate/booster/releases/latest/download/booster.phar
function booster { php "$HOME\bin\booster.phar" @args }   # put this line in $PROFILE to keep it
cd C:\path\to\your-app
booster detect
booster plan
booster run --dry-run
booster run
```

## Linux, macOS and WSL (Ubuntu): clone it

<!-- docs-test: skip installs software -->
```
sudo apt install php-cli php-xml php-mbstring php-zip php-curl unzip git composer
```

Ubuntu splits the XML extension out of `php-cli`, and Composer's dev dependencies need it: when Composer says
"ext-dom is missing", run the line above. On macOS: `brew install php composer git`. Ubuntu 22.04 ships PHP 8.1, too
old for Booster: add the [ondrej/php PPA](https://launchpad.net/+ppas?field.name=php&field.status=active) or use
the Docker route below. Then:

<!-- docs-test: skip needs network -->
```
git clone https://github.com/waynestate/booster.git
cd booster && composer install
bin/booster --version
```

Run Booster from its own folder with `--path` pointing at the app; never clone Booster inside an app:

<!-- docs-test: skip run on your own app -->
```
bin/booster plan --path=/path/to/your-app
```

`--path` takes a path, and a leading `~` means your home folder (from `HOME`, or `USERPROFILE` on Windows). In WSL,
keep Booster and the app in your Linux home folder, not under `/mnt/c` (a Windows drive is slow from Linux); a
Windows folder is `/mnt/c/Users/<you>/...`, and `run` needs Docker Desktop's WSL integration switched on.

## Windows PowerShell, with PHP and Composer installed

<!-- docs-test: skip needs network -->
```
git clone https://github.com/waynestate/booster.git
cd booster; composer install
php bin\booster --version
php bin\booster plan --path=..\my-app
```

## Docker only, no PHP (Windows or any OS)

<!-- docs-test: skip needs Docker -->
```
git clone https://github.com/waynestate/booster.git
cd booster
docker run --rm -v "${PWD}:/booster" -w /booster composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 composer install --no-interaction
docker run --rm -v "${PWD}:/booster" -v "/path/to/app:/app" -w /booster php:8.4-cli php bin/booster plan --path=/app
```

Run these from inside the booster folder.

This route can run `detect`, `plan`, `scan` and `run --dry-run`. A real `run` starts Docker and uses git from inside
Booster, which the `php:8.4-cli` image lacks, so for a real run use the single-file or clone route (in WSL on Windows). When
Booster itself runs in a container, pass `--skip-gate` to `run` and run the tests yourself. Node hops run `npm` in a
`node:<major>` container (26 unless the plan pins another Node), so they need the same daemon but no Node installed on the machine.

Booster prints its progress lines on standard error. In Windows PowerShell they print in red, as if they were errors. They are not errors; the exit code tells you whether the run worked.

Booster is also on Packagist as `waynestate/booster`: give it its own folder, not your app's `vendor/`, because it
needs PHP 8.3 even when your app doesn't. Clone it as above, or run
`composer create-project waynestate/booster booster ^1.0`.

## Single-file download

Booster ships as a single-file PHAR, so Preflight and apps without Composer access can run it as one file. The first route
above downloads it; every version tag publishes the same `booster.phar`: the GitLab release carries it as an asset,
and the GitHub release at github.com/waynestate/booster carries it as an attachment. Run it with `php booster.phar`.

You can also build it from any commit, with the script for your OS (both need Docker, and no PHP or Composer on the
host):

Windows (PowerShell):

<!-- docs-test: skip needs Docker -->
```
.\scripts\build-phar.ps1
```

Linux / macOS:

<!-- docs-test: skip needs Docker -->
```
scripts/build-phar.sh
```

Each script writes `build/booster.phar` and verifies it by printing Booster's version.

Every command works from the phar. `detect`, `plan`, `hops` and `init` read only what is bundled inside it. `run`
copies Rector, its `config/rector.php` and `docker/` out of the phar into a temp directory first, because Rector and
`docker build` are child processes that cannot be pointed at a `phar://` path; the temp directory is removed when the
command exits.
