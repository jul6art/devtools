# PHP 8

## Cycle d'entrée

Without a framework, the web server maps a URL to a file: `public/orders/new.php` answers
`/orders/new.php`. PHP runs that file top to bottom; superglobals (`$_GET`, `$_POST`, `$_SERVER`,
`$_SESSION`) carry the request, `echo` and inline HTML build the response, `header()` sets headers and
`exit` ends the request. On the command line, `php bin/script.php` or an executable file with a
`#!/usr/bin/env php` shebang runs the same way, with `$argv` for its arguments.

## Mécanismes d'extension

- **`require` / `include`** — code shared between entry points is pulled in by path, usually
  `__DIR__.'/../lib/file.php'`; `_once` variants guard against double inclusion.
- **Composer autoloading** — `vendor/autoload.php` loads classes by PSR-4 namespace from `composer.json`.
- **Front controller** — a single `index.php` dispatching on the path, when a project grows one.
- **`auto_prepend_file`** and `.htaccess` rewrites — configuration run before every script.

## Injection de dépendances et conventions de nommage

No container: objects are built with `new` where they are needed, or by a bootstrap file that returns them.
Functions shared through `require` are global. Classes follow PSR-4 (`Acme\OrderRepository` in
`lib/OrderRepository.php`), one class per file.

## Points d'entrée par type

### routes
Every `.php` file under the web directory (`public/`, `web/`, `www/`, `htdocs/`); a front controller makes
it a single route with an internal dispatch.

### commands
`scripts` and `bin` of `composer.json`, and PHP files in `bin/`, possibly without extension. A console
application built on `symfony/console` has one class per command, named by `#[AsCommand]`, and a single
binary that registers them.

### async
Cron entries calling a script; queues are libraries, not the language.

### events
None in the language; hand-written observer lists when a project has them.

### ui
Included view files (`views/header.php`) and templates of a library such as Twig or Plates.

### integrations
`curl_*`, `file_get_contents()` on URLs, `PDO` connections, `mail()`.

### data
SQL migration files, or a migration library's classes.

## Tests

PHPUnit in `tests/`, run with `vendor/bin/phpunit`; code reached only through includes is tested by running
it, or after moving it into classes.

## Pièges connus

- `require` of a relative path resolves against the include path and the working directory, not the file:
  always prefix with `__DIR__`.
- Output before `header()` makes the header silently ignored ("headers already sent").
- A missing `exit` after a redirect header keeps running the rest of the page.
- `new Foo()->bar()` without parentheses needs PHP 8.4.

## Sources

https://www.php.net/manual/en/function.include.php
https://www.php.net/manual/en/function.header.php
https://www.php.net/manual/en/features.commandline.php
https://getcomposer.org/doc/04-schema.md#bin
https://symfony.com/doc/current/components/console.html
