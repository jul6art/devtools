# self-update
`command.self-update` · type: commands · last updated: 2026-10-21 · commit: 240bcc3

## Summary

Remplace le phar de DevTools par la dernière version publiée (ADR-0034), après avoir vérifié sa somme SHA-256 et sa provenance — l'attestation de build GitHub, par gh attestation verify. Un phar dont l'une ou l'autre ne se vérifie pas n'est jamais installé. Une installation par Composer est renvoyée à composer update : la commande ne remplace qu'un phar.

## Trigger

| Element | Value |
|---|---|
| Entry point | `self-update` (command) |
| Security | — |
| Preconditions | DevTools tourne depuis son phar, et gh est installé pour vérifier la provenance. |

## Journey

```mermaid
sequenceDiagram
  participant U as Humain
  participant C as self-update
  participant S as SelfUpdater
  participant G as GitHubReleases
  U->>C: devtools self-update
  C->>C: lancé depuis un phar ?
  C->>S: phar courant, version courante
  S->>G: dernière version publiée
  G-->>S: version, phar, somme
  S->>S: SHA-256, puis gh attestation verify
  S-->>C: remplacé, ou déjà à jour
  C-->>U: version installée, vérifications faites
```

## Decisions

**`Jul6Art\DevTools\Command\SelfUpdateCommand::execute`**

```mermaid
flowchart TD
  d1{"DevTools ne tourne pas depuis un phar (installé par Composer)"}
  d1 -->|oui| v1["FAILURE : composer update jul6art/devtools"]
  d1 -->|non| d2{"la publication est introuvable, ou sa somme ou sa provenance ne se vérifie pas"}
  d2 -->|oui| v2["FAILURE : le phar en place est gardé"]
  d2 -->|non| v3["SUCCESS : remplacé, ou déjà la dernière version"]
```

## Data

Lit la dernière publication sur GitHub — l'une des deux classes de DevTools autorisées à ouvrir une connexion —, télécharge le phar et sa somme, appelle gh pour la provenance ; remplace le phar en place, atomiquement.

## Cross-cutting mechanisms

Aucun.

## Points of attention

Sans gh, la provenance ne se vérifie pas et rien n'est remplacé : c'est voulu, une somme seule venant du même endroit que le phar. Le remplacement suppose le droit d'écrire le fichier du phar.

## Related workflows

—

## History

| Date | Commit | Change |
|---|---|---|
| 2026-10-21 | 240bcc3 | rédaction initiale |
