<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Load .env
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (empty($trimmed) || strpos($trimmed, '#') === 0) continue;
        $eqIdx = strpos($trimmed, '=');
        if ($eqIdx === false) continue;
        $key = trim(substr($trimmed, 0, $eqIdx));
        $val = trim(substr($trimmed, $eqIdx + 1));
        $_ENV[$key] = $val;
    }
}

// Protocol and Host
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$base_dir = dirname($_SERVER['SCRIPT_NAME']);
// Remove trailing slash from base_dir if present
if ($base_dir === '/' || $base_dir === '\\') {
    $base_dir = '';
}
$current_url = $protocol . "://" . $host . $base_dir;

$PUBLIC_BASE_URL = $_ENV['PUBLIC_BASE_URL'] ?? $current_url;
$ZIINA_API_KEY = $_ENV['ZIINA_API_KEY'] ?? null;
$TABBY_SECRET_KEY = $_ENV['TABBY_SECRET_KEY'] ?? null;
$TABBY_MERCHANT_CODE = $_ENV['TABBY_MERCHANT_CODE'] ?? "AE";
$TABBY_API_BASE = "https://api.tabby.ai";
$ZIINA_API_BASE = "https://api-v2.ziina.com/api";

function sendJson($status, $data) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function readJsonBody() {
    $json = file_get_contents('php://input');
    return json_decode($json, true) ?: [];
}

function hasTabbyKey() {
    global $TABBY_SECRET_KEY;
    return !empty($TABBY_SECRET_KEY) && strlen($TABBY_SECRET_KEY) >= 10;
}

function buildTabbyPayload($p, $includeRedirects = true) {
    global $PUBLIC_BASE_URL, $TABBY_MERCHANT_CODE;
    
    $orderId = "OS-" . time();
    $amount = number_format((float)$p['amount'], 2, '.', '');
    
    $payload = [
        "payment" => [
            "amount" => $amount,
            "currency" => "AED",
            "description" => $p['service'] ?? "Over Seas Travel Service",
            "buyer" => [
                "phone" => $p['phone'] ?? "",
                "email" => $p['email'] ?? "",
                "name" => $p['name'] ?? "",
                "dob" => "1990-01-01",
            ],
            "buyer_history" => [
                "registered_since" => gmdate('Y-m-d\TH:i:s\Z'),
                "loyalty_level" => 0,
                "wishlist_count" => 0,
                "is_social_networks_connected" => false,
                "is_phone_number_verified" => false,
                "is_email_verified" => false,
            ],
            "order" => [
                "tax_amount" => "0.00",
                "shipping_amount" => "0.00",
                "discount_amount" => "0.00",
                "updated_at" => gmdate('Y-m-d\TH:i:s\Z'),
                "reference_id" => $orderId,
                "items" => [[
                    "title" => $p['service'] ?? "Travel Service",
                    "quantity" => 1,
                    "unit_price" => $amount,
                    "discount_amount" => "0.00",
                    "reference_id" => "SERVICE-001",
                    "image_url" => "{$PUBLIC_BASE_URL}/logo/logo.png",
                    "product_url" => $PUBLIC_BASE_URL,
                    "category" => "Travel",
                ]],
            ],
            "order_history" => [],
            "meta" => ["order_id" => $orderId, "customer" => $p['email'] ?? ""],
        ],
        "lang" => "ar",
        "merchant_code" => $TABBY_MERCHANT_CODE,
    ];

    if ($includeRedirects) {
        $payload["merchant_urls"] = [
            "success" => "{$PUBLIC_BASE_URL}/payment.html?status=success",
            "cancel" => "{$PUBLIC_BASE_URL}/payment.html?status=cancelled",
            "failure" => "{$PUBLIC_BASE_URL}/payment.html?status=failed",
        ];
    }
    return $payload;
}

function extractRejectionReason($data) {
    if (isset($data['configuration']['available_products']['installments']) && is_array($data['configuration']['available_products']['installments'])) {
        $installments = $data['configuration']['available_products']['installments'];
        if (count($installments) > 0 && isset($installments[0]['rejection_reason'])) {
            return $installments[0]['rejection_reason'];
        }
    }
    return "not_available";
}

