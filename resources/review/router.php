<?php

declare(strict_types=1);

/*
 * The router of `devtools review` (ADR-0028), run by PHP's built-in server: every request — the page and its two
 * files included — goes to ReviewApp, which checks the token and serves nothing outside what DevTools wrote.
 */

require (string) getenv('DEVTOOLS_REVIEW_AUTOLOAD');

Jul6Art\DevTools\Review\Web\ReviewApp::fromEnvironment()->handle(Jul6Art\DevTools\Review\Web\ReviewRequest::fromGlobals())->send();
