const http = require("http");
const fs   = require("fs");
const path = require("path");


// ── Load .env ────────────────────────────────────────────────
(function loadEnv() {
  try {
    const envContent = fs.readFileSync(path.join(__dirname, ".env"), "utf-8");
    envContent.split("\n").forEach(line => {
      const trimmed = line.trim();
      if (!trimmed || trimmed.startsWith("#")) return;
      const eqIdx = trimmed.indexOf("=");
      if (eqIdx === -1) return;
      const key = trimmed.slice(0, eqIdx).trim();
      const val = trimmed.slice(eqIdx + 1).trim();
      if (!process.env[key]) process.env[key] = val;
    });
  } catch {}
})();

const PORT                = Number(process.env.PORT || 4173);
const PUBLIC_BASE_URL     = process.env.PUBLIC_BASE_URL || `http://localhost:${PORT}`;
const ZIINA_API_KEY       = process.env.ZIINA_API_KEY;
const TABBY_SECRET_KEY    = process.env.TABBY_SECRET_KEY;
const TABBY_PUBLIC_KEY    = process.env.TABBY_PUBLIC_KEY;
const TABBY_MERCHANT_CODE = process.env.TABBY_MERCHANT_CODE || "AE";
const TABBY_API_BASE      = "https://api.tabby.ai";
const ZIINA_API_BASE      = "https://api-v2.ziina.com/api";



const MIME_TYPES = {
  ".html": "text/html; charset=utf-8",
  ".css":  "text/css; charset=utf-8",
  ".js":   "application/javascript; charset=utf-8",
  ".png":  "image/png",
  ".jpg":  "image/jpeg",
  ".jpeg": "image/jpeg",
  ".avif": "image/avif",
  ".svg":  "image/svg+xml",
  ".webp": "image/webp",
};

// ── Helpers ──────────────────────────────────────────────────
function sendJson(res, status, data) {
  res.writeHead(status, {
    "Content-Type":                "application/json; charset=utf-8",
    "Access-Control-Allow-Origin": "*",
    "Access-Control-Allow-Headers": "Content-Type",
    "Access-Control-Allow-Methods": "POST, GET, OPTIONS",
  });
  res.end(JSON.stringify(data));
}

function readJsonBody(req) {
  return new Promise((resolve, reject) => {
    let body = "";
    req.on("data", chunk => {
      body += chunk;
      if (body.length > 200_000) { req.destroy(); reject(new Error("Body too large.")); }
    });
    req.on("end", () => {
      try { resolve(JSON.parse(body || "{}")); }
      catch { reject(new Error("Invalid JSON.")); }
    });
  });
}

function hasTabbyKey() {
  return TABBY_SECRET_KEY && TABBY_SECRET_KEY.length >= 10;
}

// ── ZIINA ────────────────────────────────────────────────────
async function ziinaCheckout(req, res) {
  try {
    const p = await readJsonBody(req);
    const missing = ["name", "phone", "email", "service", "amount"].find(f => !p[f]);
    if (missing) return sendJson(res, 400, { message: `Missing: ${missing}` });

    if (!ZIINA_API_KEY) {
      console.log("[Ziina] No API key — returning test_mode");
      return sendJson(res, 200, { test_mode: true });
    }

    const amountFils = Math.round(Number(p.amount) * 100);
    const payload = {
      amount: amountFils,
      currency_code: "AED",
      message: p.service || "Over Seas Payment",
      success_url: `${PUBLIC_BASE_URL}/payment.html?status=success`,
      cancel_url: `${PUBLIC_BASE_URL}/payment.html?status=cancelled`,
    };

    console.log("[Ziina][checkout] Sending:", JSON.stringify(payload));

    const ziinaRes = await fetch(`${ZIINA_API_BASE}/payment_intent`, {
      method: "POST",
      headers: {
        "Authorization": `Bearer ${ZIINA_API_KEY}`,
        "Content-Type": "application/json",
        "Accept": "application/json",
      },
      body: JSON.stringify(payload),
    });

    const ziinaData = await ziinaRes.json();
    console.log("[Ziina][checkout] HTTP:", ziinaRes.status, "| Response:", JSON.stringify(ziinaData));

    if (!ziinaRes.ok) {
      return sendJson(res, 502, { message: ziinaData?.message || "Ziina error. Check your API key." });
    }

    const redirectUrl = ziinaData?.redirect_url;
    if (!redirectUrl) {
      return sendJson(res, 502, { message: "Ziina did not return a redirect URL." });
    }

    return sendJson(res, 200, { web_url: redirectUrl });

  } catch (err) {
    console.error("[Ziina][checkout] error:", err.message);
    return sendJson(res, 500, { message: err.message });
  }
}

