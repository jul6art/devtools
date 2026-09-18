# /health
`health` · type : routes · 1 route · `src/Controller/HealthController.php`

## Résumé

L'état de santé de l'application, pour la supervision. La réponse est volontairement minimale et n'interroge rien : une sonde qui charge la base ne dit plus si l'application répond, mais si la base répond.

## Routes

| Route | Chemin | Méthodes | Sécurité |
|---|---|---|---|
| [`app_health`](index.md) | `/health` | `GET` | — |

## États

—
