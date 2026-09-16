## I. CORE RULES (IMMUTABLE)

> These rules apply **to all projects** unless explicitly overridden in the project context.
> They **must almost never change**.

**Claude is strictly forbidden from ever modifying this file.**

---

## Mandatory Contribution Rules (Read First)

This repository follows **strict, cumulative, and non-negotiable rules**.
Claude must behave as a **senior Symfony developer fully embedded in the team**, familiar with the
in-house bundles, the PHPStan constraints, and the long-term project conventions.

Claude is **not here to redesign or improve the architecture**, but to **strictly comply with it**.

---

# claude_core.md

## 1. Absolute Alignment with Existing Code (Foundational Rule)

### ❗ Core Principle

> **Existing code is the ultimate authority.**

Before writing or modifying anything, Claude MUST:

1. Inspect existing files of the **same type** and **same functional domain**
2. Identify:
    * structure
    * responsibility split
    * abstraction level
    * naming conventions
3. Reproduce **exactly**:
    * style
    * architecture
    * granularity
    * internal organization

The produced code must be **indistinguishable from human-written project code**.

### Absolute Prohibitions

* ❌ Introducing alternative architectures
* ❌ Refactoring without explicit request
* ❌ "Modernizing" existing code
* ❌ Applying generic Symfony best practices if the project differs

➡️ **Project consistency > theoretical best practices > personal preference**

---

## 2. Language, Readability, and General Conventions

### Code Language

* All code MUST be written **exclusively in English**
* Applies to:
    * classes
    * methods
    * variables
    * services
    * events
    * DTOs
    * **comments**
    * commit messages
    * translation KEYS
* ❌ No French identifiers, no French comments
* Specification and decision documents (`docs/specs.md`, `docs/adr/`, `docs/corrections/`) are
  written in **French** — they are the decision-maker's reading surface, not code.

Only allowed non-English content in code paths:

* translation VALUES
* user-facing data content

---

## 3. Translations — Critical Rule (Backend & Frontend)

### ❗ No User-Facing String Hardcoded

Any string that is:

* displayed to a user
* returned by an API consumed by a frontend
* used in JavaScript UI (errors, alerts, modals, toasts, confirmations, etc.)

➡️ **MUST be a translation key**

This applies **even if the project is not multilingual today**.

### JavaScript Translation Presumption

Claude MUST assume:

> **Any JavaScript string is user-facing and therefore translatable**,
> unless explicitly proven otherwise (technical log, internal enum, identifier).

Never assume a JS string is "technical".

---

## 4. Translation Files Organization (Blocking Error if Violated)

### ❌ `messages.<locale>.yaml` Is NOT a Dumping Ground

Translations are **strictly split by functional domain**.

Before adding a key, Claude MUST:

1. Identify the functional domain
2. Locate the existing translation file for that domain
3. Respect:
    * the same file
    * the same hierarchy
    * the same naming conventions
    * the same key granularity

❌ Defaulting to `messages` is a **blocking error**.

### Translation Key Naming

* Always **English**
* Always **hierarchical**
* Always **aligned with existing keys**
* Never flat or ambiguous

Claude MUST reuse existing structures, never invent new ones arbitrarily.

### Every declared locale is filled, or the lot is not done

A key that exists in one locale and not another renders the key itself to a real user. The set of
locales is declared by the project context; **all of them** are filled in the same commit.

---

## 5. Symfony Architecture — Strict Rules

### Service-Oriented Architecture

* Controllers MUST remain **extremely thin**
* Any business logic MUST live in:
    * a service
    * or a repository
* Controllers are orchestration only

### Structural Limits

* ❌ More than 5–6 arguments per method → forbidden
* ❌ Business logic inside controllers → forbidden
* ❌ Direct `EntityManager` usage outside repositories → forbidden

---

## 6. PHPStan — Mandatory, Non-Interpretative Rules

Claude MUST proactively comply with all PHPStan rules, including but not limited to:

* No `EntityManagerInterface` outside repositories
* `createQueryBuilder` only in repositories or API Platform filters
* Mandatory use of `and` / `or` instead of `&&` / `||` where the project does so
* Global functions must be prefixed (`\count`, `\in_array`, `is_*`)
* Query methods (`where`, `andWhere`, `setParameter`) restricted to `filterBy*`
* Controller methods:
    * max 15 lines
    * max 25 lines if forms are involved

➡️ **If a PHPStan rule exists, Claude must anticipate it.**

---

## 7. Idiomatic PHP — Internal Rules

Claude MUST:

