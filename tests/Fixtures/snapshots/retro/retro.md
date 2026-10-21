# Retrospective — 2026-07-01 to 2026-10-21

## Human feedback per delivered task

| Month | Tasks delivered | Human remarks | Per task |
| --- | ---: | ---: | ---: |
| 2026-07 | 2 | 4 | 2.00 |
| 2026-08 | 2 | 3 | 1.50 |
| 2026-09 | 2 | 3 | 1.50 |
| 2026-10 | 2 | 1 | 0.50 |

2026-10: 0.50 remarks per task, against 1.67 over the three months before — -70 % (the target: -50 % in three months).

1 human remark is about no delivered task: made before any, or about nothing a task reached.

## Recurring categories

| Category | Last month | Monthly, the three months before | Trend |
| --- | --- | --- | --- |
| layout | 1 | 0.67 | up |
| ui-component | 0 | 1.00 | down |
| filter | 0 | 0.67 | down |
| validation | 0 | 0.67 | down |
| data-display | 0 | 0.33 | down |
| translation | 0 | 0.33 | down |

## Rules that blocked the gate

| Rule | Tasks it stopped in the period | Blocked, in all |
| --- | --- | --- |
| rule-select2 | 2 | 2 |

## Inactive rules — proposed for archiving

| Rule | Last given or blocking | Days since |
| --- | --- | --- |
| rule-labels-in-french | 2026-08-20 | 62 |
| rule-never-given | 2026-09-01 | 50 |

`devtools rules:archive <rule>` archives one. Nothing is archived on its own.

## Feedback seen twice, not promoted

| Feedback | Seen | Summary |
| --- | --- | --- |
| fb-2026-08-26-index-filter | 2 | Le filtre par statut ne filtre rien. |
| fb-2026-09-29-new-layout | 2 | Le bouton passe sous le formulaire. |
| fb-2026-10-05-new-select | 2 | Un select natif reste dans le formulaire. |

`devtools rules:promote <feedback>` asks Claude for the rule.

## Gate failures before success

8 tasks delivered, 5 at the first passage of the gate; 1.50 passages per task on average.

| Task | Passages | Steps that failed |
| --- | --- | --- |
| 2026-07-20-170000 | 3 | scenarios, assertions |
| 2026-08-25-170000 | 2 | verification |
| 2026-09-28-170000 | 2 | assertions |

## The verifier and the humans

| Category | Found by the verifier | Said by a human |
| --- | --- | --- |
| data-display | 0 | 1 |
| filter | 0 | 2 |
| layout | 0 | 3 |
| translation | 0 | 1 |
| ui-component | 2 | 3 |
| validation | 1 | 2 |
