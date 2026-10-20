# /customers/{id}/edit
`customer.edit` · type: routes · 1 route · `src/Controller/CustomerController.php`

## Summary

La fiche client, ouverte à qui peut modifier ce client selon son voter. On y change le nom et le site de
rattachement — parmi les sites que l'utilisateur voit — et le rang du client se recalcule d'après son
plafond de crédit.

## Routes

| Route | Path | Methods | Security |
|---|---|---|---|
| [`app_customer_edit`](index.md) | `/customers/{id}/edit` | `GET\|POST` | `ROLE_USER`, `CUSTOMER_EDIT` |

## States

—
