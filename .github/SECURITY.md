# Security Policy

## Supported versions

`jul6art/devtools` is installed by other projects through Composer, so a fix here reaches them the
moment they update. Only the current major line gets one.

| Version | Supported |
| --- | --- |
| `3.x` | ✅ |
| any older tag or fork | ❌ |

Support means security fixes on the latest release of that line — upgrade to it before reporting, in
case the problem is already gone.

## What is in scope

DevTools is a development tool, but it reads a whole codebase, runs git on it, parses files it did
not write, writes files that get committed, runs the analysed project's own console and compiler, serves
local pages, hooks into Claude Code, and can send source code to a language model. Each of those is an
attack surface:

* **Source code or secrets leaving the machine unintended** — a file the configuration excludes, an
  `.env*` file, a key, a token or a credential reaching a prompt sent to Claude, a report, or a log.
* **Command injection through git** — a path, a branch name, a commit reference or an option such as
  `--since` reaching a shell or a `git` argument list without being passed as a separate, validated
  argument.
* **Writing outside `.devtools/`** — a workflow identifier, a route name or a path taken from the
  analysed code producing a file name that traverses out of the target directory, or a symlink
  followed while writing.
* **Hostile XML** — external entities, remote DTDs or unbounded expansion in a tracking file, a
  `stack.xml` or a `config.xml`, which a pull request against an analysed project can modify.
* **Model output taken as fact or as instruction** — text returned by Claude written as a file path,
  executed, or used to decide which files to read or write, instead of being confined to the
  descriptive sections of a page. The analysed code is untrusted input to the model: a comment in it
  must not be able to steer DevTools.
* **Over-exposure through the MCP server** — a tool that reads outside the project root, or a server
  reachable by something other than the local Claude Code session.
* **The project's own tools, run by DevTools** — the Symfony console, Laravel's Artisan, Node with the
  project's TypeScript (ADR-0007, 0030, 0031): a configured command (`<symfony console>`, `<laravel
  artisan>`, `<node binary>`) reaching a shell instead of being split into arguments, or the Node bridge
  reading a file outside the project root, whatever an import says.
* **The Claude Code hooks and the gate** — a hook that blocks or injects on input it should refuse, a
  `Stop` hook that a crafted change can make pass, a rule or an assertion from the repository executed
  as more than the pattern, selector or tool command it declares.
* **The local servers** — the review page (`devtools review`) answering anything but `127.0.0.1` and its
  one-time token, writing outside `.devtools/feedback/` and `.devtools/baselines/`, or executing the
  captured page it shows; the scenario runner reaching beyond the base URL it was given.
* **The Claude API client** — `ai:run` and `ask` (ADR-0035, 0039) sending anything while the project has not
  set `<ai enabled="true"/>`, the key read from anywhere but `ANTHROPIC_API_KEY` or written to a file, a
  report, a log or a message, a request sent over plain HTTP, or a file or page the secret scanner refuses
  sent anyway.
* **Linked projects** — `workflows:inspect --link` (ADR-0038) reading a neighbour beyond the relative path its
  `config.xml` declares, an absolute path written into `graph/links.xml` or a page, or what a neighbour's
  `.devtools/` holds used as more than workflow identifiers and page paths.
* **The distribution** — `self-update` installing a phar whose SHA-256 or GitHub provenance attestation
  does not hold, the phar or the Docker image carrying files the repository does not, the exported site
  (`export:site`) running a script from a page's content or fetching anything over the network.
* **A freshness check that fails open** — a changed file reported as unchanged, so a stale page is
  trusted as current and the gate built on it lets a regression through.

Out of scope: vulnerabilities in Symfony, git, Claude Code or any other third-party tool or package —
report those to the project that owns the code, and they will reach you through your own update.
Also out of scope: a project that misconfigures DevTools in a way the README warns against, though a
warning that turns out to be easy to miss is worth an issue of its own.

## Reporting a vulnerability

**Do not open a public issue for a security problem.**

Use [GitHub's private vulnerability reporting](https://github.com/jul6art/devtools/security/advisories/new)
(the **Security** tab → *Report a vulnerability*). It opens a draft advisory only you and the
maintainers can read, and it is the channel this project prefers — no email address needs to be
published for it to work.

<!-- Private reporting has to be switched on for that link to work for anyone outside the
     repository. Once, per repository:
     gh api -X PUT repos/jul6art/devtools/private-vulnerability-reporting -->

Please include:

* the version of `jul6art/devtools`, the mode (standalone or bundle), and the stack of the analysed
  project,
* the relevant part of your `.devtools/config.xml`,
* the shortest reproduction you have — ideally a fixture project and a failing test against this
  repository, since that is what a fix will be built on,
* what an attacker gains: which file is read or written, what leaves the machine, and what they need
  to control — the analysed repository, a pull request against it, or the local machine.

## What to expect

* An acknowledgement within **7 days**.
* An assessment — accepted, out of scope, or needing more detail — within **14 days**.
* For an accepted report: a fix released on the supported line, a
  [security advisory](https://github.com/jul6art/devtools/security/advisories) describing the impact
  and the version to upgrade to, and credit in it unless you ask otherwise.

Please give the maintainers a reasonable window to ship a release before disclosing publicly. This
project runs no bug-bounty programme and offers no payment.
