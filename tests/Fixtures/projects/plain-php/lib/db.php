<?php

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;

    return $pdo ??= new PDO('sqlite:'.__DIR__.'/../var/app.sqlite');
}