// ── TABBY ────────────────────────────────────────────────────
function tabbyHeaders() {
  return {
    "Authorization": `Bearer ${TABBY_SECRET_KEY}`,
    "Content-Type": "application/json",
    "Accept": "application/json",
  };
}

function buildTabbyPayload(p, includeRedirects = true) {
  const orderId = `OS-${Date.now()}`;
  const payload = {
    payment: {
      amount: Number(p.amount).toFixed(2),
      currency: "AED",
      description: p.service || "Over Seas Travel Service",
      buyer: {
        phone: p.phone || "",
        email: p.email || "",
        name: p.name || "",
        dob: "1990-01-01",
      },
      buyer_history: {
        registered_since: new Date().toISOString(),
        loyalty_level: 0,
        wishlist_count: 0,
        is_social_networks_connected: false,
        is_phone_number_verified: false,
        is_email_verified: false,
      },
      order: {
        tax_amount: "0.00",
        shipping_amount: "0.00",
        discount_amount: "0.00",
        updated_at: new Date().toISOString(),
        reference_id: orderId,
        items: [{
          title: p.service || "Travel Service",
          quantity: 1,
          unit_price: Number(p.amount).toFixed(2),
          discount_amount: "0.00",
          reference_id: "SERVICE-001",
          image_url: `${PUBLIC_BASE_URL}/logo/logo.png`,
          product_url: PUBLIC_BASE_URL,
          category: "Travel",
        }],
      },
      order_history: [],
      meta: { order_id: orderId, customer: p.email || "" },
    },
    lang: "ar",
    merchant_code: TABBY_MERCHANT_CODE,
  };

  if (includeRedirects) {
    payload.merchant_urls = {
      success: `${PUBLIC_BASE_URL}/payment.html?status=success`,
      cancel: `${PUBLIC_BASE_URL}/payment.html?status=cancelled`,
      failure: `${PUBLIC_BASE_URL}/payment.html?status=failed`,
    };
  }
  return payload;
}

function extractRejectionReason(data) {
  const installments = data?.configuration?.available_products?.installments;
  if (!installments || installments.length === 0) return "not_available";
  return installments[0]?.rejection_reason || "not_available";
}

async function tabbyEligibility(req, res) {
  try {
    const p = await readJsonBody(req);
    if (!p.amount || !p.buyer_email || !p.buyer_phone) {
      return sendJson(res, 400, { eligible: false, rejection_reason: "missing_fields" });
    }

    if (!hasTabbyKey()) {
      console.log("[Tabby][eligibility] No key — returning eligible:true (dev mode)");
      return sendJson(res, 200, { eligible: true });
    }

    const payload = buildTabbyPayload({
      amount: p.amount, phone: p.buyer_phone,
      email: p.buyer_email, name: p.buyer_name || "",
      service: "Eligibility Check",
    }, false);

    const tabbyRes = await fetch(`${TABBY_API_BASE}/api/v2/checkout`, {
      method: "POST", headers: tabbyHeaders(), body: JSON.stringify(payload),
    });
    const tabbyData = await tabbyRes.json();

    if (tabbyData?.status === "rejected") {
      return sendJson(res, 200, { eligible: false, rejection_reason: extractRejectionReason(tabbyData) });
    }
    return sendJson(res, 200, { eligible: true });

  } catch (err) {
    console.error("[Tabby][eligibility] error:", err.message);
    return sendJson(res, 200, { eligible: true });
  }
}

