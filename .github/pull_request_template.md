## What this changes

<!-- One subject per pull request. Say what a project using DevTools will be
     able to do, or stop having to do. -->

Closes #

## Type

- [ ] Bug fix — **patch**
- [ ] New feature, existing code untouched — **minor**
- [ ] Breaking change — **major** (removed or renamed command or option, changed
      page template, tracking XML rejected by the previous XSD, identifiers
      derived differently)
- [ ] Documentation or tooling only

<!-- A change to how workflow identifiers are derived is breaking even when every
     test is green: every existing page would be orphaned on its next scan. -->

## Quality gate

```
composer qa
```

- [ ] `cs-check` — php-cs-fixer clean
- [ ] `rector-check` — Rector clean
- [ ] `phpstan` — clean at `level: max`, with no baseline
- [ ] `test` — PHPUnit green, with no deprecation, notice, warning or risky test

<details>
<summary>Output</summary>

```
```

</details>

## Tests

- [ ] A bug fix ships the test that fails without it
- [ ] A new feature ships tests for what it adds
- [ ] A re-run without changes still modifies nothing outside `.devtools/reports/`
- [ ] I checked the `lowest` dependency set matters here (widened constraint,
      new vendor call) and said so below

## Documentation

- [ ] The README is updated in this pull request *(new or changed command,
      option or generated format)*
- [ ] The house rules in [CONTRIBUTING.md](CONTRIBUTING.md) still hold for what
      I added

## Notes for the reviewer

<!-- Trade-offs, anything left out on purpose, follow-up work, and which
     project this came out of. -->