function httpRequest($url, $method, $headers, $body = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    
    $curlHeaders = [];
    foreach ($headers as $key => $val) {
        $curlHeaders[] = "$key: $val";
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ['code' => $httpCode, 'data' => json_decode($response, true) ?: []];
}

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ── ZIINA ────────────────────────────────────────────────────
    if ($action === 'ziina_checkout') {
        $p = readJsonBody();
        $required = ["name", "phone", "email", "service", "amount"];
        foreach ($required as $f) {
            if (empty($p[$f])) return sendJson(400, ["message" => "Missing: $f"]);
        }

        if (empty($ZIINA_API_KEY)) {
            return sendJson(200, ["test_mode" => true]);
        }

        $amountFils = round((float)$p['amount'] * 100);
        $payload = [
            "amount" => $amountFils,
            "currency_code" => "AED",
            "message" => $p['service'] ?? "Over Seas Payment",
            "success_url" => "{$PUBLIC_BASE_URL}/payment.html?status=success",
            "cancel_url" => "{$PUBLIC_BASE_URL}/payment.html?status=cancelled",
        ];

        $res = httpRequest("{$ZIINA_API_BASE}/payment_intent", "POST", [
            "Authorization" => "Bearer {$ZIINA_API_KEY}",
            "Content-Type" => "application/json",
            "Accept" => "application/json"
        ], json_encode($payload));

        $ziinaData = $res['data'];

        if ($res['code'] < 200 || $res['code'] >= 300) {
            return sendJson(502, ["message" => $ziinaData['message'] ?? "Ziina error. Check your API key."]);
        }

        $redirectUrl = $ziinaData['redirect_url'] ?? null;
        if (!$redirectUrl) {
            return sendJson(502, ["message" => "Ziina did not return a redirect URL."]);
        }

        return sendJson(200, ["web_url" => $redirectUrl]);
    }
    
    // ── TABBY ELIGIBILITY ───────────────────────────────────────────
    if ($action === 'tabby_eligibility') {
        $p = readJsonBody();
        if (empty($p['amount']) || empty($p['buyer_email']) || empty($p['buyer_phone'])) {
            return sendJson(400, ["eligible" => false, "rejection_reason" => "missing_fields"]);
        }

        if (!hasTabbyKey()) {
            return sendJson(200, ["eligible" => true]);
        }

        $payload = buildTabbyPayload([
            "amount" => $p['amount'],
            "phone" => $p['buyer_phone'],
            "email" => $p['buyer_email'],
            "name" => $p['buyer_name'] ?? "",
            "service" => "Eligibility Check",
        ], false);

        $res = httpRequest("{$TABBY_API_BASE}/api/v2/checkout", "POST", [
            "Authorization" => "Bearer {$TABBY_SECRET_KEY}",
            "Content-Type" => "application/json",
            "Accept" => "application/json"
        ], json_encode($payload));

        $tabbyData = $res['data'];

        if (isset($tabbyData['status']) && $tabbyData['status'] === "rejected") {
            return sendJson(200, ["eligible" => false, "rejection_reason" => extractRejectionReason($tabbyData)]);
        }
        return sendJson(200, ["eligible" => true]);
    }
    
    // ── TABBY CHECKOUT ──────────────────────────────────────────────
    if ($action === 'tabby_checkout') {
        $p = readJsonBody();
        $required = ["name", "phone", "email", "service", "amount"];
        foreach ($required as $f) {
            if (empty($p[$f])) return sendJson(400, ["message" => "Missing: $f"]);
        }

        if (!hasTabbyKey()) {
            return sendJson(200, ["test_mode" => true]);
        }

        $payload = buildTabbyPayload($p);
        
        $res = httpRequest("{$TABBY_API_BASE}/api/v2/checkout", "POST", [
            "Authorization" => "Bearer {$TABBY_SECRET_KEY}",
            "Content-Type" => "application/json",
            "Accept" => "application/json"
        ], json_encode($payload));

        $tabbyData = $res['data'];

        if (isset($tabbyData['status']) && $tabbyData['status'] === "rejected") {
            return sendJson(200, [
                "status" => "rejected",
                "rejection_reason" => extractRejectionReason($tabbyData),
            ]);
        }

        $webUrl = $tabbyData['configuration']['available_products']['installments'][0]['web_url'] ?? $tabbyData['web_url'] ?? null;

        if (!$webUrl) {
            return sendJson(502, ["message" => "Tabby did not return a checkout URL."]);
        }

        return sendJson(200, ["web_url" => $webUrl]);
    }
}

sendJson(404, ["error" => "Endpoint not found"]);
