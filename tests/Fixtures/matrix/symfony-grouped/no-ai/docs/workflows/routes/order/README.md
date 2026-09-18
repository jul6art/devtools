# /orders
`order` · type : routes · 4 routes · `src/Controller/OrderController.php`

## Résumé

—

## Routes

| Route | Chemin | Méthodes | Sécurité |
|---|---|---|---|
| [`app_order_index`](index.md) | `/orders` | `GET` | `ROLE_USER` |
| [`app_order_new`](new.md) | `/orders/new` | `GET\|POST` | `ROLE_USER`, `ROLE_OPERATOR` |
| [`app_order_show`](show.md) | `/orders/{id}` | `GET` | `ROLE_USER` |
| [`app_order_validate`](validate.md) | `/orders/{id}/validate` | `POST` | `ROLE_USER`, `ROLE_MANAGER` |

## États

```mermaid
stateDiagram-v2
  state "draft" as s1
  state "validated" as s2
  state "shipped" as s3
  [*] --> s1
  s1 --> s2 : validate
  s2 --> s3 : ship
```
