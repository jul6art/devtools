# workflows:inspect
`command.workflows.inspect` · type : commands · dernière mise à jour : 2026-09-17 · commit : a3b1c1b

## Résumé

`devtools workflows:inspect [path]` est le cœur de DevTools : il détecte les stacks, extrait les workflows
par l'adaptateur de chaque stack (Symfony, PHP générique ou voie Claude), décide pour chacun s'il faut le
créer, le réécrire, le garder ou le marquer orphelin, écrit les pages factuelles, le suivi, le menu et le
graphe, puis prépare les briefs pour Claude. Options : `--dry-run`, `--only`, `--force[=id]`, `--since`,
`--prune`, `--no-ai`.

## Déclencheur

| Élément | Valeur |
|---|---|
| Point d'entrée | `workflows:inspect` (command) |
| Sécurité | — |
| Préconditions | Le chemin donné, ou le dossier courant, est un dossier existant ; aucune autre inspection ne tourne sur ce projet. |

## Parcours

```mermaid
sequenceDiagram
  participant U as Développeur ou CI
  participant C as WorkflowsInspectCommand
  participant P as InspectionPipeline
  participant A as Adaptateur
  participant F as FreshnessResolver
  participant R as Rendu
  U->>C: devtools workflows:inspect [options]
  C->>P: run(InspectionOptions)
  P->>P: Initializer, ProjectLock
  P->>P: config.xml, StackDetector → stack.xml
  P->>A: extract(stack) pour chaque stack
  A-->>P: points d'entrée ou workflows découverts
  P->>P: WorkflowBuilder : identifiants, fichiers, tests
  P->>F: resolve(workflows, suivi, candidats git)
  F-->>P: create / rewrite / keep / orphan
  P->>R: pages, suivi, workflows.md, index.xml, graph/
  P->>P: briefs knowledge, discovery, page (sauf --no-ai)
  P-->>C: InspectionReport
  C-->>U: tableau, avertissements, rapport ; code 0, 1 ou 2
```

## Navigation / états

—

## Composants impliqués