async function tabbyCheckout(req, res) {
  try {
    const p = await readJsonBody(req);
    const missing = ["name", "phone", "email", "service", "amount"].find(f => !p[f]);
    if (missing) return sendJson(res, 400, { message: `Missing: ${missing}` });

    if (!hasTabbyKey()) {
      console.log("[Tabby][checkout] No key — returning test_mode");
      return sendJson(res, 200, { test_mode: true });
    }

    const payload = buildTabbyPayload(p);
    const tabbyRes = await fetch(`${TABBY_API_BASE}/api/v2/checkout`, {
      method: "POST", headers: tabbyHeaders(), body: JSON.stringify(payload),
    });
    const tabbyData = await tabbyRes.json();

    console.log("[Tabby][checkout] HTTP:", tabbyRes.status, "| Status:", tabbyData?.status);

    if (tabbyData?.status === "rejected") {
      return sendJson(res, 200, {
        status: "rejected",
        rejection_reason: extractRejectionReason(tabbyData),
      });
    }

    const products = tabbyData?.configuration?.available_products;
    const installments = products?.installments;
    const webUrl = installments?.[0]?.web_url || tabbyData?.web_url || null;

    if (!webUrl) {
      return sendJson(res, 502, { message: "Tabby did not return a checkout URL." });
    }

    return sendJson(res, 200, { web_url: webUrl });

  } catch (err) {
    console.error("[Tabby][checkout] error:", err.message);
    return sendJson(res, 500, { message: err.message });
  }
}

// ── Static file server ───────────────────────────────────────
function serveStatic(req, res) {
  const reqUrl = new URL(req.url, `http://localhost:${PORT}`);
  const pathname = reqUrl.pathname === "/" ? "/index.html" : reqUrl.pathname;
  const safePath = path.normalize(decodeURIComponent(pathname)).replace(/^(\.\.[/\\])+/, "");
  const filePath = path.join(__dirname, safePath);
  if (!filePath.startsWith(__dirname)) { res.writeHead(403); res.end("Forbidden"); return; }
  fs.readFile(filePath, (err, content) => {
    if (err) { res.writeHead(404); res.end("Not found"); return; }
    res.writeHead(200, {
      "Content-Type": MIME_TYPES[path.extname(filePath)] || "application/octet-stream",
      "Access-Control-Allow-Origin": "*",
    });
    res.end(content);
  });
}

// ── Router ───────────────────────────────────────────────────
const server = http.createServer((req, res) => {
  if (req.method === "OPTIONS") {
    res.writeHead(204, {
      "Access-Control-Allow-Origin": "*",
      "Access-Control-Allow-Headers": "Content-Type",
      "Access-Control-Allow-Methods": "POST, GET, OPTIONS",
    });
    res.end();
    return;
  }

  const url = req.url.split("?")[0];

  if (req.method === "POST") {
    if (url === "/api/ziina/checkout")          return ziinaCheckout(req, res);
    if (url === "/api/tabby/eligibility")       return tabbyEligibility(req, res);
    if (url === "/api/tabby/checkout")          return tabbyCheckout(req, res);

  }

  if (req.method === "GET") return serveStatic(req, res);

  res.writeHead(405);
  res.end("Method not allowed");
});

server.listen(PORT, () => {
  console.log(`\n🌍 Over Seas running at http://localhost:${PORT}`);
  console.log(`📧 Email notifications will be sent to: Overseastravel.contact@gmail.com`);
  console.log(ZIINA_API_KEY  ? "✅ Ziina key loaded"        : "⚠  No ZIINA_API_KEY  → demo mode");
  console.log(hasTabbyKey()  ? "✅ Tabby secret key loaded" : "⚠  No TABBY_SECRET_KEY → demo mode");

});