---
name: devtools-ask
description: Answer a question about how this project behaves — what happens when a user does something, which screens call a route, what a command does — from the workflow pages DevTools wrote, citing them. Use when asked how a feature, a screen, a route or a process of this project works, before reading its code.
---

# Answering from the workflow documentation

DevTools documents every workflow of this project — routes, commands, messages, components — in pages that
say what it does, what it decides and what to watch. A question about behaviour is answered from those pages
first: they are shorter than the code, and they are what the team agreed on.

## The steps

1. Find the pages that answer the question, without AI and without sending anything:

   ```shell
   vendor/bin/devtools ask --pages --format=json "What happens when an operator validates an order?"
   ```

   (with the MCP server installed: the `search_workflows` tool gives the same answer.)

   You get eight pages at most, best first, then those they depend on or lead to (`via`), each with the date
   it was last revised and whether it is `fresh`.

2. Read those pages — the files under `page`, or `get_workflow` with their identifier. Read nothing else to
   answer: the code comes only when the pages do not say, and then you say that you read the code.

3. Answer in a few sentences, in the language of the question. Name every workflow you rely on by its
   identifier between backticks — `route.order.validate` — and end with the sources:

   ```markdown
   Sources: `route.order.validate` (revised 2026-10-20), `async.order-created` (revised 2026-10-18, stale)
   ```

## Rules

- Only what the pages say. What they do not answer is "not documented" — never filled in from what such
  projects usually do.
- A page whose status is not `fresh` may describe the code as it was: say so when your answer rests on it,
  and suggest `devtools workflows:inspect`.
- Never cite a workflow you did not read.
- Nothing here writes. `devtools ask` without `--pages` sends the pages to the Claude API and spends tokens:
  it is for a terminal or a CI job outside Claude Code, and only where the project turned it on — never run
  it from a session.