| Rôle | Fichier | Notes |
|---|---|---|
| Autre | `src/Ai/PageBrief.php` |  |
| Autre | `src/Ai/XmlPageBriefStore.php` |  |
| Autre | `src/Clock/Clock.php` |  |
| Autre | `src/Clock/SystemClock.php` |  |
| Autre | `src/Command/WorkflowsInspectCommand.php` | point d'entrée |
| Autre | `src/Config/Config.php` |  |
| Autre | `src/Config/ConfigReaderInterface.php` |  |
| Autre | `src/Config/InvalidConfig.php` |  |
| Autre | `src/Config/XmlConfigReader.php` |  |
| Autre | `src/Inspection/Adapter/AdapterInterface.php` |  |
| Autre | `src/Inspection/Adapter/AdapterResult.php` |  |
| Autre | `src/Inspection/Adapter/Claude/ClaudeDrivenAdapter.php` |  |
| Autre | `src/Inspection/Adapter/Claude/Discovery.php` |  |
| Autre | `src/Inspection/Adapter/Claude/DiscoveryBrief.php` |  |
| Autre | `src/Inspection/Adapter/Claude/XmlDiscoveryBriefStore.php` |  |
| Autre | `src/Inspection/Adapter/GenericPhp/GenericPhpAdapter.php` |  |
| Autre | `src/Inspection/Adapter/Symfony/SymfonyAdapter.php` |  |
| Autre | `src/Inspection/AdapterResolver.php` |  |
| Autre | `src/Inspection/Freshness/DecisionKind.php` |  |
| Autre | `src/Inspection/Freshness/FileHasher.php` |  |
| Autre | `src/Inspection/Freshness/FreshnessDecision.php` |  |
| Autre | `src/Inspection/Freshness/FreshnessResolver.php` |  |
| Autre | `src/Inspection/Freshness/GitClient.php` |  |
| Autre | `src/Inspection/Freshness/GitState.php` |  |
| Autre | `src/Inspection/Freshness/Reason.php` |  |
| Autre | `src/Inspection/Freshness/ReasonKind.php` |  |
| Autre | `src/Inspection/Graph/BuildResult.php` |  |
| Autre | `src/Inspection/Graph/ClassLocator.php` |  |
| Autre | `src/Inspection/Graph/CoverageCalculator.php` |  |
| Autre | `src/Inspection/Graph/DependencyResolver.php` |  |
| Autre | `src/Inspection/Graph/EntryPointCandidate.php` |  |
| Autre | `src/Inspection/Graph/PhpReferenceExtractor.php` |  |
| Autre | `src/Inspection/Graph/PhpReferences.php` |  |
| Autre | `src/Inspection/Graph/TestLocator.php` |  |
| Autre | `src/Inspection/Graph/TwigReferenceExtractor.php` |  |
| Autre | `src/Inspection/Graph/WorkflowBuilder.php` |  |
| Autre | `src/Inspection/InspectionOptions.php` |  |
| Autre | `src/Inspection/InspectionPipeline.php` |  |
| Autre | `src/Inspection/InspectionReport.php` |  |
| Autre | `src/Inspection/Model/Confidence.php` |  |
| Autre | `src/Inspection/Model/Edge.php` |  |
| Autre | `src/Inspection/Model/EntryPoint.php` |  |
| Autre | `src/Inspection/Model/FileRef.php` |  |
| Autre | `src/Inspection/Model/FileRole.php` |  |
| Autre | `src/Inspection/Model/InspectionResult.php` |  |
| Autre | `src/Inspection/Model/InvalidModel.php` |  |
| Autre | `src/Inspection/Model/NonEmpty.php` |  |
| Autre | `src/Inspection/Model/PackageRef.php` |  |
| Autre | `src/Inspection/Model/Serialization/EntryPointXml.php` |  |
| Autre | `src/Inspection/Model/Serialization/ModelXmlSerializer.php` |  |
| Autre | `src/Inspection/Model/SortedList.php` |  |
| Autre | `src/Inspection/Model/StateMachine.php` |  |
| Autre | `src/Inspection/Model/Transition.php` |  |
| Autre | `src/Inspection/Model/Workflow.php` |  |
| Autre | `src/Inspection/Model/WorkflowId.php` |  |
| Autre | `src/Inspection/Model/WorkflowIdAssigner.php` |  |
| Autre | `src/Inspection/Model/WorkflowIdCollision.php` |  |
| Autre | `src/Inspection/Model/WorkflowIdDeriver.php` |  |
| Autre | `src/Inspection/Model/WorkflowSource.php` |  |
| Autre | `src/Inspection/Model/WorkflowType.php` |  |
| Autre | `src/Inspection/Model/WorkflowTypeRegistry.php` |  |
| Autre | `src/Project/PathOutsideProject.php` |  |
| Autre | `src/Project/ProjectLock.php` |  |
| Autre | `src/Project/ProjectRoot.php` |  |
| Autre | `src/Rendering/GraphRenderer.php` |  |
| Autre | `src/Rendering/Labels.php` |  |
| Autre | `src/Rendering/MarkdownWriter.php` |  |
| Autre | `src/Rendering/MenuRenderer.php` |  |
| Autre | `src/Rendering/MermaidWriter.php` |  |
| Autre | `src/Rendering/PageParser.php` |  |
| Autre | `src/Rendering/PageRenderer.php` |  |
| Autre | `src/Rendering/PageSection.php` |  |
| Autre | `src/Rendering/ParsedPage.php` |  |
| Autre | `src/Rendering/RenderingContext.php` |  |
| Autre | `src/Resources.php` |  |
| Autre | `src/Stack/Detector/ComposerDetector.php` |  |
| Autre | `src/Stack/Detector/LanguageOnlyDetector.php` |  |
| Autre | `src/Stack/Detector/NodeDetector.php` |  |
| Autre | `src/Stack/Detector/StackDetectorInterface.php` |  |
| Autre | `src/Stack/Knowledge/KnowledgeBrief.php` |  |
| Autre | `src/Stack/Knowledge/KnowledgeCanvas.php` |  |
| Autre | `src/Stack/Knowledge/KnowledgeProvider.php` |  |
| Autre | `src/Stack/Knowledge/XmlKnowledgeBriefStore.php` |  |
| Autre | `src/Stack/StackDetectionFailed.php` |  |
| Autre | `src/Stack/StackDetector.php` |  |
| Autre | `src/Stack/StackDocument.php` |  |
| Autre | `src/Stack/StackProfile.php` |  |
| Autre | `src/Stack/XmlStackStore.php` |  |
| Autre | `src/Tracking/AtomicFileWriter.php` |  |
| Autre | `src/Tracking/DevToolsDirectory.php` |  |
| Autre | `src/Tracking/FileLink.php` |  |
| Autre | `src/Tracking/FileRelation.php` |  |
| Autre | `src/Tracking/FilesToWorkflows.php` |  |
| Autre | `src/Tracking/FilesToWorkflowsReaderInterface.php` |  |
| Autre | `src/Tracking/FilesToWorkflowsWriterInterface.php` |  |
| Autre | `src/Tracking/Generation.php` |  |
| Autre | `src/Tracking/GenerationMode.php` |  |
| Autre | `src/Tracking/Index.php` |  |
| Autre | `src/Tracking/IndexEntry.php` |  |
| Autre | `src/Tracking/IndexReaderInterface.php` |  |
| Autre | `src/Tracking/IndexWriterInterface.php` |  |
| Autre | `src/Tracking/Initializer.php` |  |
| Autre | `src/Tracking/ReadsDocumentFiles.php` |  |
| Autre | `src/Tracking/Revision.php` |  |
| Autre | `src/Tracking/TrackedFile.php` |  |
| Autre | `src/Tracking/TrackingDocument.php` |  |
| Autre | `src/Tracking/TrackingReaderInterface.php` |  |
| Autre | `src/Tracking/TrackingStatus.php` |  |
| Autre | `src/Tracking/TrackingWriterInterface.php` |  |
| Autre | `src/Tracking/VcsState.php` |  |
| Autre | `src/Tracking/VcsXml.php` |  |
| Autre | `src/Tracking/XmlFilesToWorkflowsStore.php` |  |
| Autre | `src/Tracking/XmlIndexStore.php` |  |
| Autre | `src/Tracking/XmlTrackingStore.php` |  |
| Autre | `src/Version.php` |  |
| Autre | `src/Xml/DomBuilder.php` |  |
| Autre | `src/Xml/InvalidXml.php` |  |
| Autre | `src/Xml/SafeXmlLoader.php` |  |

