<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Glossary;

use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Inspection\Model\Workflow;

/**
 * The terms the model names (ADR-0037), deterministically: the short names of the entities and messages the
 * workflows traverse, the components of the interface, and the words of the identifiers three workflows or more
 * share — `order` in `route.order.index`, `route.order.new`, `route.order.show`.
 */
final class GlossaryCollector
{
    public const int SHARED = 3;

    /**
     * Words every project's identifiers hold, which name an action or a shape and no business thing.
     */
    private const array COMMON = ['add', 'admin', 'all', 'api', 'app', 'create', 'delete', 'detail', 'edit', 'get', 'health', 'home', 'id', 'index', 'list', 'new', 'patch', 'post', 'put', 'remove', 'root', 'show', 'store', 'update', 'view'];

    /**
     * @param list<Workflow> $workflows
     *
     * @return array<string, array{name: string, workflows: list<string>, files: list<string>}> by term key
     */
    public function collect(array $workflows): array
    {
        $terms = [];
        $segments = [];

        foreach ($workflows as $workflow) {
            $id = $workflow->id->value;

            foreach ($workflow->files as $file) {
                if (\in_array($file->role, [FileRole::Entity, FileRole::Message], true)) {
                    self::add($terms, self::shortName($file), $id, $file->path);
                }
            }

            if ('ui' === $workflow->type->name) {
                self::add($terms, (string) preg_replace('/Component$/', '', $workflow->title), $id, $workflow->main->declaredIn->path);
            }

            foreach (\array_slice(explode('.', $id), 1) as $segment) {
                if (1 === preg_match('/^[a-z][a-z-]{2,}$/', $segment) && !\in_array($segment, self::COMMON, true)) {
                    $segments[$segment][$id] = true;
                }
            }
        }

        foreach ($segments as $segment => $ids) {
            if (\count($ids) >= self::SHARED) {
                // `orders` in the routes is the `Order` of the entities: a plural joins its singular.
                $singular = str_ends_with($segment, 's') && isset($terms[Term::key(substr($segment, 0, -1))]) ? substr($segment, 0, -1) : $segment;

                foreach (array_keys($ids) as $id) {
                    self::add($terms, $singular, (string) $id, null);
                }
            }
        }

        ksort($terms, \SORT_STRING);

        return array_map(static function (array $term): array {
            $workflows = array_keys($term['workflows']);
            $files = array_keys($term['files']);
            sort($workflows, \SORT_STRING);
            sort($files, \SORT_STRING);

            return ['name' => $term['name'], 'workflows' => $workflows, 'files' => $files];
        }, $terms);
    }

    /**
     * @param array<string, array{name: string, workflows: array<string, true>, files: array<string, true>}> $terms
     */
    private static function add(array &$terms, string $name, string $workflow, ?string $file): void
    {
        $key = Term::key($name);

        if ('' === $key) {
            return;
        }

        // The class name wins over the identifier's word: `Order` rather than `order`.
        $terms[$key] ??= ['name' => $name, 'workflows' => [], 'files' => []];

        if (ctype_upper($name[0]) && !ctype_upper($terms[$key]['name'][0])) {
            $terms[$key]['name'] = $name;
        }

        $terms[$key]['workflows'][$workflow] = true;

        if (null !== $file) {
            $terms[$key]['files'][$file] = true;
        }
    }

    private static function shortName(FileRef $file): string
    {
        return (string) preg_replace('/\..*$/', '', basename($file->path));
    }
}