* Avoid redundant `count` comparisons
* Use native PHP array helpers (`array_map`, `array_filter`, etc.)
* Keep files "beautiful":
    * consistent spacing
    * sorted properties
    * sorted methods
* Respect the ordering observed in existing files
* `declare(strict_types=1);` everywhere; `final` classes by default; constructor injection

---

## 8. Entities, Database, and Validation — Zero Tolerance

### Fundamental Principle

> **An entity must never be able to trigger an SQL error that a form could have intercepted.**

Claude MUST guarantee **strict alignment** between:

* Doctrine mapping
* Database schema
* Validation assertions
* Forms

### Absolute Prohibitions

* ❌ Nullable property when DB field is not nullable
* ❌ `nullable=false` without `Assert\NotBlank` / `Assert\NotNull`
* ❌ Doctrine length ≠ `Assert\Length`
* ❌ `unique: true` without `#[UniqueEntity]`
* ❌ Missing server-side validation
* ❌ Error handling via `try/catch` instead of `isValid()`

➡️ **The form is the only valid gate before persistence.**

---

## 9. Bundles, Wrappers, and Dependency Abstraction

Claude MUST:

* Check for an existing **internal bundle** before implementing anything
* Never directly use:
    * third-party libraries
    * low-level Symfony components
      when an internal wrapper exists

❌ No third-party namespace should appear directly in project code when a wrapper exists.

---

## 10. Parameters and Configuration Management

* Environment-specific parameters → `.env.local`
* Global parameters → `services.yaml`
* ❌ No magic values in code
* ❌ No hidden configuration inside classes
* A scalar constructor argument (`bool`, `string`, `int`) is wired in `services.yaml` via
  `%env(...)%` — autowiring never resolves one, and the omission only shows up at boot

---

## 11. Docker — Execution Context

The project context declares which commands run on the host and which run inside the stack.
When a project is Docker-first, any command usually written as:

```bash
php bin/console ...
```

MUST be written as:

```bash
docker compose exec php bin/console ...
```

Claude MUST read the project context before choosing, and never assume.

---

## 12. Security — Three Layers, Always

Three layers, each answering a question the others do not ask. **None substitutes for another.**

| | Question | Where |
|---|---|---|
| **1. Class** | is this screen open to this account? | `#[IsGranted]` on the CLASS |
| **2. Method** | does this role know how to perform this gesture? | `#[IsGranted(PermissionCodes::…)]` on the METHOD |
| **3. Subject** | on THIS object? | `denyAccessUnlessGranted(Voter::ATTR, $entity)` |

⚠️ **Layer 2 NEVER protects other people's data.** It validates a property of the ROLE; ownership
is a property of the OBJECT.

⚠️ A cross-tenant access answers **404, not 403** — a 403 confirms the object exists, which is
already a leak.

⚠️ **A misspelled permission code OPENS the screen**: a voter abstains on what it cannot parse,
and general abstention counts as "granted".

---

## 13. Public Surfaces — Never Serialize the Entity

Any route reachable without authentication renders a **projection DTO built field by field**, never
an entity and never an entity's serialization groups. A new column must be incapable of widening a
public response by accident.

---

## 14. Performance — No Route Costs a Query Per Row

The N+1 is the first cause of slowness in a Symfony application, and it is invisible: the code
reads well, the suite is green, the page is pretty, and it fires 240 queries.

* `leftJoin` **+ `addSelect`** — `leftJoin` alone filters without loading
* `|length` or `is not empty` on a collection loads it entirely → `EXTRA_LAZY`
* A relation shown in a column is exposed as an **IRI**, never a nested object
* No query inside a loop, no `findAll()` filtered in PHP, no reflex `EAGER`
* The query count is **measured**, not assumed, and does not move when the row count grows

---

## 15. Git, Tickets, and Scope Discipline

Claude MUST implicitly respect:

* One branch = one ticket
* No changes outside ticket scope
* No opportunistic refactors
* No "cleanup" unless explicitly requested
* Commit messages in English

---

## 16. Mandatory Self-Correction (Learning Rule)

### Core Principle

Claude MUST treat **every human correction as a permanent new rule**.

If an error is pointed out:

1. Analyze it
2. Extract the implicit rule
3. Integrate it permanently
4. Never reproduce the error again

Rules are **cumulative**.

---

## 17. When in Doubt

If Claude hesitates between:

* a generic best practice
* an observed project convention

➡️ **Project convention always wins.**

If no clear precedent exists:

* Claude may propose
* Claude must explain
* Claude must never impose
