# Glossary — jul6art/devtools

The words of this project, as its pages use them. Back to the [workflows](workflows.md).

| Term | Definition | Also called | Workflows |
|---|---|---|---|
| **claude** | Claude Code, l'agent qui rédige la prose des pages et que DevTools outille : skills, hooks, serveur MCP et vérificateur. | — | `command.claude.hook`, `command.claude.install`, `command.claude.uninstall` |
| **feedback** (retour) | Une remarque de revue enregistrée comme donnée — son workflow, sa catégorie, une ligne — et comptée quand elle revient. | — | `command.feedback.add`, `command.feedback.list`, `command.feedback.resolve` |
| **knowledge** (connaissance) | La fiche qui dit comment une pile fonctionne : où le framework met ses routes, ses règles et ses effets de bord. Aucune page de cette pile ne s'écrit sans elle. | — | `command.knowledge.amend`, `command.knowledge.list`, `command.knowledge.promote` |
| **rules** (règle) | Une consigne née d'un retour vu deux fois, donnée à Claude avant qu'il modifie un fichier qu'elle vise. Une assertion peut la faire respecter dans la gate. | — | `command.rules.activate`, `command.rules.archive`, `command.rules.list`, `command.rules.promote` |
| **workflows** (workflow) | Un parcours que le code expose — route, commande, message, composant —, avec sa page et son fichier de suivi : l'unité que DevTools documente. | — | `command.workflows.accept`, `command.workflows.apply`, `command.workflows.check`, `command.workflows.diff`, `command.workflows.impact`, `command.workflows.inspect`, `command.workflows.reject`, `command.workflows.rename`, `command.workflows.review` |
