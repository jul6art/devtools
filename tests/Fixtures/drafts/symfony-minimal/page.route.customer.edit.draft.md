---
model: claude-opus-5-5
revision: 2026-09-16T15:00:00+02:00
---

## Summary

Modifie la fiche d'un client : son nom et son site de rattachement, puis recalcule son rang à
l'enregistrement. L'écran n'est ouvert qu'à qui peut modifier ce client, et les sites proposés dépendent
de qui le modifie.

## Preconditions

Utilisateur connecté (ROLE_USER) autorisé par le voter sur ce client.

## Journey

```mermaid
sequenceDiagram
  participant U as Utilisateur
  participant C as CustomerController::edit
  participant R as CustomerRepository
  participant V as CustomerVoter
  participant F as CustomerType
  participant T as CustomerTiering
  U->>C: GET|POST /customers/{id}/edit
  C->>R: get(id)
  R-->>C: Customer
  C->>V: denyAccessUnlessGranted(CUSTOMER_EDIT, customer)
  V-->>C: accès accordé, ou refus
  C->>F: createForm(customer, user, can_change_site)
  F-->>C: formulaire soumis et valide
  C->>T: apply(customer)
  T-->>C: tier recalculé
  C->>R: save(customer)
  C-->>U: redirect app_customer_edit
```

## Decisions

**`App\Entity\Customer::tier`**

```mermaid
flowchart TD
  d1{"plafond de crédit au-delà de 100 000"}
  d1 -->|oui| v1["platinum"]
  d1 -->|non| d2{"plafond de crédit au-delà de 10 000"}
  d2 -->|oui| v2["gold"]
  d2 -->|non| v3["le rang par défaut, calculé ailleurs"]
  v4["valeurs admises à la validation : standard, gold, platinum"]
```

**`App\Entity\Customer::creditLimit`**

```mermaid
flowchart TD
  d1{"plafond positif, ou client platinum"}
  d1 -->|oui| v1["valide"]
  d1 -->|non| v2["refusé à la validation"]
```

**`App\Entity\Customer::vatNumber`**

```mermaid
flowchart TD
  d1{"client suisse"}
  d1 -->|oui| v1["numéro de TVA obligatoire"]
  d1 -->|non| v2["facultatif"]
```

**`App\Entity\Customer::site`**

```mermaid
flowchart TD
  d1["sites visibles par l'utilisateur qui modifie"] --> v1["seuls choix proposés"]
```

**`App\Form\CustomerType::site.disabled`**

```mermaid
flowchart TD
  d1{"l'utilisateur peut changer le site"}
  d1 -->|oui| v1["champ ouvert"]
  d1 -->|non| v2["champ grisé"]
```

## Data

Lit et écrit un `Customer` via `src/Repository/CustomerRepository.php` ; lit les sites proposés via
`src/Repository/SiteRepository.php`.

## Cross-cutting mechanisms

Le voter `CustomerVoter` décide l'accès : refusé sans utilisateur connecté, accordé à un manager, sinon
accordé seulement au gestionnaire de compte du client. La locale de la requête est fixée par
`LocaleListener` avant le contrôleur.

## Points of attention

Le rang par défaut est décidé par `defaultTier()`, que le schéma ne détaille pas : le calcul est un appel
plus loin. Les sites proposés ne sont filtrés que par l'utilisateur passé en option : un appel sans cette
option n'en propose aucun.

## Change

rédaction initiale
