<?php
ini_set('error_log', 'error_log');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../jdf.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../keyboard.php';
require __DIR__ . '/../vendor/autoload.php';

$ManagePanel = new ManagePanel();
$setting = select("setting", "*");
$paymentreports = select("topicid", "idreport", "report", "paymentreport", "select")['idreport'];

$datatextbotget = select("textbot", "*", null, null, "fetchAll");
$datatxtbot = array();
foreach ($datatextbotget as $row) {
    $datatxtbot[] = array(
        'id_text' => $row['id_text'],
        'text' => $row['text']
    );
}
$datatextbot = array(
    'textafterpay' => '',
    'textaftertext' => '',
    'textmanual' => '',
    'textselectlocation' => '',
);
foreach ($datatxtbot as $item) {
    if (isset($datatextbot[$item['id_text']])) {
        $datatextbot[$item['id_text']] = $item['text'];
    }
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data) && !empty($_POST)) {
    $data = $_POST;
}

http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');

if (!is_array($data)) {
    echo 'OK';
    exit;
}

$cbStatus = isset($data['status']) ? $data['status'] : null;
if ($cbStatus !== 100 && $cbStatus !== '100') {
    echo 'OK';
    exit;
}

$hashId = isset($data['hash_id']) ? trim((string) $data['hash_id']) : '';
$authority = isset($data['authority']) ? trim((string) $data['authority']) : '';

$Payment_report = null;
if ($hashId !== '') {
    $candidate = select("Payment_report", "*", "id_order", $hashId, "select");
    if (is_array($candidate) && isset($candidate['Payment_Method']) && $candidate['Payment_Method'] === 'tetrapay') {
        $Payment_report = $candidate;
    }
}
if (!$Payment_report && $authority !== '') {
    $candidate = select("Payment_report", "*", "dec_not_confirmed", $authority, "select");
    if (is_array($candidate) && isset($candidate['Payment_Method']) && $candidate['Payment_Method'] === 'tetrapay') {
        $Payment_report = $candidate;
    }
}

if (!$Payment_report || !isset($Payment_report['id_order'])) {
    echo 'OK';
    exit;
}

if ($hashId !== '' && (string) $Payment_report['id_order'] !== $hashId) {
    echo 'OK';
    exit;
}

if ($authority !== '' && isset($Payment_report['dec_not_confirmed']) && (string) $Payment_report['dec_not_confirmed'] !== $authority) {
    echo 'OK';
    exit;
}

$verifyResult = verifyTetrapay($authority !== '' ? $authority : (string) $Payment_report['dec_not_confirmed']);
if (!tetrapayVerifySuccess($verifyResult)) {
    error_log('tetrapay verify failed: ' . json_encode($verifyResult));
    echo 'OK';
    exit;
}

if ($Payment_report['payment_Status'] === 'paid') {
    echo 'OK';
    exit;
}

$textbotlang = languagechange('../text.json');
$id_order = $Payment_report['id_order'];
DirectPayment($id_order, "../images.jpg");

$pricecashback = select("PaySetting", "ValuePay", "NamePay", "chashbacktetrapay", "select")['ValuePay'];
$Balance_id = select("user", "*", "id", $Payment_report['id_user'], "select");
if ($pricecashback != "0") {
    $result = ($Payment_report['price'] * $pricecashback) / 100;
    $Balance_confrim = intval($Balance_id['Balance']) + $result;
    update("user", "Balance", $Balance_confrim, "id", $Balance_id['id']);
    $text_report = "🎁 کاربر عزیز مبلغ $result تومان به عنوان هدیه واریز به حساب شما واریز گردید.";
    sendmessage($Balance_id['id'], $text_report, null, 'HTML');
}

$trackingNote = '';
if (is_array($verifyResult) && isset($verifyResult['tracking_id'])) {
    $trackingNote = (string) $verifyResult['tracking_id'];
}

$text_reportpayment = "💵 پرداخت جدید (تتراپی)
- 👤 نام کاربری کاربر : @{$Balance_id['username']}
- 🆔 آیدی عددی کاربر : {$Balance_id['id']}
- 💸 مبلغ تراکنش {$Payment_report['price']}
- 🔗 مرجع : {$trackingNote}
- 💳 روش پرداخت : tetrapay";
if (strlen($setting['Channel_Report']) > 0) {
    telegram('sendmessage', [
        'chat_id' => $setting['Channel_Report'],
        'message_thread_id' => $paymentreports,
        'text' => $text_reportpayment,
        'parse_mode' => "HTML",
    ]);
}

update("Payment_report", "payment_Status", "paid", "id_order", $Payment_report['id_order']);

echo 'OK';
