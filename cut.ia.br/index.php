<?php
declare(strict_types=1);

// Load shared configuration and common functions
require_once __DIR__ . '/../arcreformas.com.br/api/config.php';
require_once __DIR__ . '/../src/common.php';


// Permissive CORS (friction-first)
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: *');
header('Access-Control-Allow-Methods: GET,POST,OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') exit;
header('Cache-Control: no-store');

$domain = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'cut.ia.br');
$dataDir = realpath(__DIR__ . '/..') ? (realpath(__DIR__ . '/..') . '/data') : (__DIR__ . '/../data');
@mkdir($dataDir, 0777, true);

$eventsFile  = $dataDir . '/events.ndjson';
$ghTokenFile = $dataDir . '/github_token.txt'; // put PAT with repo contents:write scope

function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function event_emit(string $type, $payload): array {
  global $eventsFile; $ev = ['id'=>bin2hex(random_bytes(8)),'ts'=>time(),'type'=>$type,'payload'=>$payload];
  if ($fh=fopen($eventsFile,'a')) { fwrite($fh, json_encode($ev)."\n"); fclose($fh); @chmod($eventsFile, 0666); }
  return $ev;
}
function event_tail(int $n): array {
  global $eventsFile; if (!is_file($eventsFile)) return []; $lines=@file($eventsFile, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [];
  $slice = array_slice($lines, -$n); $out=[]; foreach ($slice as $l){ $j=json_decode($l,true); if ($j) $out[]=$j; } return $out;
}

// Get PDO instance from common library
try {
    $pdo = get_pdo();
} catch (PDOException $e) {
    // get_pdo() already handles emitting a JSON error and exiting.
    // We just need to catch the exception to prevent script from halting here.
    return;
}

$op   = $_GET['op'] ?? '';
$slug = $_GET['s']  ?? '';

// Redirect short link: GET /?s=slug
if ($slug !== '' && $op === '') {
  $st = $pdo->prepare('SELECT url FROM links WHERE slug=?'); $st->execute([$slug]);
  if ($row = $st->fetch()) { $pdo->prepare('UPDATE links SET views=views+1 WHERE slug=?')->execute([$slug]); header('Location: ' . $row['url'], true, 302); exit; }
  http_response_code(404); echo '<!doctype html><meta charset="utf-8"><title>Not found</title><p>Short link not found. <a href="'.esc($domain).'">Create one</a>.</p>'; exit;
}

// Webhook: POST /?op=new-item
if ($op === 'new-item' && $_SERVER['REQUEST_METHOD']==='POST') {
  $in = json_decode((string)file_get_contents('php://input'), true) ?? [];
  $url = $in['url'] ?? '';
  $filename = $in['filename'] ?? 'item';
  $type = $in['type'] ?? 'file';
  if ($url === '') emit_json(['error'=>'Missing url'], 400);
  $task = "Process new {$type}: [{$filename}]({$url})";

  // Use the arcreformas.com.br tasks API to add the task
  $taskPayload = json_encode(['op' => 'add', 'text' => $task]);
  $ch = curl_init(API_INTERNAL_URL . '/tasks/inbox');
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $taskPayload);
  curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 2);
  curl_exec($ch);
  curl_close($ch);

  event_emit('task_added', ['board'=>'inbox','text'=>$task]);
  emit_json(['status'=>'accepted']);
}

