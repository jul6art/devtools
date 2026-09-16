<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Model;

/**
 * A kind of trigger, and therefore a sub-menu of `workflows.md` and a folder of `workflows/`.
 *
 * The seven native types of specs § 4.2 are named constructors. A project may declare more in
 * `.devtools/config.xml` (§ 4.2: "extensible par configuration"), which is why this is a value
 * object and not an enum: an enum could not hold a type only a project knows. The set in force is a
 * {@see WorkflowTypeRegistry}.
 */
final readonly class WorkflowType
{
    /**
     * Folder name => identifier prefix, in the order of specs § 4.2 — which is also the menu order.
     */
    public const array NATIVE = [
        'routes' => 'route',
        'commands' => 'command',
        'async' => 'async',
        'events' => 'event',
        'ui' => 'ui',
        'integrations' => 'integration',
        'data' => 'data',
    ];

    private function __construct(public string $name, public string $idPrefix)
    {
    }

    public static function routes(): self
    {
        return self::native('routes');
    }

    public static function commands(): self
    {
        return self::native('commands');
    }

    public static function async(): self
    {
        return self::native('async');
    }

    public static function events(): self
    {
        return self::native('events');
    }

    public static function ui(): self
    {
        return self::native('ui');
    }

    public static function integrations(): self
    {
        return self::native('integrations');
    }

    public static function data(): self
    {
        return self::native('data');
    }

    public static function native(string $name): self
    {
        if (!isset(self::NATIVE[$name])) {
            throw new InvalidModel(\sprintf('"%s" is not a native workflow type.', $name));
        }

        return new self($name, self::NATIVE[$name]);
    }

    public static function custom(string $name, string $idPrefix): self
    {
        NonEmpty::slug($name, 'workflow type name');
        NonEmpty::slug($idPrefix, 'identifier prefix');

        if (isset(self::NATIVE[$name]) || \in_array($idPrefix, self::NATIVE, true)) {
            throw new InvalidModel(\sprintf('The custom workflow type "%s" (prefix "%s") would shadow a native type.', $name, $idPrefix));
        }

        return new self($name, $idPrefix);
    }

    public function isNative(): bool
    {
        return isset(self::NATIVE[$this->name]);
    }
}
