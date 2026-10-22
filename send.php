<?php
// Обработчик форм omni-market.ru: отправляет заявки и обращения письмом в нужный отдел.
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
date_default_timezone_set('Europe/Moscow');

const MAIL_FROM   = 'hello@omni-market.ru';
const RECIPIENTS  = [
    'supplier' => 'hello@omni-market.ru',
    'claim'    => 'pretension@omni-market.ru',
];
const MAX_FILES       = 5;
const MAX_TOTAL_BYTES = 10 * 1024 * 1024; // 10 МБ на все вложения

function reply(int $code, bool $ok, string $message): void {
    http_response_code($code);
    echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// Хостинг reg.ru отклоняет multipart/form-data, поэтому форма присылает JSON (файлы в base64).
$raw  = file_get_contents('php://input');
$DATA = [];
if (is_string($raw) && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $DATA = $decoded;
    }
}
if (!$DATA && !empty($_POST)) {
    $DATA = $_POST;
}

function field(string $name, int $max = 2000): string {
    global $DATA;
    $v = isset($DATA[$name]) && is_scalar($DATA[$name]) ? (string) $DATA[$name] : '';
    $v = str_replace("\0", '', $v);
    $v = trim(preg_replace('/\r\n?/', "\n", $v));
    return mb_substr($v, 0, $max);
}

function oneLine(string $v): string {
    return trim(preg_replace('/[\r\n\t]+/', ' ', $v));
}

function encHeader(string $v): string {
    return '=?UTF-8?B?' . base64_encode(oneLine($v)) . '?=';
}

function checkPhone(string $phone): void {
    $d = preg_replace('/\D/', '', $phone);
    if (strlen($d) <= 1) reply(422, false, 'Укажите телефон.');
    if (strlen($d) !== 11) reply(422, false, 'Проверьте телефон.');
}

