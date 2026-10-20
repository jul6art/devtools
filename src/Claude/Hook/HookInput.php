<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Claude\Hook;

/**
 * What Claude Code sends a hook on its standard input (ADR-0019) — the fields DevTools reads, nothing else.
 */
final readonly class HookInput
{
    public function __construct(
        public string $event,
        public ?string $toolName = null,
        public ?string $filePath = null,
        public ?string $cwd = null,
        public bool $stopHookActive = false,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the input is not the JSON object of a hook
     */
    public static function parse(string $json): self
    {
        try {
            $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $invalid) {
            throw new \InvalidArgumentException('The hook input is not JSON: '.$invalid->getMessage(), $invalid->getCode(), previous: $invalid);
        }

        if (!\is_array($data) || !\is_string($data['hook_event_name'] ?? null)) {
            throw new \InvalidArgumentException('The hook input names no event (hook_event_name).');
        }

        $tool = \is_array($data['tool_input'] ?? null) ? $data['tool_input'] : [];

        return new self(
            $data['hook_event_name'],
            \is_string($data['tool_name'] ?? null) ? $data['tool_name'] : null,
            \is_string($tool['file_path'] ?? null) ? $tool['file_path'] : null,
            \is_string($data['cwd'] ?? null) ? $data['cwd'] : null,
            true === ($data['stop_hook_active'] ?? false),
        );
    }
}
