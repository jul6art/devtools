<?php

declare(strict_types=1);

/*
 * A fake Messages API for the tests of ai:run (ADR-0035), served by `php -S`: it records every request in
 * requests.jsonl and answers as DEVTOOLS_FAKE_MODE asks — with the recorded draft of the workflow the brief
 * names (its revision put back), something no validator accepts, a refusal, or a draft cut short.
 */

$directory = (string) getenv('DEVTOOLS_FAKE_DIRECTORY');
$body = (string) file_get_contents('php://input');
$headers = [];

foreach ($_SERVER as $name => $value) {
    if (str_starts_with((string) $name, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr((string) $name, 5)))] = $value;
    }
}

file_put_contents($directory.'/requests.jsonl', json_encode(['method' => $_SERVER['REQUEST_METHOD'] ?? '', 'headers' => $headers, 'body' => json_decode($body, true)])."\n", \FILE_APPEND);

$request = json_decode($body, true);
$request = is_array($request) ? $request : [];
$messages = is_array($request['messages'] ?? null) ? $request['messages'] : [];
$brief = is_array($messages[0] ?? null) && is_string($messages[0]['content'] ?? null) ? $messages[0]['content'] : '';
preg_match('/workflow="([^"]+)"/', $brief, $workflow);
preg_match('/revision="([^"]+)"/', $brief, $revision);
$mode = (string) getenv('DEVTOOLS_FAKE_MODE');
$draft = (string) @file_get_contents(getenv('DEVTOOLS_FAKE_DRAFTS').'/page.'.($workflow[1] ?? '').'.draft.md');
$draft = (string) preg_replace('/^revision: .*$/m', 'revision: '.($revision[1] ?? ''), $draft, 1);

$answer = match ($mode) {
    'invalid' => ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => "Here is your page!\n\nIt is great."]]],
    'refusal' => ['stop_reason' => 'refusal', 'stop_details' => ['type' => 'refusal', 'category' => 'cyber', 'explanation' => 'declined'], 'content' => []],
    'max_tokens' => ['stop_reason' => 'max_tokens', 'content' => [['type' => 'text', 'text' => substr($draft, 0, 40)]]],
    default => ['stop_reason' => 'end_turn', 'content' => [['type' => 'thinking', 'thinking' => '', 'signature' => 'x'], ['type' => 'text', 'text' => "```markdown\n".$draft.'```']]],
};

header('Content-Type: application/json');
echo json_encode(['id' => 'msg_fake', 'type' => 'message', 'role' => 'assistant', 'model' => $request['model'] ?? '', ...$answer, 'usage' => ['input_tokens' => 1200, 'output_tokens' => 400, 'cache_read_input_tokens' => 800, 'cache_creation_input_tokens' => 100]]);
