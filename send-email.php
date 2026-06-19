<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$required = ['name', 'phone', 'email', 'service', 'amount', 'transactionId', 'paymentMethod'];
foreach ($required as $field) {
    if (!isset($data[$field])) {
        http_response_code(400);
        echo json_encode(['error' => "Missing required field: $field"]);
        exit;
    }
}

$methodNames = [
    'card' => 'بطاقة ائتمان / دفع سريع',
    'installments' => 'تقسيط تابي',
    'apple_pay' => 'Apple Pay',
    'google_pay' => 'Google Pay',
];
$paymentMethod = $data['paymentMethod'];
$methodName = isset($methodNames[$paymentMethod]) ? $methodNames[$paymentMethod] : $paymentMethod;

$serviceDisplay = $data['service'] === 'inquiry' ? 'استفسار أو خدمة مخصصة' : $data['service'];
$subject = "تأكيد دفع جديد - Over Seas - " . $data['transactionId'];

$inquiryHtml = '';
$inquiryText = '';
if ($data['service'] === 'inquiry' && !empty($data['inquiryMessage'])) {
    $inquiryMessage = htmlspecialchars($data['inquiryMessage']);
    $inquiryHtml = "
    <div class='inquiry-box'>
        <div class='label'>📝 تفاصيل الاستفسار / الخدمة المخصصة</div>
        <div class='message'>$inquiryMessage</div>
    </div>";
    $inquiryText = "تفاصيل الاستفسار: {$data['inquiryMessage']}\n";
}

$amount = htmlspecialchars($data['amount']);
$transactionId = htmlspecialchars($data['transactionId']);
$name = htmlspecialchars($data['name']);
$phone = htmlspecialchars($data['phone']);
$email = htmlspecialchars($data['email']);

$htmlBody = <<<HTML
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f5f5f5; padding: 20px; direction: rtl; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 16px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .header { text-align: center; border-bottom: 2px solid #4BFDB3; padding-bottom: 20px; margin-bottom: 24px; }
        .header h1 { color: #0a0a0a; font-size: 22px; margin: 0; }
        .header .sub { color: #666; font-size: 14px; margin: 4px 0 0; }
        .detail-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee; }
        .detail-row:last-child { border-bottom: none; }
        .detail-label { color: #555; font-weight: 600; }
        .detail-value { color: #0a0a0a; font-weight: 500; }
        .total-row { background: #f8f9fa; padding: 14px 0; margin: 12px -30px -30px; padding: 16px 30px; border-radius: 0 0 16px 16px; }
        .total-row .detail-value { color: #4BFDB3; font-size: 18px; }
        .status-badge { display: inline-block; background: #4ade80; color: #0a0a0a; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; }
        .inquiry-box { background: #f0f7ff; border-radius: 8px; padding: 14px; margin: 12px 0; border-right: 4px solid #4BFDB3; }
        .inquiry-box .label { font-size: 12px; color: #666; font-weight: 600; }
        .inquiry-box .message { margin: 4px 0 0; color: #0a0a0a; }
        .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #999; }
        .footer a { color: #4BFDB3; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🛫 Over Seas</h1>
            <p class="sub">تأكيد دفع جديد</p>
        </div>
        <div class="detail-row">
            <span class="detail-label">رقم العملية</span>
            <span class="detail-value">$transactionId</span>
        </div>
        <div class="detail-row">
            <span class="detail-label">العميل</span>
            <span class="detail-value">$name</span>
        </div>
        <div class="detail-row">
            <span class="detail-label">رقم الهاتف</span>
            <span class="detail-value">$phone</span>
        </div>
        <div class="detail-row">
            <span class="detail-label">البريد الإلكتروني</span>
            <span class="detail-value">$email</span>
        </div>
        <div class="detail-row">
            <span class="detail-label">الخدمة</span>
            <span class="detail-value">$serviceDisplay</span>
        </div>
        $inquiryHtml
        <div class="detail-row">
            <span class="detail-label">طريقة الدفع</span>
            <span class="detail-value">$methodName</span>
        </div>
        <div class="detail-row">
            <span class="detail-label">المبلغ المدفوع</span>
            <span class="detail-value">AED $amount</span>
        </div>
        <div class="detail-row">
            <span class="detail-label">الحالة</span>
            <span class="detail-value"><span class="status-badge">✓ مكتمل</span></span>
        </div>
        <div class="total-row">
            <div class="detail-row" style="border-bottom: none; padding: 0;">
                <span class="detail-label" style="font-size: 16px;">الإجمالي</span>
                <span class="detail-value" style="font-size: 20px; color: #4BFDB3;">AED $amount</span>
            </div>
        </div>
        <div class="footer">
            <p>تم إرسال هذا البريد تلقائياً من نظام Over Seas للدفع.</p>
            <p><a href="mailto:info@overseas.ae">info@overseas.ae</a> | <a href="https://wa.me/971564630165">واتساب</a></p>
        </div>
    </div>
</body>
</html>
HTML;

$plainText = <<<TEXT
تأكيد دفع جديد - Over Seas
─────────────────────────────
رقم العملية: $transactionId
العميل: $name
الهاتف: $phone
البريد: $email
الخدمة: $serviceDisplay
$inquiryText
طريقة الدفع: $methodName
المبلغ: AED $amount
الحالة: مكتمل
─────────────────────────────
TEXT;

$mail = new PHPMailer(true);

try {
    // Server settings
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'Overseastravel.contact@gmail.com';
    $mail->Password   = 'fzrx erun oozf odoh';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
    $mail->CharSet    = 'UTF-8';

    // Recipients
    $mail->setFrom('Overseastravel.contact@gmail.com', 'Over Seas Payments');
    $mail->addAddress('Overseastravel.contact@gmail.com');
    $mail->addReplyTo($data['email'], $data['name']);

    // Content
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $htmlBody;
    $mail->AltBody = $plainText;

    $mail->send();
    echo json_encode([
        'success' => true,
        'message' => 'Email sent successfully',
        'transaction_id' => $data['transactionId']
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to send email',
        'details' => $mail->ErrorInfo
    ]);
}
