<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Link;

/**
 * What a neighbour is to the project that declares it (ADR-0038): the client calling its routes, or the
 * server answering its calls.
 */
enum LinkRole: string
{
    case Client = 'client';
    case Server = 'server';
}
