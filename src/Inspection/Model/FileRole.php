<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Model;

enum FileRole: string
{
    case Controller = 'controller';
    case Service = 'service';
    case Form = 'form';
    case Template = 'template';
    case Entity = 'entity';
    case Repository = 'repository';
    case Config = 'config';
    case Listener = 'listener';
    case Message = 'message';
    case Handler = 'handler';
    case Component = 'component';
    case Test = 'test';
    case Other = 'other';
}
