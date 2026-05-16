<?php
ini_set('error_log', 'error_log');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../keyboard.php';
require_once __DIR__ . '/../jdf.php';
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
    'textselectlocation' => ''
);
foreach ($datatxtbot as $item) {
    if (isset($datatextbot[$item['id_text']])) {
        $datatextbot[$item['id_text']] = $item['text'];
    }
}
$textbotlang = languagechange('../text.json');

$data = json_decode(file_get_contents("php://input"), true);

http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');

if (!is_array($data)) {
    echo 'OK';
    exit;
}

$paymentStatus = isset($data['payment_status']) ? strtolower(trim((string) $data['payment_status'])) : '';
if ($paymentStatus !== 'finished') {
    echo 'OK';
    exit;
}

$paymentId = isset($data['payment_id']) ? trim((string) $data['payment_id']) : '';
$orderIdFromIpn = isset($data['order_id']) ? trim((string) $data['order_id']) : '';
$invoiceIdFromIpn = isset($data['invoice_id']) ? $data['invoice_id'] : null;

$Payment_report = null;

if ($orderIdFromIpn !== '') {
    $candidate = select("Payment_report", "*", "id_order", $orderIdFromIpn, "select");
    if (is_array($candidate) && isset($candidate['Payment_Method']) && $candidate['Payment_Method'] === 'nowpayment') {
        $Payment_report = $candidate;
    }
}

if (!$Payment_report && $invoiceIdFromIpn !== null && $invoiceIdFromIpn !== '') {
    $candidate = select("Payment_report", "*", "dec_not_confirmed", (string) $invoiceIdFromIpn, "select");
    if (is_array($candidate) && isset($candidate['Payment_Method']) && $candidate['Payment_Method'] === 'nowpayment') {
        $Payment_report = $candidate;
    }
}

$pay = [];
if ($paymentId !== '') {
    $pay = StatusPayment($paymentId);
    if (!is_array($pay)) {
        $pay = [];
    }
}

if (!$Payment_report && !empty($pay['invoice_id'])) {
    $candidate = select("Payment_report", "*", "dec_not_confirmed", (string) $pay['invoice_id'], "select");
    if (is_array($candidate) && isset($candidate['Payment_Method']) && $candidate['Payment_Method'] === 'nowpayment') {
        $Payment_report = $candidate;
    }
}

if (!$Payment_report || !isset($Payment_report['id_order'])) {
    echo 'OK';
    exit;
}

if ($orderIdFromIpn !== '' && (string) $Payment_report['id_order'] !== $orderIdFromIpn) {
    echo 'OK';
    exit;
}

if (!empty($pay['order_id']) && (string) $pay['order_id'] !== (string) $Payment_report['id_order']) {
    echo 'OK';
    exit;
}

if ($Payment_report['payment_Status'] == "paid") {
    echo 'OK';
    exit;
}

DirectPayment($Payment_report['id_order'], "../images.jpg");
$pricecashback = select("PaySetting", "ValuePay", "NamePay", "cashbacknowpayment", "select")['ValuePay'];
$Balance_id = select("user", "*", "id", $Payment_report['id_user'], "select");
if ($pricecashback != "0") {
    $result = ($Payment_report['price'] * $pricecashback) / 100;
    $Balance_confrim = intval($Balance_id['Balance']) + $result;
    update("user", "Balance", $Balance_confrim, "id", $Balance_id['id']);
    $pricecashback = number_format($pricecashback);
    $text_report = "🎁 کاربر عزیز مبلغ $result تومان به عنوان هدیه واریز به حساب شما واریز گردید.";
    sendmessage($Balance_id['id'], $text_report, null, 'HTML');
}

$actuallyPaid = '';
if (isset($pay['actually_paid']) && $pay['actually_paid'] !== '') {
    $actuallyPaid = $pay['actually_paid'];
} elseif (isset($pay['actually_paid_amount']) && $pay['actually_paid_amount'] !== '') {
    $actuallyPaid = $pay['actually_paid_amount'];
} elseif (isset($data['actually_paid'])) {
    $actuallyPaid = $data['actually_paid'];
}

$text_reportpayment = "💵 پرداخت جدید
- 👤 نام کاربری کاربر : @{$Balance_id['username']}
- ‏🆔آیدی عددی کاربر : {$Balance_id['id']}
- 💸 مبلغ تراکنش {$Payment_report['price']}
- 📥 مبلغ واریز شده ترون. : {$actuallyPaid}
- 💳 روش پرداخت :  nowpayment";
if (strlen($setting['Channel_Report']) > 0) {
    telegram('sendmessage', [
        'chat_id' => $setting['Channel_Report'],
        'message_thread_id' => $paymentreports,
        'text' => $text_reportpayment,
        'parse_mode' => "HTML"
    ]);
}
update("Payment_report", "payment_Status", "paid", "id_order", $Payment_report['id_order']);

echo 'OK';
