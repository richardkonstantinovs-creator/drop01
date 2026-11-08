<?php
// Stand-in for api.telegram.org in the browser tests (php -S … tg-mock.php): canned Bot API answers,
// /__push queues updates for getUpdates, /__sent lists what the store sent. State lives in a temp JSON file.
$f = sys_get_temp_dir() . '/tg-mock.json';
$st = is_file($f) ? json_decode(file_get_contents($f), true) : ['updates' => [], 'sent' => [], 'next' => 1];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
$save = function () use (&$st, $f) { file_put_contents($f, json_encode($st)); };   // by reference: fn() would save the state as it was at the start
header('Content-Type: application/json');
$ok = fn($r) => print(json_encode(['ok' => true, 'result' => $r]));
if ($path === '/__push') { foreach ($in['updates'] ?? [] as $u) $st['updates'][] = $u + ['update_id' => $st['next']++]; $save(); exit($ok(count($st['updates']))); }
if ($path === '/__sent') exit($ok($st['sent']));
if (str_starts_with($path, '/file/')) { header('Content-Type: image/png'); exit(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==')); }
if (!preg_match('#^/bot(\d+:[\w-]+)/(\w+)$#', $path, $m)) { http_response_code(404); exit(json_encode(['ok' => false, 'description' => 'Not Found'])); }
switch ($m[2]) {
    case 'getMe': $ok(['id' => 4242, 'is_bot' => true, 'first_name' => 'Drop 01', 'username' => 'drop01_test_bot']); break;
    case 'getUpdates':
        $off = (int) ($in['offset'] ?? 0);
        $st['updates'] = array_values(array_filter($st['updates'], fn($u) => $u['update_id'] >= $off)); $save();
        $ok($st['updates']); break;
    case 'sendMessage': $st['sent'][] = $in; $save(); $ok(['message_id' => count($st['sent']), 'chat' => ['id' => $in['chat_id']], 'text' => $in['text']]); break;
    case 'getFile': $ok(['file_id' => $in['file_id'], 'file_path' => 'photos/p.png']); break;
    case 'getWebhookInfo': $ok(['url' => '', 'pending_update_count' => 0]); break;
    default: $ok(true);
}
