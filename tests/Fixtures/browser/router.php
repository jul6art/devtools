<?php

declare(strict_types=1);

/*
 * A small site the scenario runner is tested against (ADR-0021), served by `php -S` with this router: every
 * action of the vocabulary has something here to act on, and a Select2 field as an application integrates it.
 */

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = (string) parse_url(is_string($uri) ? $uri : '/', \PHP_URL_PATH);
$query = static fn (string $name): string => is_string($_GET[$name] ?? null) ? $_GET[$name] : '';

if (str_starts_with($path, '/vendor/')) {
    return false;
}

$page = static function (string $title, string $body, string $head = ''): void {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>'.$title.'</title>'.$head.'</head><body>'.$body.'</body></html>';
};
$escape = static fn (string $text): string => htmlspecialchars($text, \ENT_QUOTES);

if ('/' === $path) {
    $page('Home', <<<'HTML'
        <nav><a href="/form">Order form</a> <a href="/items/7">Item seven</a> <span role="button" onclick="location.href='/form'">Go</span></nav>
        <h1>Home</h1>
        <ul class="items"><li>One</li><li>Two</li><li>Three</li></ul>
        <p class="hidden" style="display: none">Hidden</p>
        <p id="late"></p>
        <script>setTimeout(function () { var p = document.getElementById('late'); p.className = 'arrived'; p.textContent = 'Arrived late'; }, 400);</script>
        HTML);

    return true;
}

if (1 === preg_match('#^/items/([^/]+)$#', $path, $item)) {
    $page('Item', '<h1>Item #'.$escape(rawurldecode($item[1])).'</h1><p class="query">'.$escape($query('tab')).'</p>');

    return true;
}

if ('/form' === $path && 'POST' === $_SERVER['REQUEST_METHOD']) {
    header('Location: /done?'.http_build_query(['customer' => $_POST['customer'] ?? '', 'status' => $_POST['status'] ?? '', 'urgent' => isset($_POST['urgent']) ? 'yes' : 'no', 'product' => $_POST['product'] ?? '']), true, 303);

    return true;
}

if ('/form' === $path) {
    $page('Order form', <<<'HTML'
        <form id="order" method="post" action="/form">
          <label for="customer">Customer</label><input id="customer" name="customer" value="nobody">
          <label>Status <select name="status"><option value="new">New</option><option value="paid">Paid</option></select></label>
          <label for="urgent">Urgent</label><input type="checkbox" id="urgent" name="urgent">
          <input type="hidden" name="product" value="none">
          <button type="submit">Save</button>
          <input type="button" value="Do nothing">
        </form>
        HTML);

    return true;
}

if ('/done' === $path) {
    $page('Saved', '<div class="flash-success">Saved</div><dl>'.implode('', array_map(static fn (string $key, string $value): string => '<dt>'.$key.'</dt><dd class="'.$key.'">'.$value.'</dd>', array_keys($_GET), array_map(static fn (mixed $value): string => $escape(is_string($value) ? $value : ''), $_GET))).'</dl>');

    return true;
}

if ('/select2' === $path) {
    $page('Select2', <<<'HTML'
        <form method="get" action="/done">
          <label for="product">Product</label>
          <select id="product" name="order[product]" class="js-select2">
            <option value=""></option><option value="ws">Widget S</option><option value="wxl">Widget XL</option><option value="g">Gadget</option>
          </select>
          <label for="customer">Customer</label>
          <select id="customer" name="order[customer]" class="js-remote"></select>
        </form>
        <script src="/vendor/jquery.min.js"></script>
        <script src="/vendor/select2.min.js"></script>
        <script>
          $('.js-select2').select2({placeholder: 'Choose a product', width: '300px'});
          $('.js-remote').select2({width: '300px', minimumInputLength: 2, ajax: {url: '/customers', dataType: 'json', delay: 150}});
        </script>
        HTML, '<link rel="stylesheet" href="/vendor/select2.min.css">');

    return true;
}

// What the remote Select2 searches, a little slowly: the results must be waited for.
if ('/customers' === $path) {
    usleep(300_000);
    $term = strtolower($query('term'));
    $customers = ['acme' => 'ACME Corporation', 'globex' => 'Globex', 'initech' => 'Initech'];
    header('Content-Type: application/json');
    echo json_encode(['results' => array_values(array_map(static fn (string $id, string $text): array => ['id' => $id, 'text' => $text], array_keys(array_filter($customers, static fn (string $text): bool => str_contains(strtolower($text), $term))), array_filter($customers, static fn (string $text): bool => str_contains(strtolower($text), $term))))]);

    return true;
}

// A page that changes on every request, where it says when it was served: what a mask is for.
if ('/clock' === $path) {
    $page('Clock', '<h1>Report</h1><p class="timestamp" style="font: 32px monospace">Generated at '.hrtime(true).'</p><table><tr><td>Total</td><td>42</td></tr></table>');

    return true;
}

// Logging in, by a form or by HTTP basic: the page then names the user — what a capture must never repeat.
if ('/login' === $path && 'POST' === $_SERVER['REQUEST_METHOD']) {
    if ('tester' === ($_POST['_username'] ?? null) && 's3cret-pass' === ($_POST['_password'] ?? null)) {
        setcookie('session', 'tester', ['path' => '/']);
        header('Location: /private', true, 303);

        return true;
    }

    $page('Login', '<p class="error">Invalid credentials.</p><form method="post"><input name="_username"><input type="password" name="_password"><button>Sign in</button></form>');

    return true;
}

if ('/login' === $path) {
    $page('Login', '<form method="post"><input name="_username"><input type="password" name="_password"><button>Sign in</button></form>');

    return true;
}

if ('/private' === $path) {
    $user = 'tester' === ($_COOKIE['session'] ?? null) || ('tester' === ($_SERVER['PHP_AUTH_USER'] ?? null) && 's3cret-pass' === ($_SERVER['PHP_AUTH_PW'] ?? null)) ? 'tester' : null;

    if (null === $user) {
        http_response_code(401);
        $page('Denied', '<h1>Denied</h1>');

        return true;
    }

    $page('Private', '<h1>Welcome '.$user.'</h1><p class="secret">Your password is s3cret-pass</p>');

    return true;
}

// What an application keeps in the browser, as a table keeps its search: `?keep` stores, every visit shows.
if ('/memory' === $path) {
    $page('Memory', <<<'HTML'
        <h1>Memory</h1>
        <p class="local"></p><p class="session"></p>
        <script>
          if (new URLSearchParams(location.search).has('keep')) { localStorage.setItem('kept', 'local'); sessionStorage.setItem('kept', 'session'); }
          document.querySelector('.local').textContent = localStorage.getItem('kept') || 'no local';
          document.querySelector('.session').textContent = sessionStorage.getItem('kept') || 'no session';
        </script>
        HTML);

    return true;
}

http_response_code(404);
$page('Not found', '<h1>Not found</h1>');

return true;
