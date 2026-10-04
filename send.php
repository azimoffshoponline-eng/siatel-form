<?php
// Обработчик формы заявки Siatel: Telegram + почта.
// Настройки — в config.php рядом (не публикуется на GitHub).
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
foreach (ALLOWED_ORIGINS as $re) {
  if ($origin && preg_match($re, $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Accept, Content-Type');
    break;
  }
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }

function f($k, $max) { return mb_substr(trim(strip_tags((string)($_POST[$k] ?? ''))), 0, $max); }
$name = f('name', 80); $phone = f('phone', 25); $service = f('service', 80);
$message = f('message', 1500); $page = f('page', 300);
$elapsed = (int)($_POST['elapsed'] ?? 0);

// Защита от ботов: скрытое поле и слишком быстрая отправка — молча «принимаем».
if (!empty($_POST['website']) || ($elapsed > 0 && $elapsed < 2500)) { echo '{"ok":true}'; exit; }
if ($name === '' || strlen(preg_replace('/\D/', '', $phone)) < 10 || empty($_POST['consent'])) {
  http_response_code(400); echo '{"ok":false,"error":"invalid"}'; exit;
}

// Не больше 3 заявок с одного IP за 10 минут.
$ip = $_SERVER['REMOTE_ADDR'] ?? '0';
$rl = sys_get_temp_dir() . '/siatel_rl_' . md5($ip);
$hits = array_filter(array_map('intval', @file($rl) ?: []), function ($t) { return $t > time() - 600; });
if (count($hits) >= 3) { http_response_code(429); echo '{"ok":false}'; exit; }
$hits[] = time(); @file_put_contents($rl, implode("\n", $hits));

$time = (new DateTime('now', new DateTimeZone('Europe/Moscow')))->format('d.m.Y H:i');
$lines = ["Новая заявка с сайта Siatel", "", "Имя: $name", "Телефон: $phone", "Интересует: $service"];
if ($message !== '') { $lines[] = "Задача: $message"; }
$lines[] = ""; $lines[] = "Время: $time (МСК)"; if ($page) { $lines[] = "Страница: $page"; }
$text = implode("\n", $lines);

$okTg = false;
if (TG_TOKEN && TG_CHAT_ID) {
  $ch = curl_init('https://api.telegram.org/bot' . TG_TOKEN . '/sendMessage');
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
    CURLOPT_POSTFIELDS => ['chat_id' => TG_CHAT_ID, 'text' => $text, 'disable_web_page_preview' => 'true']]);
  $r = json_decode((string)curl_exec($ch), true); curl_close($ch);
  $okTg = !empty($r['ok']);
}

$okMail = false;
if (MAIL_TO) {
  $subj = '=?UTF-8?B?' . base64_encode("Заявка с сайта Siatel: $name, $phone") . '?=';
  $headers = "From: Siatel <" . MAIL_FROM . ">\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
  $okMail = @mail(MAIL_TO, $subj, $text, $headers, '-f' . MAIL_FROM);
}

// Резервный журнал заявок (на случай сбоя доставки).
@file_put_contents(__DIR__ . '/leads.log', "$time | $name | $phone | $service | tg=" . (int)$okTg . " mail=" . (int)$okMail . "\n", FILE_APPEND);

if ($okTg || $okMail) { echo '{"ok":true}'; } else { http_response_code(502); echo '{"ok":false}'; }