Paquets : `nikic/php-parser` 5.9.0, `symfony/console` 8.1.7, `symfony/filesystem` 8.1.6, `symfony/process` 8.1.7

## Données

Lit le code et les manifestes du projet, `config.xml`, `stack.xml`, le suivi existant et l'état git ; écrit
tout `.devtools/` sauf `config.xml`, et le rapport dans `reports/`.

## Mécanismes transverses

Verrou par projet (`ProjectLock`) ; écritures atomiques ; au plus quatre processus git par inspection, et le
hash seul décide qu'un fichier a changé ; horloge reproductible par `SOURCE_DATE_EPOCH`.

## Points d'attention

Une page `keep` n'est pas réécrite d'un octet : `index.xml` garde son commit tant que rien ne change, sans
quoi chaque commit de documentation en appellerait un autre. Le code de sortie 1 signale un avertissement ou
un repli de console, pas une erreur : un script CI qui exige 0 échoue sur un projet Symfony qui ne démarre pas.

## Tests existants

| Test | Fichier | Couvre |
|---|---|---|
| PageRedactionTest | `tests/Ai/PageRedactionTest.php` | — |
| WorkflowsInspectCommandTest | `tests/Command/WorkflowsInspectCommandTest.php` | — |
| ClaudeDrivenTest | `tests/Inspection/Adapter/Claude/ClaudeDrivenTest.php` | — |
| GenericPhpAdapterTest | `tests/Inspection/Adapter/GenericPhp/GenericPhpAdapterTest.php` | — |
| FreshnessTest | `tests/Inspection/Freshness/FreshnessTest.php` | — |
| InspectionPipelineTest | `tests/Inspection/Pipeline/InspectionPipelineTest.php` | — |
| RescanPerformanceTest | `tests/Performance/RescanPerformanceTest.php` | — |
| KnowledgeTest | `tests/Stack/Knowledge/KnowledgeTest.php` | — |

## Workflows liés

—

## Historique

| Date | Commit | Changement |
|---|---|---|
| 2026-09-17 | a3b1c1b | rédaction initiale |
