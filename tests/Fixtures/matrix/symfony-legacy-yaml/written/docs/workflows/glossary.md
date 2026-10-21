# Glossary — acme/symfony-legacy-yaml

The words of this project, as its pages use them. Back to the [workflows](workflows.md).

| Term | Definition | Also called | Workflows |
|---|---|---|---|
| **CartSummary** | Composant live qui résume le panier de l'utilisateur dans l'en-tête des pages. | — | `ui.cart-summary` |
| **Customer** (Client) | Client à qui une commande est rattachée, identifié par son nom et son site. | — | `route.customer.edit` |
| **Order** (Commande) | Commande passée pour un client sur un produit, tarifée puis validée par un opérateur. | purchase | `async.order-created`, `route.order.index`, `route.order.new`, `route.order.show`, `route.order.validate`, `ui.cart-summary` |
| **OrderCreated** | Message publié quand une commande est créée ; ses gestionnaires notifient et préparent la suite. | — | `async.order-created` |
| **Product** | Article du catalogue qu'une commande vise, avec son prix. | — | `command.app.import-catalog`, `route.order.new` |
| **Site** | Établissement d'un client, auquel ses commandes sont livrées. | — | `route.customer.edit` |
