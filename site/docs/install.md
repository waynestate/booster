---
title: "Install | Booster docs"
description: "Every route to get Booster running, from a single file to Docker only."
url: https://booster.apps.wayne.edu/docs/install.html
llms: https://booster.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Install[#](https://booster.apps.wayne.edu/docs/install.html#install)

This page covers every install route. Next: [Quick start](https://booster.apps.wayne.edu/docs/guides/quick-start.html).

Run Booster from inside the app you want to upgrade: `--path` defaults to `.`, so `booster plan` needs no flags. Booster needs PHP 8.3 or newer on the machine; the app it upgrades does not, because Rector runs from Booster's own `vendor/`.

## Single file (PHAR)[#](https://booster.apps.wayne.edu/docs/install.html#single-file-phar)

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

`detect` reads the app and prints its type and versions. `plan` lists the steps. `run --dry-run` previews the edits and changes nothing. `run` edits files and makes no commit. A real `run` also needs git, a clean git folder (commit or stash first) and a Docker daemon for its test gate.

## Linux, macOS and WSL (Ubuntu): clone it[#](https://booster.apps.wayne.edu/docs/install.html#linux-macos-and-wsl-ubuntu-clone-it)

```
sudo apt install php-cli php-xml php-mbstring php-zip php-curl unzip git composer
```

Ubuntu splits the XML extension out of `php-cli`, and Composer's dev dependencies need it: when Composer says "ext-dom is missing", run the line above. On macOS: `brew install php composer git`. Ubuntu 22.04 ships PHP 8.1, too old for Booster: add the [ondrej/php PPA](https://launchpad.net/+ppas?field.name=php&field.status=active) or use the Docker route below. Then:

```
git clone https://github.com/waynestate/booster.git
cd booster && composer install
bin/booster --version
```

Run Booster from its own folder with `--path` pointing at the app; never clone Booster inside an app:

```
bin/booster plan --path=/path/to/your-app
```

`--path` takes a path, and a leading `~` means your home folder (from `HOME`, or `USERPROFILE` on Windows). In WSL, keep Booster and the app in your Linux home folder, not under `/mnt/c` (a Windows drive is slow from Linux); a Windows folder is `/mnt/c/Users/<you>/...`, and `run` needs Docker Desktop's WSL integration switched on.

## Windows PowerShell, with PHP and Composer installed[#](https://booster.apps.wayne.edu/docs/install.html#windows-powershell-with-php-and-composer-installed)

```
git clone https://github.com/waynestate/booster.git
cd booster; composer install
php bin\booster --version
php bin\booster plan --path=..\my-app
```

## Docker only, no PHP (Windows or any OS)[#](https://booster.apps.wayne.edu/docs/install.html#docker-only-no-php-windows-or-any-os)

```
git clone https://github.com/waynestate/booster.git
cd booster
docker run --rm -v "${PWD}:/booster" -w /booster composer:2.10.3 composer install --no-interaction
docker run --rm -v "${PWD}:/booster" -v "/path/to/app:/app" -w /booster php:8.4-cli php bin/booster plan --path=/app
```

Run these from inside the booster folder.

This route can run `detect`, `plan`, `scan` and `run --dry-run`. A real `run` starts Docker and uses git from inside Booster, which the `php:8.4-cli` image lacks, so for a real run use the single-file or clone route (in WSL on Windows). When Booster itself runs in a container, pass `--skip-gate` to `run` and run the tests yourself. Node hops run `npm` in a `node:26` container, so they need the same daemon but no Node installed on the machine.

Booster prints its progress lines on standard error. In Windows PowerShell they print in red, as if they were errors. They are not errors; the exit code tells you whether the run worked.

Booster is also on Packagist as `waynestate/booster`: give it its own folder, not your app's `vendor/`, because it needs PHP 8.3 even when your app doesn't. Clone it as above, or run `composer create-project waynestate/booster booster ^1.0`.

## Single-file download[#](https://booster.apps.wayne.edu/docs/install.html#single-file-download)

Booster ships as a single-file PHAR, so Preflight and apps without Composer access can run it as one file. The first route above downloads it; every version tag publishes the same `booster.phar`: the GitLab release carries it as an asset, and the GitHub release at github.com/waynestate/booster carries it as an attachment. Run it with `php booster.phar`.

You can also build it from any commit, with the script for your OS (both need Docker, and no PHP or Composer on the host):

Windows (PowerShell):

```
.\scripts\build-phar.ps1
```

Linux / macOS:

```
scripts/build-phar.sh
```

Each script writes `build/booster.phar` and verifies it by printing Booster's version.

Every command works from the phar. `detect`, `plan`, `hops` and `init` read only what is bundled inside it. `run` copies Rector, its `config/rector.php` and `docker/` out of the phar into a temp directory first, because Rector and `docker build` are child processes that cannot be pointed at a `phar://` path; the temp directory is removed when the command exits.
