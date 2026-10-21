<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Ai\Api;

/**
 * A task `ai:run` does not send: a secret, an excluded or oversized file. Nothing left the machine.
 */
final class TaskRefused extends \RuntimeException
{
}
