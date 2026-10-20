<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Claude;

use Jul6Art\DevTools\Tracking\AtomicFileWriter;

/**
 * The Claude Code configuration files of a project DevTools adds itself to — `.claude/settings.json` for
 * the hooks (ADR-0019), `.mcp.json` for the MCP server (ADR-0018) — merged, never overwritten, and given
 * back as they were.
 *
 * ⚠️ The JSON is read as objects: decoded to arrays, an empty `{}` of the project would come back as `[]`,
 * and Claude Code would read another file than the one the team wrote.
 */
final readonly class ClaudeSettings
{
    /**
     * What every hook command DevTools installs carries: the way to find them again, and only them.
     */
    public const string HOOK = 'claude:hook';

    /**
     * The name of DevTools' server in `.mcp.json`: Claude Code shows its tools as `mcp__devtools__<tool>`.
     */
    public const string MCP_SERVER = 'devtools';

    public function __construct(private AtomicFileWriter $writer = new AtomicFileWriter())
    {
    }

    /**
     * @param array<string, string|null> $events event => matcher (null: every occurrence)
     * @param string                     $binary how the project runs DevTools
     *
     * @return bool whether the file changed
     *
     * @throws \InvalidArgumentException when the existing file is not a JSON object: it is the team's, and is left alone
     */
    public function installHooks(string $file, array $events, string $binary): bool
    {
        $settings = self::read($file);
        $hooks = $settings->hooks ?? new \stdClass();

        if (!$hooks instanceof \stdClass) {
            throw new \InvalidArgumentException(\sprintf('%s: "hooks" is not an object; DevTools leaves it to you.', $file));
        }

        foreach ($events as $event => $matcher) {
            $command = $binary.' '.self::HOOK.' '.$event;
            $groups = \is_array($hooks->{$event} ?? null) ? $hooks->{$event} : [];

            if (array_any($groups, static fn (mixed $group): bool => $group instanceof \stdClass && \is_array($group->hooks ?? null) && array_any($group->hooks, static fn (mixed $hook): bool => $hook instanceof \stdClass && ($hook->command ?? null) === $command))) {
                continue;
            }

            $group = new \stdClass();

            if (null !== $matcher) {
                $group->matcher = $matcher;
            }

            $group->hooks = [(object) ['type' => 'command', 'command' => $command, 'timeout' => 10]];
            $groups[] = $group;
            $hooks->{$event} = $groups;
        }

        $settings->hooks = $hooks;

        return $this->writer->write($file, self::encode($settings));
    }

    /**
     * Removes the hooks DevTools installed and nothing else; a group, an event, the `hooks` key and the file
     * itself go when DevTools' entries were all they held.
     *
     * @return bool whether anything changed
     */
    public function uninstallHooks(string $file): bool
    {
        if (!is_file($file)) {
            return false;
        }

        $settings = self::read($file);
        $before = self::encode($settings);

        if (!($settings->hooks ?? null) instanceof \stdClass) {
            return false;
        }

        foreach (get_object_vars($settings->hooks) as $event => $groups) {
            if (!\is_array($groups)) {
                continue;
            }

            $kept = [];

            foreach ($groups as $group) {
                if ($group instanceof \stdClass && \is_array($group->hooks ?? null)) {
                    $group->hooks = array_values(array_filter($group->hooks, static fn (mixed $hook): bool => !self::installed($hook)));

                    if ([] === $group->hooks) {
                        continue;
                    }
                }

                $kept[] = $group;
            }

            if ([] === $kept) {
                unset($settings->hooks->{$event});
            } else {
                $settings->hooks->{$event} = $kept;
            }
        }

        if ([] === get_object_vars($settings->hooks)) {
            unset($settings->hooks);
        }

        if (self::encode($settings) === $before) {
            return false;
        }

        if ([] === get_object_vars($settings)) {
            return unlink($file);
        }

        return $this->writer->write($file, self::encode($settings));
    }

    /**
     * Declares DevTools' MCP server in `.mcp.json`. A server of that name DevTools did not write is the team's:
     * kept, unless `$force`.
     *
     * @param list<string> $args
     *
     * @return bool whether the file changed
     *
     * @throws \InvalidArgumentException when the file is not a JSON object, or names another server "devtools"
     */
    public function installMcpServer(string $file, string $command, array $args, bool $force = false): bool
    {
        $configuration = self::read($file);
        $servers = $configuration->mcpServers ?? new \stdClass();

        if (!$servers instanceof \stdClass) {
            throw new \InvalidArgumentException(\sprintf('%s: "mcpServers" is not an object; DevTools leaves it to you.', $file));
        }

        $existing = $servers->{self::MCP_SERVER} ?? null;

        if (null !== $existing && !$force && !self::isMcpServer($existing)) {
            throw new \InvalidArgumentException(\sprintf('%s already declares a server "%s" DevTools did not write; it was left as is. Use --force to replace it.', $file, self::MCP_SERVER));
        }

        $servers->{self::MCP_SERVER} = (object) ['type' => 'stdio', 'command' => $command, 'args' => $args];
        $configuration->mcpServers = $servers;

        return $this->writer->write($file, self::encode($configuration));
    }

    /**
     * Removes DevTools' MCP server, and the file when it was all it held. A server named "devtools" that
     * DevTools did not write stays.
     *
     * @return bool whether anything changed
     */
    public function uninstallMcpServer(string $file): bool
    {
        if (!is_file($file)) {
            return false;
        }

        $configuration = self::read($file);
        $servers = $configuration->mcpServers ?? null;

        if (!$servers instanceof \stdClass || !self::isMcpServer($servers->{self::MCP_SERVER} ?? null)) {
            return false;
        }

        unset($servers->{self::MCP_SERVER});

        if ([] === get_object_vars($servers)) {
            unset($configuration->mcpServers);
        }

        if ([] === get_object_vars($configuration)) {
            return unlink($file);
        }

        return $this->writer->write($file, self::encode($configuration));
    }

    private static function isMcpServer(mixed $server): bool
    {
        return $server instanceof \stdClass && \is_string($server->command ?? null) && str_contains($server->command, 'devtools') && \is_array($server->args ?? null) && \in_array('mcp:serve', $server->args, true);
    }

    private static function installed(mixed $hook): bool
    {
        return $hook instanceof \stdClass && \is_string($hook->command ?? null) && str_contains($hook->command, 'devtools') && str_contains($hook->command, self::HOOK);
    }

    private static function read(string $file): \stdClass
    {
        if (!is_file($file)) {
            return new \stdClass();
        }

        try {
            $settings = json_decode((string) file_get_contents($file), false, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $invalid) {
            throw new \InvalidArgumentException(\sprintf('%s is not valid JSON (%s); DevTools leaves it to you.', $file, $invalid->getMessage()), $invalid->getCode(), previous: $invalid);
        }

        if (!$settings instanceof \stdClass) {
            throw new \InvalidArgumentException(\sprintf('%s is not a JSON object; DevTools leaves it to you.', $file));
        }

        return $settings;
    }

    private static function encode(\stdClass $settings): string
    {
        return json_encode($settings, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n";
    }
}
