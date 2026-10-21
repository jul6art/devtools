<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Glossary;

enum TermStatus: string
{
    case Candidate = 'candidate';
    case Defined = 'defined';
    case Ignored = 'ignored';
}
