---
title: "Hops | Booster docs"
description: "Every hop Booster knows, generated from the hop data."
url: https://booster.apps.wayne.edu/docs/reference/hops.html
llms: https://booster.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Hops[#](https://booster.apps.wayne.edu/docs/reference/hops.html#hops)

This page lists every hop Booster knows; the list is generated from the hop data by the docs build.

| Hop | Kind | From | To | What it does |
| --- | --- | --- | --- | --- |
| `php-55-to-56` | php | 5.5 | 5.6 | Move PHP 5.5 code to PHP 5.6. |
| `php-56-to-70` | php | 5.6 | 7.0 | Move PHP 5.6 code to PHP 7.0. |
| `php-70-to-71` | php | 7.0 | 7.1 | Move PHP 7.0 code to PHP 7.1. |
| `php-71-to-72` | php | 7.1 | 7.2 | Move PHP 7.1 code to PHP 7.2. |
| `php-72-to-73` | php | 7.2 | 7.3 | Move PHP 7.2 code to PHP 7.3. |
| `php-73-to-74` | php | 7.3 | 7.4 | Move PHP 7.3 code to PHP 7.4. |
| `php-74-to-80` | php | 7.4 | 8.0 | Move PHP 7.4 code to PHP 8.0. |
| `php-80-to-81` | php | 8.0 | 8.1 | Move PHP 8.0 code to PHP 8.1. |
| `php-81-to-82` | php | 8.1 | 8.2 | Move PHP 8.1 code to PHP 8.2. |
| `php-82-to-83` | php | 8.2 | 8.3 | Move PHP 8.2 code to PHP 8.3. |
| `php-83-to-84` | php | 8.3 | 8.4 | Move PHP 8.3 code to PHP 8.4. |
| `php-84-to-85` | php | 8.4 | 8.5 | Move PHP 8.4 code to PHP 8.5. |
| `laravel-5.8-to-6` | laravel | 5.8 | 6 | Move a Laravel 5.8 app to Laravel 6. |
| `laravel-6-to-7` | laravel | 6 | 7 | Move a Laravel 6 app to Laravel 7. |
| `laravel-7-to-8` | laravel | 7 | 8 | Move a Laravel 7 app to Laravel 8. |
| `laravel-8-to-9` | laravel | 8 | 9 | Move a Laravel 8 app to Laravel 9. |
| `laravel-9-to-10` | laravel | 9 | 10 | Move a Laravel 9 app to Laravel 10. |
| `laravel-10-to-11` | laravel | 10 | 11 | Move a Laravel 10 app to Laravel 11. |
| `laravel-11-to-12` | laravel | 11 | 12 | Move a Laravel 11 app to Laravel 12. |
| `laravel-12-to-13` | laravel | 12 | 13 | Move a Laravel 12 app to Laravel 13. |
| `phpunit-9-to-10` | phpunit | 9 | 10 | Move PHPUnit 9 tests to PHPUnit 10. |
| `phpunit-10-to-11` | phpunit | 10 | 11 | Move PHPUnit 10 tests to PHPUnit 11. |
| `phpunit-11-to-12` | phpunit | 11 | 12 | Move PHPUnit 11 tests to PHPUnit 12. |
| `phpunit-12-to-13` | phpunit | 12 | 13 | Move PHPUnit 12 tests to PHPUnit 13. |
| `phpunit-to-9-manual` | phpunit | below-9 | 9 | Move PHPUnit to ^9 by hand: PHPUnit below 9 does not run on PHP 8, so later gates cannot run until it moves. |
| `cas-to-sesame` | sesame | wayne-cas | sesame | Swap the wayne-cas package for SESAME using its compatibility classes. |
| `wayneui-1-to-2` | wayneui | 1 | 2 | Move WayneUI 1.x (Bootstrap 3) to 2.x (wsu- classes): rewrites the one-to-one items and lists the rest. |
| `node-pin-24` | node | any | 26 | Pin the app to Node 26: set engines.node in package.json and write .nvmrc. |
| `mix-to-vite` | node | laravel-mix | vite | Move a simple Laravel Mix front end to Vite (simple webpack.mix.js files only). |
| `vite-major` | node | any | 8 | Move a Vite front end onto Vite 8: pin vite and the Laravel and Vue plugin packages to the data versions. |
| `tailwind-3-to-4` | node | 3 | 4 | Move a Tailwind CSS 3 front end to Tailwind CSS 4 with the official upgrade tool. |
| `typescript-major` | node | any | 6 | Move the app to TypeScript 6: pin typescript in package.json, and vue-tsc too when the app has it. |
| `vue-26-to-27` | node | 2.6 | 2.7 | Move a Vue 2.6 front end to Vue 2.7, the last Vue 2 release, which carries the Vue 3 reactivity API. |
| `vue-27-to-compat` | node | 2.7 | 3 | Move a Vue 2.7 front end to Vue 3 through the @vue/compat build. |
| `vue-compat-codemod` | node | 3-compat | 3 | Run the vue-upgrade-tool codemod over the front end and keep every manual item it lists. |
| `vue-2-to-3` | vue | 2 | 3 | Move a Vue 2 front end to Vue 3 (playbook only; Rector does not read Vue). |
| `inertia-2-to-3` | node | 2 | 3 | Move an Inertia 2 app to Inertia 3: pin inertiajs/inertia-laravel to ^3.0 and @inertiajs/vue3 to ^3.0. |

The hop id `node-pin-24` is a stable id and now pins Node 26.

> Every other hop lifts the engine; `wayneui-1-to-2` is the one that repaints the bodywork.

Booster plans any target the policy knows. A target past security support is allowed as a stop on the way up, with a plan warning that names the date its security support ended. A version the policy does not list at all is still refused by name.

A Laravel major hop also raises the companion packages its target major pairs with (for Laravel 13, laravel/tinker to ^3.0), but only where the app already lists them in require or require-dev; absent packages are never added, and a constraint that already floors at or above the pin's floor is left alone.
