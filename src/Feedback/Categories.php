<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Feedback;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

/**
 * The closed list of feedback categories (ADR-0025): the ten DevTools ships, and those the project adds in
 * `config.xml`. A feedback, a finding of the verifier, a rule: all are filed under one of them.
 */
final readonly class Categories
{
    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/feedback-categories/1';

    /**
     * @var array<string, string> name => what it covers
     */
    public array $all;

    /**
     * @param list<string> $custom the categories the project adds
     */
    public function __construct(array $custom = [])
    {
        $file = Resources::path('feedback/categories.xml');
        $root = DomBuilder::root(new SafeXmlLoader()->loadValidated((string) file_get_contents($file), $file, Resources::path('schemas/feedback-categories.xsd'), 1));
        $all = [];

        foreach (new DomBuilder(self::NAMESPACE)->children($root, 'category') as $category) {
            $all[$category->getAttribute('name')] = trim($category->textContent);
        }

        foreach ($custom as $name) {
            $all[$name] ??= 'Added by the project.';
        }

        $this->all = $all;
    }

    public static function of(Config $config): self
    {
        return new self($config->feedbackCategories);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->all);
    }

    public function has(string $name): bool
    {
        return isset($this->all[$name]);
    }
}
