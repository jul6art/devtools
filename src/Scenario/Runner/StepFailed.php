<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Runner;

/**
 * The application did not do what the step says: the verdict is `failed`, not `error`.
 */
final class StepFailed extends \RuntimeException
{
}
