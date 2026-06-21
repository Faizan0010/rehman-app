<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// ── CONFIGURATION ────────────────────────────────────────────
$SMTP_USER     = 'itsmefaizikhan0101@gmail.com';
$SMTP_PASS     = 'otxw lqiu mbpq hmmv';
$SITE_OWNER    = 'itsmefaizikhan0101@gmail.com';
// ─────────────────────────────────────────────────────────────

// Log the incoming request
$logFile = __DIR__ . '/email_debug.log';
$json = file_get_contents('php://input');
file_put_contents($logFile, date('Y-m-d H:i:s') . " - Received data: " . $json . "\n", FILE_APPEND);

$data = json_decode($json, true);

if (!$data) {
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - ERROR: Invalid JSON\n", FILE_APPEND);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$required = ['name', 'phone', 'email', 'service', 'amount', 'transactionId', 'paymentMethod'];
foreach ($required as $field) {
    if (!isset($data[$field])) {
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - ERROR: Missing field: $field\n", FILE_APPEND);
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
$userEmail = $data['email'];
$dateNow = date('Y-m-d H:i:s');

file_put_contents($logFile, date('Y-m-d H:i:s') . " - Processing payment: $transactionId for $name\n", FILE_APPEND);

// ══════════════════════════════════════════════════════════════
// COMMON STYLES (shared between both email templates)
// ══════════════════════════════════════════════════════════════
$commonStyles = <<<CSS
body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f5f5f5; padding: 20px; direction: rtl; margin: 0; }
.container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 16px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
.header { text-align: center; border-bottom: 2px solid #4BFDB3; padding-bottom: 20px; margin-bottom: 24px; }
.header h1 { color: #0a0a0a; font-size: 22px; margin: 0; }
.header .sub { color: #666; font-size: 14px; margin: 4px 0 0; }
.detail-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee; }
.detail-row:last-child { border-bottom: none; }
.detail-label { color: #555; font-weight: 600; }
.detail-value { color: #0a0a0a; font-weight: 500; }
.total-row { background: #f8f9fa; margin: 12px -30px -30px; padding: 16px 30px; border-radius: 0 0 16px 16px; }
.total-row .detail-value { color: #22c55e; font-size: 18px; }
.status-badge { display: inline-block; background: #4ade80; color: #0a0a0a; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; }
.inquiry-box { background: #f0f7ff; border-radius: 8px; padding: 14px; margin: 12px 0; border-right: 4px solid #4BFDB3; }
.inquiry-box .label { font-size: 12px; color: #666; font-weight: 600; }
.inquiry-box .message { margin: 4px 0 0; color: #0a0a0a; }
.footer { text-align: center; margin-top: 20px; font-size: 12px; color: #999; }
.footer a { color: #4BFDB3; text-decoration: none; }
.customer-info { background: #f0fdf4; border-radius: 12px; padding: 16px; margin: 16px 0; border: 1px solid #bbf7d0; }
.customer-info-title { font-size: 13px; font-weight: 700; color: #166534; margin-bottom: 10px; }
.customer-info .detail-row { border-bottom: 1px solid #dcfce7; }
.customer-info .detail-row:last-child { border-bottom: none; }
CSS;

// ══════════════════════════════════════════════════════════════
// EMAIL 1: TO SITE OWNER — Full form data + payment details
// ══════════════════════════════════════════════════════════════
$ownerSubject = "🔔 طلب دفع جديد - $name - $transactionId";

$ownerHtml = <<<HTML
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <style>$commonStyles</style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔔 طلب دفع جديد</h1>
            <p class="sub">تم استلام دفعة جديدة عبر الموقع</p>
        </div>

        <div class="customer-info">
            <div class="customer-info-title">👤 بيانات العميل</div>
            <div class="detail-row">
                <span class="detail-label">الاسم الكامل</span>
                <span class="detail-value">$name</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">رقم الهاتف</span>
                <span class="detail-value" style="direction:ltr;text-align:right;">$phone</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">البريد الإلكتروني</span>
                <span class="detail-value">$email</span>
            </div>
        </div>

        <div class="detail-row">
            <span class="detail-label">رقم العملية</span>
            <span class="detail-value" style="font-weight:700; color:#4BFDB3;">$transactionId</span>
        </div>
        <div class="detail-row">
            <span class="detail-label">الخدمة المطلوبة</span>
            <span class="detail-value">$serviceDisplay</span>
        </div>
        $inquiryHtml
        <div class="detail-row">
            <span class="detail-label">طريقة الدفع</span>
            <span class="detail-value">$methodName</span>
        </div>
        <div class="detail-row">
            <span class="detail-label">تاريخ العملية</span>
            <span class="detail-value">$dateNow</span>
        </div>
        <div class="detail-row">
            <span class="detail-label">الحالة</span>
            <span class="detail-value"><span class="status-badge">✓ مكتمل</span></span>
        </div>
        <div class="total-row">
            <div class="detail-row" style="border-bottom: none; padding: 0;">
                <span class="detail-label" style="font-size: 16px; font-weight:700;">المبلغ المدفوع</span>
                <span class="detail-value" style="font-size: 22px; color: #22c55e; font-weight:800;">AED $amount</span>
            </div>
        </div>
        <div class="footer">
            <p>هذا البريد تلقائي من نظام Over Seas للدفع — يرجى عدم الرد.</p>
        </div>
    </div>
</body>
</html>
HTML;

$ownerPlain = <<<TEXT
🔔 طلب دفع جديد - Over Seas
═══════════════════════════════

👤 بيانات العميل:
  الاسم: $name
  الهاتف: $phone
  البريد: $email

💳 تفاصيل الدفع:
  رقم العملية: $transactionId
  الخدمة: $serviceDisplay
  {$inquiryText}طريقة الدفع: $methodName
  المبلغ: AED $amount
  التاريخ: $dateNow
  الحالة: مكتمل

═══════════════════════════════
TEXT;

// ══════════════════════════════════════════════════════════════
// EMAIL 2: TO USER — Payment receipt / confirmation
// ══════════════════════════════════════════════════════════════
$userSubject = "✅ تأكيد الدفع - Over Seas - $transactionId";

$userHtml = <<<HTML
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <style>$commonStyles
    .thank-you { text-align: center; margin: 20px 0 24px; }
    .thank-you h2 { color: #0a0a0a; font-size: 20px; margin: 0 0 6px; }
    .thank-you p { color: #666; font-size: 14px; margin: 0; }
    .contact-box { background: #f8fafc; border-radius: 12px; padding: 16px; margin-top: 16px; text-align: center; border: 1px solid #e2e8f0; }
    .contact-box p { margin: 4px 0; font-size: 13px; color: #555; }
    .contact-box a { color: #4BFDB3; font-weight: 600; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🛫 Over Seas</h1>
            <p class="sub">إيصال الدفع</p>
        </div>

        <div class="thank-you">
            <h2>شكراً لك يا $name! ✅</h2>
            <p>تم استلام دفعتك بنجاح. إليك تفاصيل العملية:</p>
        </div>

        <div class="detail-row">
            <span class="detail-label">رقم العملية</span>
            <span class="detail-value" style="font-weight:700;">$transactionId</span>
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
            <span class="detail-label">التاريخ</span>
            <span class="detail-value">$dateNow</span>
        </div>
        <div class="detail-row">
            <span class="detail-label">الحالة</span>
            <span class="detail-value"><span class="status-badge">✓ مكتمل</span></span>
        </div>
        <div class="total-row">
            <div class="detail-row" style="border-bottom: none; padding: 0;">
                <span class="detail-label" style="font-size: 16px;">المبلغ المدفوع</span>
                <span class="detail-value" style="font-size: 22px; color: #22c55e; font-weight:800;">AED $amount</span>
            </div>
        </div>

        <div class="contact-box">
            <p>هل لديك أي استفسار؟</p>
            <p><a href="https://wa.me/971564630165">تواصل معنا عبر واتساب</a> | <a href="mailto:info@overseas.ae">info@overseas.ae</a></p>
        </div>

        <div class="footer">
            <p>هذا إيصال تلقائي من Over Seas. يرجى الاحتفاظ به كمرجع.</p>
            <p>© Over Seas - جميع الحقوق محفوظة</p>
        </div>
    </div>
</body>
</html>
HTML;

$userPlain = <<<TEXT
✅ تأكيد الدفع - Over Seas
═══════════════════════════════

شكراً لك يا $name!
تم استلام دفعتك بنجاح.

تفاصيل العملية:
  رقم العملية: $transactionId
  الخدمة: $serviceDisplay
  {$inquiryText}طريقة الدفع: $methodName
  المبلغ: AED $amount
  التاريخ: $dateNow
  الحالة: مكتمل

═══════════════════════════════
للاستفسار: واتساب 971564630165+
البريد: info@overseas.ae
TEXT;


// ══════════════════════════════════════════════════════════════
// SEND BOTH EMAILS
// ══════════════════════════════════════════════════════════════
$errors = [];

// ── Email 1: To Site Owner ──────────────────────────────────
try {
    $mailOwner = new PHPMailer(true);
    $mailOwner->isSMTP();
    $mailOwner->Host       = 'smtp.gmail.com';
    $mailOwner->SMTPAuth   = true;
    $mailOwner->Username   = $SMTP_USER;
    $mailOwner->Password   = $SMTP_PASS;
    $mailOwner->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mailOwner->Port       = 587;
    $mailOwner->CharSet    = 'UTF-8';

    $mailOwner->setFrom($SMTP_USER, 'Over Seas - نظام الدفع');
    $mailOwner->addAddress($SITE_OWNER);
    $mailOwner->addReplyTo($userEmail, $data['name']);

    $mailOwner->isHTML(true);
    $mailOwner->Subject = $ownerSubject;
    $mailOwner->Body    = $ownerHtml;
    $mailOwner->AltBody = $ownerPlain;

    $mailOwner->send();
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - ✅ Owner email sent to: $SITE_OWNER\n", FILE_APPEND);
} catch (Exception $e) {
    $errorMsg = "Owner email failed: " . $mailOwner->ErrorInfo;
    $errors[] = $errorMsg;
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - ❌ " . $errorMsg . "\n", FILE_APPEND);
}

// ── Email 2: To User (Receipt) ──────────────────────────────
try {
    $mailUser = new PHPMailer(true);
    $mailUser->isSMTP();
    $mailUser->Host       = 'smtp.gmail.com';
    $mailUser->SMTPAuth   = true;
    $mailUser->Username   = $SMTP_USER;
    $mailUser->Password   = $SMTP_PASS;
    $mailUser->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mailUser->Port       = 587;
    $mailUser->CharSet    = 'UTF-8';

    $mailUser->setFrom($SMTP_USER, 'Over Seas');
    $mailUser->addAddress($userEmail, $data['name']);

    $mailUser->isHTML(true);
    $mailUser->Subject = $userSubject;
    $mailUser->Body    = $userHtml;
    $mailUser->AltBody = $userPlain;

    $mailUser->send();
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - ✅ User email sent to: $userEmail\n", FILE_APPEND);
} catch (Exception $e) {
    $errorMsg = "User email failed: " . $mailUser->ErrorInfo;
    $errors[] = $errorMsg;
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - ❌ " . $errorMsg . "\n", FILE_APPEND);
}

// ── Response ─────────────────────────────────────────────────
if (empty($errors)) {
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - ✅ Both emails sent successfully for $transactionId\n\n", FILE_APPEND);
    echo json_encode([
        'success' => true,
        'message' => 'Both emails sent successfully',
        'transaction_id' => $data['transactionId'],
        'debug' => [
            'owner_email' => $SITE_OWNER,
            'user_email' => $userEmail,
            'log_file' => $logFile
        ]
    ]);
} else {
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - ❌ Email errors: " . implode(', ', $errors) . "\n\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode([
        'error' => 'One or more emails failed',
        'details' => $errors,
        'debug' => [
            'log_file' => $logFile
        ]
    ]);
}