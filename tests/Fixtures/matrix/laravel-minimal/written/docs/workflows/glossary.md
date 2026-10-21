# Glossary — acme/laravel-minimal

The words of this project, as its pages use them. Back to the [workflows](workflows.md).

| Term | Definition | Also called | Workflows |
|---|---|---|---|
| **Alert** | Composant Blade qui affiche un message d'information, de succès ou d'erreur. | — | `ui.alert` |
| **CartSummary** | Composant Livewire qui affiche le nombre d'articles du panier. | — | `ui.cart-summary` |
| **Order** (Commande) | Commande identifiée par sa référence, créée par un utilisateur connecté. | purchase | `async.send-order-confirmation`, `command.orders.import`, `data.seeders`, `route.orders.index`, `route.orders.show`, `route.orders.store` |
| **OrderPlaced** | Événement annoncé quand une commande est enregistrée ; l'entrepôt l'écoute. | — | `route.orders.store` |