// Publisher: POST /?op=publish_md
if ($op === 'publish_md' && $_SERVER['REQUEST_METHOD']==='POST') {
  $in = json_decode((string)file_get_contents('php://input'), true) ?? [];
  $repo  = $in['repo']  ?? GITHUB_REPO;
  $title = trim((string)($in['title'] ?? 'Note'));
  $body  = (string)($in['body'] ?? '');
  $tags  = trim((string)($in['tags'] ?? ''));
  $layout= trim((string)($in['layout'] ?? 'post'));

  $date = new DateTime('now', new DateTimeZone('UTC'));
  $ymd  = $date->format('Y-m-d'); $ts = $date->format('c');
  $slugify=function(string $s):string{$s=strtolower($s);$s=preg_replace('/[^a-z0-9]+/','-',$s);$s=trim($s,'-');return $s?:('note-'.date('His'));};
  $fname = "_posts/{$ymd}-".$slugify($title).".md";

  $front = "---\nlayout: $layout\ntitle: \"" . str_replace('"','\"',$title) . "\"\ndate: $ts\n";
  if ($tags !== '') {
    $arr = array_values(array_filter(array_map('trim', explode(',', $tags))));
    $front .= "tags: [" . implode(', ', array_map(fn($x)=>'"'.str_replace('"','\"',$x).'"',$arr)) . "]\n";
  }
  $front .= "---\n\n";
  $content = $front . $body . "\n";

  $token = GITHUB_TOKEN;
  if ($token === 'your_github_personal_access_token_here') {
      // Allow reading from file as a fallback if not set in env
      if (is_file($ghTokenFile)) $token = trim((string)file_get_contents($ghTokenFile));
  }
  if ($token === '' || $token === 'your_github_personal_access_token_here') emit_json(['status'=>'no_token','message'=>'GitHub token is not configured.'], 500);

  $url = "https://api.github.com/repos/$repo/contents/" . rawurlencode($fname);
  $payload = json_encode(['message'=>"Publish: $title",'content'=>base64_encode($content),'branch'=>'main']);

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => 'PUT',
    CURLOPT_POSTFIELDS    => $payload,
    CURLOPT_RETURNTRANSFER=> true,
    CURLOPT_HTTPHEADER    => ['Accept: application/vnd.github+json','Authorization: Bearer '.$token,'User-Agent: cut.ia.br-publisher'],
  ]);
  $resp = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($http >= 200 && $http < 300) {
    $j = json_decode($resp, true);
    $html_url = $j['content']['html_url'] ?? null;
    event_emit('published_note', ['repo'=>$repo,'path'=>$fname,'html_url'=>$html_url,'title'=>$title]);
    emit_json(['status'=>'ok','path'=>$fname,'html_url'=>$html_url ?: null], 201);
  } else {
    emit_json(['status'=>'github_error','code'=>$http,'response'=>json_decode($resp,true)], 502);
  }
}

// Bus: POST /?op=bus_emit   GET /?op=bus_tail&n=100
if ($op === 'bus_emit' && $_SERVER['REQUEST_METHOD']==='POST') {
  $in=json_decode((string)file_get_contents('php://input'),true) ?? [];
  if (!isset($in['type'])) emit_json(['status'=>'bad_json'],400);
  $ev = event_emit((string)$in['type'], $in['payload'] ?? null);
  emit_json(['status'=>'ok','event'=>$ev]);
}
if ($op === 'bus_tail') {
  $n = max(1, min(1000, (int)($_GET['n'] ?? 100)));
  emit_json(['status'=>'ok','events'=>event_tail($n)]);
}

// Quick add: GET /?op=task_add&text=...&b=slug
if ($op === 'task_add') {
  $text = (string)($_GET['text'] ?? ($_POST['text'] ?? ''));
  $board= (string)($_GET['b'] ?? ($_POST['b'] ?? 'public'));
  $memor_url = 'https://memor.ia.br/?b=' . urlencode($board) . '&add=' . urlencode($text);

  // Use the arcreformas tasks API to add the task
  $taskPayload = json_encode(['op' => 'add', 'text' => $text]);
  $ch = curl_init(API_INTERNAL_URL . '/tasks/' . rawurlencode($board));
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $taskPayload);
  curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 2);
  curl_exec($ch);
  curl_close($ch);

  event_emit('task_added', ['board'=>$board,'text'=>$text]);
  header('Location: ' . $memor_url, true, 303); exit;
}

