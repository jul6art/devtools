<?php

declare(strict_types=1);

require __DIR__.'/../lib/db.php';
include __DIR__.'/../lib/views/header.php';

$orders = new Acme\OrderRepository(db())->all();
?>
<h1>Orders</h1>
<ul>
<?php foreach ($orders as $order) { ?>
    <li><?= htmlspecialchars($order['customer']) ?></li>
<?php } ?>
</ul>
<form action="orders/new.php" method="get"><button>New order</button></form>
<a href="orders/new.php">New order</a>
