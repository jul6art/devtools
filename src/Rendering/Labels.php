<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rendering;

use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;

/**
 * The French labels of the page template and the menu (specs § 4.5, § 4.7).
 *
 * @internal
 */
final class Labels
{
    private const array TYPES = [
        'routes' => 'Routes',
        'commands' => 'Commands',
        'async' => 'Async',
        'ui' => 'UI',
        'integrations' => 'Integrations',
        'data' => 'Data',
    ];

    public static function type(WorkflowType $type): string
    {
        return self::TYPES[$type->name] ?? ucfirst($type->name);
    }

    public static function role(FileRole $role): string
    {
        return match ($role) {
            FileRole::Controller => 'Controller',
            FileRole::Service => 'Service',
            FileRole::Form => 'Form',
            FileRole::Template => 'Template',
            FileRole::Entity => 'Entity',
            FileRole::Repository => 'Repository',
            FileRole::Config => 'Configuration',
            FileRole::Listener => 'Listener',
            FileRole::Message => 'Message',
            FileRole::Handler => 'Handler',
            FileRole::Component => 'Component',
            FileRole::Test => 'Test',
            FileRole::Other => 'Other',
        };
    }
}