// Dashboard: GET /?op=dash
if ($op === 'dash') {
  $icsUrl    = 'https://memor.ia.br/ics.php?b=public';
  $driveList = API_INTERNAL_URL . '/files';
  $site      = 'https://ib-bsb-br.github.io/';

  $icsOk = @file_get_contents($icsUrl) !== false;
  $driveOk = false; $driveCount = 0;
  $r = @file_get_contents($driveList);
  if ($r !== false) { $j = json_decode($r,true); if ($j && ($j['status'] ?? '')==='success'){ $driveOk=true; $driveCount=count($j['data']??[]);} }

  echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>cut.ia.br — Dashboard</title>
  <style>body{font:16px/1.5 system-ui,sans-serif;margin:2rem;max-width:760px}.ok{color:#0a7f2e}.bad{color:#a00}a{color:#06f}</style>
  <h1>Status</h1>
  <ul>
    <li>memor ICS: <strong class="'.($icsOk?'ok':'bad').'">'.($icsOk?'OK':'DOWN').'</strong> — <a href="'.esc($icsUrl).'">open</a></li>
    <li>drive list: <strong class="'.($driveOk?'ok':'bad').'">'.($driveOk?'OK':'DOWN').'</strong> ('.(int)$driveCount.' files) — <a href="'.esc($driveList).'">open</a></li>
    <li>site: <a href="'.esc($site).'">open</a></li>
    <li>events: <a href="?op=bus_tail&n=50">tail</a></li>
  </ul>
  <p><a href="'.esc($domain).'">← Home</a></p>'; exit;
}

// Homepage + shortener form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($op)) {
  $url = trim((string)($_POST['url'] ?? ''));
  if (!filter_var($url, FILTER_VALIDATE_URL)) { http_response_code(400); echo 'Invalid URL.'; exit; }
  $slug = id(5); // Use the common ID generator
  $pdo->prepare('INSERT INTO links(slug,url,created_at) VALUES(?,?,?)')->execute([$slug,$url,time()]);
  $short = $domain . '/?s=' . $slug;
  echo '<!doctype html><meta charset="utf-8"><title>Shortened</title><p>Short link: <a href="'.esc($short).'">'.esc($short).'</a></p><p><a href="'.esc($domain).'">Create another</a></p>'; exit;
}

// Simple homepage
echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>cut.ia.br — Capture • Publish • Shorten</title>
<style>body{font:16px/1.5 system-ui,sans-serif;margin:2rem auto;max-width:760px}input,textarea{width:100%;padding:.6rem;border:1px solid #ccc;border-radius:8px}button{margin-top:.6rem;padding:.6rem 1rem;border:0;border-radius:8px;background:#111;color:#fff;cursor:pointer}.row{margin:1.2rem 0}.nav a{margin-right:1rem}</style>
<div class="nav"><a href="https://memor.ia.br/" target="_blank">memor</a> <a href="https://arcreformas.com.br/" target="_blank">drive</a> <a href="https://ib-bsb-br.github.io/" target="_blank">site</a> <a href="?op=dash">dash</a></div>
<h1>cut.ia.br</h1>
<div class="row"><h2>Publish Markdown</h2><form id="pub"><input id="t" placeholder="Title" required><textarea id="b" rows="8" placeholder="# Heading\nBody..."></textarea><input id="tags" placeholder="tags, csv (optional)"><input id="repo" placeholder="owner/repo (default ib-bsb-br/ib-bsb-br.github.io)"><button type="button" onclick="pub()">Publish</button></form><pre id="out"></pre></div>
<div class="row"><h2>Shorten URL</h2><form method="post"><input type="url" name="url" placeholder="https://example.com" required><button type="submit">Shorten</button></form></div>
<script>
async function pub(){
  const payload={title:document.getElementById("t").value,body:document.getElementById("b").value,tags:document.getElementById("tags").value,repo:document.getElementById("repo").value||"ib-bsb-br/ib-bsb-br.github.io",layout:"post"};
  const r=await fetch("?op=publish_md",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(payload)}); const j=await r.json(); document.getElementById("out").textContent=JSON.stringify(j,null,2); if(j.html_url) window.open(j.html_url,"_blank");
}
</script>';