function checkEmail(string $email): void {
    if ($email === '') reply(422, false, 'Укажите почту.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) reply(422, false, 'Проверьте почту.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply(405, false, 'Форма принимает только отправку.');
}

$type = field('form_type', 20);
if (!isset(RECIPIENTS[$type])) {
    reply(400, false, 'Неизвестная форма.');
}

// Защита от спам-ботов: скрытое поле должно быть пустым, форма заполнялась дольше 3 секунд.
if (field('website', 200) !== '') {
    reply(200, true, 'Спасибо! Сообщение отправлено.');
}
$started = (int) field('started', 20);
if ($started > 0 && (time() - intdiv($started, 1000)) < 3) {
    reply(429, false, 'Слишком быстро. Подождите пару секунд и отправьте ещё раз.');
}

if (field('consent', 10) !== 'yes') {
    reply(422, false, 'Отметьте согласие на обработку персональных данных.');
}

$replyTo = '';
$lines   = [];

if ($type === 'supplier') {
    $company = oneLine(field('company', 200));
    $name    = oneLine(field('name', 200));
    $phone   = oneLine(field('phone', 50));
    $email   = oneLine(field('email', 200));
    if ($company === '') reply(422, false, 'Укажите компанию.');
    if ($name === '')    reply(422, false, 'Укажите контактное лицо.');
    checkPhone($phone);
    checkEmail($email);
    $inn = preg_replace('/\D/', '', field('inn', 20));
    if ($inn !== '' && !preg_match('/^(\d{10}|\d{12})$/', $inn)) {
        reply(422, false, 'Проверьте ИНН.');
    }
    $replyTo = $email;
    $subject = 'Заявка поставщика: ' . $company;
    $lines = [
        'Компания'         => $company,
        'ИНН'              => oneLine(field('inn', 20)),
        'Контактное лицо'  => $name,
        'Телефон'          => $phone,
        'Почта'            => $email,
        'Категория'        => oneLine(field('category', 100)),
        'Бренды и прайс'   => field('brands', 5000),
    ];
} else {
    $order = oneLine(field('order', 100));
    $name  = oneLine(field('name', 200));
    $phone = oneLine(field('phone', 50));
    $email = oneLine(field('email', 200));
    $text  = field('text', 5000);
    if (oneLine(field('marketplace', 100)) === '') reply(422, false, 'Укажите, где покупали.');
    if ($order === '') reply(422, false, 'Укажите номер заказа.');
    if (oneLine(field('type', 100)) === '') reply(422, false, 'Укажите тип обращения.');
    if ($name === '') reply(422, false, 'Укажите имя.');
    checkPhone($phone);
    checkEmail($email);
    $replyTo = $email;
    $subject = 'Обращение по заказу' . ($order !== '' ? ' ' . $order : '') . ': ' . oneLine(field('type', 100));
    $lines = [
        'Маркетплейс'       => oneLine(field('marketplace', 100)),
        'Номер заказа'      => $order,
        'Товар'             => oneLine(field('item', 300)),
        'Тип обращения'     => oneLine(field('type', 100)),
        'Что случилось'     => $text,
        'Имя'               => $name,
        'Телефон'           => $phone,
        'Почта'             => $email,
    ];
}

// Вложения (только для обращений покупателей): [{name, data(base64)}].
$attachments = [];
if ($type === 'claim' && !empty($DATA['files']) && is_array($DATA['files'])) {
    if (count($DATA['files']) > MAX_FILES) {
        reply(413, false, 'Можно приложить не больше ' . MAX_FILES . ' файлов.');
    }
    $total = 0;
    foreach ($DATA['files'] as $f) {
        if (!is_array($f) || !isset($f['data']) || !is_string($f['data'])) continue;
        $bin = base64_decode($f['data'], true);
        if ($bin === false || $bin === '') {
            reply(400, false, 'Не удалось прочитать файл. Попробуйте приложить его ещё раз.');
        }
        $total += strlen($bin);
        if ($total > MAX_TOTAL_BYTES) {
            reply(413, false, 'Файлы слишком большие. Можно приложить до 10 МБ.');
        }
        $mime = 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $mime = (string) finfo_buffer($fi, $bin);
            finfo_close($fi);
        }
        if (!preg_match('#^(image|video)/#', $mime)) {
            reply(415, false, 'Можно прикладывать только фото и видео.');
        }
        $name  = isset($f['name']) && is_string($f['name']) ? $f['name'] : 'file';
        $fname = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', basename($name));
        $attachments[] = ['name' => $fname ?: 'file', 'mime' => $mime, 'data' => $bin];
    }
}

// Текст письма.
$body = '';
foreach ($lines as $label => $value) {
    if ($value === '') continue;
    $body .= (strpos($value, "\n") !== false) ? "$label:\n$value\n\n" : "$label: $value\n";
}
$body .= "\n—\nОтправлено с сайта omni-market.ru " . date('d.m.Y H:i') . ' МСК';
if ($attachments) {
    $body .= "\nВложений: " . count($attachments);
}

$to = RECIPIENTS[$type];
$headers  = 'From: ' . encHeader('Сайт Omni Market') . ' <' . MAIL_FROM . ">\r\n";
if ($replyTo !== '') {
    $headers .= 'Reply-To: ' . $replyTo . "\r\n";
}
$headers .= "MIME-Version: 1.0\r\n";

if ($attachments) {
    $boundary = 'om_' . bin2hex(random_bytes(12));
    $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"";
    $message  = "--$boundary\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
    $message .= chunk_split(base64_encode($body)) . "\r\n";
    foreach ($attachments as $a) {
        $message .= "--$boundary\r\n";
        $message .= 'Content-Type: ' . $a['mime'] . '; name="' . encHeader($a['name']) . "\"\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n";
        $message .= 'Content-Disposition: attachment; filename="' . encHeader($a['name']) . "\"\r\n\r\n";
        $message .= chunk_split(base64_encode($a['data'])) . "\r\n";
    }
    $message .= "--$boundary--";
} else {
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64";
    $message  = chunk_split(base64_encode($body));
}

$sent = mail($to, encHeader($subject), $message, $headers, '-f' . MAIL_FROM);
if (!$sent) {
    reply(500, false, 'Не удалось отправить. Напишите нам на ' . $to . '.');
}
reply(200, true, 'Спасибо! Сообщение отправлено, мы ответим в ближайшее время.');
