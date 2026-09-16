# Contributing to `jul6art/devtools`

`jul6art/devtools` gives a project a versioned `.devtools/` folder in which Claude Code keeps an
exact map of the project's workflows — and, later, the memory of what humans had to correct. It
runs standalone on a codebase in any language, or inside a Symfony project through a thin bundle.

It exists because the same review comments kept coming back on code Claude had declared finished.
That origin sets the tone for contributions: DevTools carries what proved necessary on a real
project, and a pull request that adds a case nobody has hit yet is a harder sell than one that
fixes a case you did hit — say which one yours is.

Read [README.md](../README.md) first, then [`docs/specs.md`](../docs/specs.md) — the design this
repository implements, module by module. This file covers the workflow.

## Before you open anything

* **Bug** → open an [issue](https://github.com/jul6art/devtools/issues/new/choose) with the
  DevTools version, the mode (standalone or bundle), the stack of the analysed project, and the
  shortest failing test you can write. A failing test is worth more than a description, and it is
  what a fix will be built on.
* **New command, new option, new element in a generated file** → open an issue first. What
  DevTools writes into `.devtools/` is committed by the projects using it, so its format is a
  promise; changing it is cheaper to discuss before the code than after.
* **Security problem** → do not open an issue. Follow [SECURITY.md](SECURITY.md).

## Setting up

```bash
git clone https://github.com/jul6art/devtools.git
cd devtools
composer install
bin/devtools list
```

You need PHP **^8.5**, Composer 2 and git. There is nothing to boot: the core runs as a console
application, and the Symfony bridge is exercised through a test kernel the suite builds for you.

## The quality gate

```bash
composer qa
```

That is the whole contract, and it runs the four checks in the order the CI runs them:

| Step | What it is | Fix it with |
| --- | --- | --- |
| `cs-check` | php-cs-fixer, `--dry-run --diff` | `composer cs` |
| `rector-check` | Rector, `--dry-run` | `composer rector` |
| `phpstan` | PHPStan at **`level: max`** | by hand — there is no baseline, and there will not be one |
| `test` | PHPUnit | by hand |

`composer qa` green is the minimum for a pull request, not the goal. The suite is configured to fail
on deprecations, notices, warnings and risky tests, so a test that passes while emitting a
deprecation is a failing test here.

## What the CI checks that your machine does not

[`.github/workflows/ci.yml`](workflows/ci.yml) runs three jobs, and two of them catch what a local
run cannot:

* **The dependency matrix** — the test suite runs on both `highest` **and** `lowest`
  dependencies. The `lowest` set is not a formality, and it is also the only job that exercises
  Symfony 7.4. It caught a real difference on the very first commit of this repository: the
  `ApplicationTester` of symfony/console 7.4.0 does not neutralise `SHELL_VERBOSITY`, the 8.x one
  does, and a console test read an empty display on one set only.
* **`composer validate --strict`** — a malformed or inconsistent `composer.json` fails the build.

## Semantic versioning is a promise here

* **patch** — a fix that changes no command, no option and no generated format;
* **minor** — something added that existing projects keep working without: a new command, a new
  optional element in a tracking XML;
* **major** — anything a project using DevTools must act on: a removed or renamed command or option,
  a changed page template, a tracking XML that no longer validates against the previous XSD, a
  workflow identifier derived differently.

A change to how workflow identifiers are derived is a **breaking change** even when every test is
green: every page and every tracking file of every project using DevTools would be orphaned on its
next scan. Say so in the pull request when yours does.

## House rules

1. **All logic lives in the core; `src/Bridge/Symfony/` is a wrapper.** Nothing outside the bridge
   depends on `symfony/http-kernel`. A command that only works through `bin/console` is a command
   the standalone mode silently lacks.

2. **Language independence is not optional.** Anything that assumes the analysed project is PHP
   belongs in a stack adapter, never in the pipeline. Both paths — native adapter and
   Claude-driven — produce the same intermediate model, validated by schema.

3. **No file path is ever invented.** Every path, route or method written into a page or a tracking
   XML comes from the intermediate model. Claude writes descriptions, diagrams and points of
   attention; it does not write facts.

4. **A re-run without changes modifies nothing** outside `.devtools/reports/`. Idempotence is
   tested, and a pull request that breaks it is refused whatever it adds.

5. **Every XML DevTools writes is validated against its XSD, on write and on read.** A new element
   ships with its schema change and a round-trip test in the same pull request.

6. **Nothing reaches Claude that the configuration excludes.** Excluded paths, `.env*` files and
   anything matching a secret pattern are filtered before a prompt is built, not after.

7. **A test that touches git creates its own temporary repository.** Never this repository, never
   the developer's global git configuration.

8. **A new runtime dependency is a discussion, not a commit.** The core's `require` is kept to what
   [`docs/specs.md` §6](../docs/specs.md) lists; everything in it is imposed on every project
   installing DevTools, including non-Symfony ones.

9. **`level: max`, and no baseline.** Silencing PHPStan moves the cost to whoever upgrades.

## Tests

Tests live in `tests/`, mirroring `src/`. `tests/Fixtures/TestKernel.php` boots a real container for
the bridge rather than a mock, and `tests/Console/BinaryTest.php` runs `bin/devtools` in a separate
process — so a broken entry point fails the suite instead of failing a project on install.

Fixture projects will live in `tests/Fixtures/projects/`, each with the `.devtools/` it is expected
to produce. They are real mini-projects in their own languages: php-cs-fixer, Rector and PHPStan must
never rewrite or analyse them.

A bug fix comes with the test that fails without it — and the test is checked by mutation: disable
the fix, watch it go red, put the fix back.

## One trap that is not yours

**Do not add `symfony/flex` to `require-dev`.** The other bundles of this ecosystem use it; this
repository cannot. Its recipes write an application skeleton on every `composer install`, including
`src/Kernel.php` — and `src/` is the core's own namespace here.

## Pull requests

* One subject per pull request.
* Fill in the [template](pull_request_template.md), including the `composer qa` result — a pull
  request that does not say whether the gate is green cannot be reviewed.
* Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/): `fix: …`,
  `feat: …`, `docs: …`, `chore: …`, and `feat!:` / `fix!:` for a breaking change.
* Update the README in the same pull request when you add or change a command, an option or a
  generated format.
* Rebase on `master` rather than merging it back in.

## Code of conduct

Participation is covered by our [Code of Conduct](CODE_OF_CONDUCT.md).

## License

Contributions are accepted under the [MIT license](../LICENSE) that covers this repository.
