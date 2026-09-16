<?php

declare(strict_types=1);

require_once __DIR__.'/../../lib/db.php';

if ('POST' === $_SERVER['REQUEST_METHOD']) {
    new Acme\OrderRepository(db())->add((string) $_POST['customer']);
    header('Location: /index.php');

    exit;
}
?>
<form method="post" action="new.php">
    <input name="customer">
    <button>Save</button>
</form>
<a href="/index.php">Back</a>
