<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Release;

/**
 * `self-update` installs nothing: the release cannot be read, its checksum or its provenance does not hold.
 */
final class UpdateRefused extends \RuntimeException
{
}
