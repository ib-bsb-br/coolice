<?php

declare(strict_types=1);

// Generates an ICS VTODO feed for a board by fetching central API
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="calendar.ics"');
header('Cache-Control: public, max-age=60');

const CENTRAL_API = 'https://arcreformas.com.br/api';

function safeSlug(string $b): string
{
    $b = preg_replace('/[^a-zA-Z0-9_-]/', '_', $b);
    return substr($b, 0, 64) ?: 'public';
}
function dt_ics(int $ts): string
{
    return gmdate('Ymd\\THis\\Z', $ts);
}

$slug = safeSlug($_GET['b'] ?? 'public');
$ch = curl_init(CENTRAL_API . '/tasks/' . rawurlencode($slug));
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 5,
]);
$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$board = ['title' => 'Board '.$slug, 'tasks' => []];
if ($http === 200 && $resp) {
    $j = json_decode($resp, true);
    if (is_array($j)) {
        $board = $j;
    }
}
$title = $board['title'] ?? ('Board ' . $slug);
$tasks = $board['tasks'] ?? [];

echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:-//memor.ia.br//Tasks//EN\r\n";
echo "CALSCALE:GREGORIAN\r\n";
echo "X-WR-CALNAME:" . addcslashes($title, ",;") . "\r\n";

$now = time();
foreach ($tasks as $t) {
    $uid = ($t['id'] ?? '') !== '' ? $t['id'] : bin2hex(random_bytes(8));
    $summary = trim((string)($t['text'] ?? '(untitled)'));
    $created = (int)($t['ts'] ?? $now);
    $done = !empty($t['done']);
    $due = null;
    if (preg_match('/@(\\d{4}-\\d{2}-\\d{2})(?:\\s+|$)/', $summary, $m)) {
        $due = strtotime($m[1] . ' 09:00:00 UTC');
    }

    echo "BEGIN:VTODO\r\n";
    echo "UID:$uid@memor.ia.br\r\n";
    echo "SUMMARY:" . addcslashes($summary, ",;") . "\r\n";
    echo "DTSTAMP:" . dt_ics($created) . "\r\n";
    echo "CREATED:" . dt_ics($created) . "\r\n";
    if ($due) {
        echo "DUE:" . dt_ics($due) . "\r\n";
    }
    echo "STATUS:" . ($done ? "COMPLETED" : "NEEDS-ACTION") . "\r\n";
    if ($done) {
        echo "PERCENT-COMPLETE:100\r\n";
    }
    echo "END:VTODO\r\n";
}
echo "END:VCALENDAR\r\n";
