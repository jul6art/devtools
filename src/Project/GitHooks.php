<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Project;

use Symfony\Component\Process\Process;

/**
 * Installs and removes the DevTools blocks of a project's git hooks (ADR-0017).
 *
 * ⚠️ A hook that already exists is never overwritten: the block is appended between two markers and
 * removed at the byte on uninstall. Someone else's `pre-commit` is someone else's work.
 *
 * ⚠️ The hooks it writes never block a commit for a technical reason — DevTools missing, PHP missing,
 * project without `.devtools/`. A gate that stops the work because of itself is a gate that gets
 * uninstalled, and with it the one thing that kept the documentation honest.
 */
final readonly class GitHooks
{
    public const string BEGIN = '# >>> devtools >>>';

    public const string END = '# <<< devtools <<<';

    private const array HOOKS = ['pre-commit', 'post-merge', 'post-checkout'];

    public function __construct(private string $binary = 'vendor/bin/devtools')
    {
    }

    /**
     * @return list<string> the hooks it wrote, relative to the project
     */
    public function install(ProjectRoot $root, bool $strict = false): array
    {
        $directory = self::directory($root);

        if (null === $directory) {
            return [];
        }

        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        $written = [];

        foreach (self::HOOKS as $hook) {
            $file = $directory.'/'.$hook;
            $existing = is_file($file) ? (string) file_get_contents($file) : '';
            $block = self::BEGIN."\n".$this->body($hook, $strict).self::END."\n";

            $content = match (true) {
                '' === trim($existing) => "#!/bin/sh\n".$block,
                str_contains($existing, self::BEGIN) => (string) preg_replace('/'.preg_quote(self::BEGIN, '/').'.*?'.preg_quote(self::END, '/')."\n?/s", $block, $existing),
                default => rtrim($existing, "\n")."\n\n".$block,
            };

            file_put_contents($file, $content);
            chmod($file, 0o755);
            $written[] = $hook;
        }

        return $written;
    }

    /**
     * @return list<string> the hooks it cleaned
     */
    public function uninstall(ProjectRoot $root): array
    {
        $directory = self::directory($root);

        if (null === $directory) {
            return [];
        }

        $cleaned = [];

        foreach (self::HOOKS as $hook) {
            $file = $directory.'/'.$hook;

            if (!is_file($file)) {
                continue;
            }

            $content = (string) file_get_contents($file);

            if (!str_contains($content, self::BEGIN)) {
                continue;
            }

            $without = (string) preg_replace('/\n*'.preg_quote(self::BEGIN, '/').'.*?'.preg_quote(self::END, '/')."\n?/s", '', $content);

            // A hook that held nothing but our block is one we created: it goes away with it.
            if ('' === trim(str_replace('#!/bin/sh', '', $without))) {
                unlink($file);
            } else {
                file_put_contents($file, rtrim($without, "\n")."\n");
            }

            $cleaned[] = $hook;
        }

        return $cleaned;
    }

    /**
     * `core.hooksPath` when the project configured one — a repository that moved its hooks elsewhere
     * would otherwise get them installed where git never looks.
     */
    private static function directory(ProjectRoot $root): ?string
    {
        $process = new Process(['git', 'rev-parse', '--git-path', 'hooks'], $root->path);

        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        if (!$process->isSuccessful()) {
            return null;
        }

        $path = trim($process->getOutput());

        return str_starts_with($path, '/') ? $path : $root->path.'/'.$path;
    }

    private function body(string $hook, bool $strict): string
    {
        $guard = <<<SH
            DEVTOOLS="\${DEVTOOLS:-{$this->binary}}"
            if [ ! -x "\$DEVTOOLS" ] && ! command -v "\$DEVTOOLS" >/dev/null 2>&1; then
                exit 0
            fi
            if [ ! -d .devtools ]; then
                exit 0
            fi

            SH;

        return $guard.match ($hook) {
            // ⚠️ The check warns and lets the commit through unless --strict was asked for: a
            // documentation gate that blocks a commit is the first thing a team disables.
            'pre-commit' => $strict
                ? "\$DEVTOOLS workflows:check || exit 1\n"
                : "\$DEVTOOLS workflows:check || echo '/!\\ la documentation des workflows a dérivé : devtools workflows:diff' >&2\nexit 0\n",
            default => "\$DEVTOOLS workflows:inspect --no-ai >/dev/null 2>&1 || true\nexit 0\n",
        };
    }
}
