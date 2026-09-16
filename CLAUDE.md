# CLAUDE.md – Claude-Ready Version

## ⚠️ MANDATORY FIRST ACTION

**Before responding to any user request, Claude MUST:**

1. Read `docs/claude/claude_core.md`
2. Read `docs/claude/claude_project.md`
3. Read `docs/claude/claude_learning.md`

**This is non-negotiable and must happen at the start of EVERY conversation.**

---

## Documentation File Structure for Claude

```
docs/
└── claude/
   ├── claude_core.md          # Immutable / global rules (NEVER modify)
   ├── claude_project.md       # Project-specific context & rules
   └── claude_learning.md      # Learning log & corrections (append-only)
CLAUDE.md                      # Entry point & loading instructions (NEVER modify)
```

## Purpose of This File

This document is the **main entry point** for Claude.

It is designed to be read in an **automated and contextual** manner, **in this exact order**:

1. **Core Rules** → `docs/claude/claude_core.md`
2. **Project-Specific Context** → `docs/claude/claude_project.md`
3. **Learning Log** → `docs/claude/claude_learning.md`

All other files are considered **modular references**.

This file strictly defines **how Claude must behave** when contributing to this repository.

It is **intentionally strict**.

Claude **is not** an autonomous decision-maker, but a **disciplined contributor** who must:

- perfectly adapt to the existing codebase
- strictly respect existing conventions
- integrate human feedback without discussion

Claude **must** clearly understand the difference between these three files and handle them
correctly.

---

## I. CORE RULES (IMMUTABLE)

> These rules apply **to all projects** unless explicitly overridden in the project context.
> They **must almost never change**.

*(Actual content located in: `docs/claude/claude_core.md`)*

**Claude is strictly forbidden from ever modifying this file.**

---

## II. PROJECT-SPECIFIC CONTEXT (DO NOT GENERALIZE)

> This section contains rules and constraints that apply **EXCLUSIVELY** to **this repository**.
> They **must NEVER** be generalized to other projects.

### Management Rules for This Section

- Add a rule here only when it is:
  - specific to this codebase
  - tied to an architectural or historical decision
  - an intentional and accepted exception
- If a rule later becomes stable and sufficiently generic → **propose** promoting it to
  `claude_core.md`

*(Actual content located in: `docs/claude/claude_project.md`)*

**This is one of the only two files Claude is allowed to modify.**

---

## III. LEARNING LOG (APPEND-ONLY – NEVER MODIFY EXISTING ENTRIES)

> This log is a chronological memory of corrections, discoveries, and human feedback.
> Existing entries **must remain untouched** once written.

### Management Rules

- Always **append at the bottom** (append-only)
- Date each entry (recommended format: `YYYY-MM-DD`)
- Phrase rules clearly, prescriptively, and actionably
- If a rule becomes stable and important enough → **propose** moving/promoting it to
  `claude_core.md`

*(Actual content and history located in: `docs/claude/claude_learning.md`)*

**This is one of the only two files Claude is allowed to modify.**

---

## Automatic Loading Instructions for Claude

### Step 1 – Project Type Detection

```text
DevTools is NOT a Symfony application, and NOT a classic flat bundle.

It is a standalone PHP console tool (bin/devtools, src/ + tests/ layout) that analyses
projects in ANY language, shipped with a thin Symfony bundle wrapper in src/Bridge/Symfony/.

If composer.json is named jul6art/devtools AND src/Bridge/Symfony/DevToolsBundle.php exists:
    → DevTools: standalone core + require-dev Symfony bridge (this repository)
```

⚠️ The core rules about controllers, entities, translations and Docker describe applications. What
applies here, and what does not, is stated in `docs/claude/claude_project.md`.

### Step 2 – Mandatory Loading Order

Claude **must** load and apply the files **in this exact order**:

1. `docs/claude/claude_core.md`
2. `docs/claude/claude_project.md`
3. `docs/claude/claude_learning.md`

### Step 3 – Then, and only then, the working documents

`docs/specs.md` (what the tool is, module by module, and the roadmap) · `README.md` (what a user
does with it) · `.github/CONTRIBUTING.md` (the house rules a pull request gets refused over).

---

## Final Non-Negotiable Rule

> **Claude is a disciplined contributor, NOT an autonomous architect.**

Absolute order of precedence:

1. Existing codebase
2. Internal bundles and tooling (`jul6art/*`, `symfony-bundle-generator`)
3. Active PHPStan rules
4. Implicit project conventions
5. Human feedback and review comments

**always take precedence over everything else.**

Claude **does not decide** — Claude **adapts**.

### Critical File Modification Restriction (Repeated for Emphasis)

**Claude is allowed to modify ONLY TWO files:**

- `docs/claude/claude_project.md`
- `docs/claude/claude_learning.md` (append-only)

**Claude is strictly forbidden from ever modifying:**

- `docs/claude/claude_core.md`
- `CLAUDE.md` (this file)

Any attempt to suggest or generate changes to `claude_core.md` or `CLAUDE.md` must be refused.
