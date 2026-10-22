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

function field(string $name, int $max = 2000): string {
    $v = isset($_POST[$name]) && is_string($_POST[$name]) ? $_POST[$name] : '';
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
    if ($company === '' || $name === '' || ($phone === '' && $email === '')) {
        reply(422, false, 'Укажите компанию, контактное лицо и телефон или почту.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        reply(422, false, 'Проверьте адрес почты.');
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
    $contact = oneLine(field('contact', 200));
    $text    = field('text', 5000);
    if ($contact === '' || $text === '') {
        reply(422, false, 'Опишите, что случилось, и оставьте телефон или почту для ответа.');
    }
    if (filter_var($contact, FILTER_VALIDATE_EMAIL)) {
        $replyTo = $contact;
    }
    $order   = oneLine(field('order', 100));
    $subject = 'Обращение по заказу' . ($order !== '' ? ' ' . $order : '') . ': ' . oneLine(field('type', 100));
    $lines = [
        'Маркетплейс'       => oneLine(field('marketplace', 100)),
        'Номер заказа'      => $order,
        'Товар'             => oneLine(field('item', 300)),
        'Тип обращения'     => oneLine(field('type', 100)),
        'Что случилось'     => $text,
        'Имя'               => oneLine(field('name', 200)),
        'Телефон или почта' => $contact,
    ];
}

// Вложения (только для обращений покупателей).
$attachments = [];
if ($type === 'claim' && !empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
    $total = 0;
    $count = count($_FILES['files']['name']);
    for ($i = 0; $i < $count; $i++) {
        $err = $_FILES['files']['error'][$i];
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            reply(413, false, 'Файлы слишком большие. Можно приложить до 10 МБ.');
        }
        if ($err !== UPLOAD_ERR_OK) {
            reply(400, false, 'Не удалось загрузить файл. Попробуйте ещё раз.');
        }
        if (count($attachments) >= MAX_FILES) {
            reply(413, false, 'Можно приложить не больше ' . MAX_FILES . ' файлов.');
        }
        $tmp  = $_FILES['files']['tmp_name'][$i];
        $size = (int) $_FILES['files']['size'][$i];
        $total += $size;
        if ($total > MAX_TOTAL_BYTES) {
            reply(413, false, 'Файлы слишком большие. Можно приложить до 10 МБ.');
        }
        $mime = function_exists('mime_content_type') ? (string) mime_content_type($tmp) : 'application/octet-stream';
        if (!preg_match('#^(image|video)/#', $mime)) {
            reply(415, false, 'Можно прикладывать только фото и видео.');
        }
        $fname = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', basename((string) $_FILES['files']['name'][$i]));
        $attachments[] = ['name' => $fname ?: 'file', 'mime' => $mime, 'data' => file_get_contents($tmp)];
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
