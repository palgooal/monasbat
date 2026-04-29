#!/bin/bash
# =============================================
# سكربت اختبار Webhook سلة
# شغّله من Git Bash أو WSL على جهازك:
#   bash test-webhook.sh
# =============================================

BASE_URL="http://monasbat.test/wp-json/mon/v1/salla-callback"
# ضع هنا مفتاح Webhook من لوحة التحكم (PgEvents > متاجر سلة)
SECRET="YOUR_WEBHOOK_SECRET_HERE"

# ─── Payloads تجريبية ─────────────────────────────
PAYLOAD_INVALID='{"event":"order.created","merchant":999,"data":{}}'

PAYLOAD_AUTHORIZE='{"event":"app.store.authorize","merchant":123456,"data":{"access_token":"test_token_abc123","refresh_token":"test_refresh_xyz","expires":9999999999,"scope":"settings.read","token_type":"bearer"}}'

PAYLOAD_UNINSTALL='{"event":"app.store.uninstall","merchant":123456,"data":{}}'

# للاختبار الحقيقي: استبدل SALLA_PRODUCT_ID بـ salla_id المخزّن في الباقة
PAYLOAD_ORDER='{"event":"order.created","merchant":123456,"data":{"id":9001,"status":{"slug":"completed"},"customer":{"email":"test@example.com","first_name":"محمد","last_name":"الاختبار","full_name":"محمد الاختبار"},"items":[{"product":{"id":"SALLA_PRODUCT_ID_HERE"}}]}}'

# ─── دالة حساب الـ HMAC-SHA256 ───────────────────
sign() {
    echo -n "$1" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}'
}

# ─── تشغيل الاختبارات ─────────────────────────────

echo ""
echo "════════════════════════════════════════════"
echo " اختبار 1: بدون signature → متوقع 401"
echo "════════════════════════════════════════════"
curl -s -w "\nHTTP: %{http_code}\n" -X POST "$BASE_URL" \
  -H "Content-Type: application/json" \
  -d "$PAYLOAD_INVALID"

echo ""
echo "════════════════════════════════════════════"
echo " اختبار 2: signature خاطئ → متوقع 401"
echo "════════════════════════════════════════════"
curl -s -w "\nHTTP: %{http_code}\n" -X POST "$BASE_URL" \
  -H "Content-Type: application/json" \
  -H "x-salla-signature: 0000000000000000000000000000000000000000000000000000000000000000" \
  -d "$PAYLOAD_INVALID"

echo ""
echo "════════════════════════════════════════════"
echo " اختبار 3: app.store.authorize → متوقع 200 + authorized"
echo "════════════════════════════════════════════"
SIG=$(sign "$PAYLOAD_AUTHORIZE")
echo "Signature: $SIG"
curl -s -w "\nHTTP: %{http_code}\n" -X POST "$BASE_URL" \
  -H "Content-Type: application/json" \
  -H "x-salla-signature: $SIG" \
  -d "$PAYLOAD_AUTHORIZE"

echo ""
echo "════════════════════════════════════════════"
echo " اختبار 4: order.created → متوقع 200 + success/ignored"
echo "════════════════════════════════════════════"
SIG=$(sign "$PAYLOAD_ORDER")
echo "Signature: $SIG"
curl -s -w "\nHTTP: %{http_code}\n" -X POST "$BASE_URL" \
  -H "Content-Type: application/json" \
  -H "x-salla-signature: $SIG" \
  -d "$PAYLOAD_ORDER"

echo ""
echo "════════════════════════════════════════════"
echo " اختبار 5: app.store.uninstall → متوقع 200 + uninstalled"
echo "════════════════════════════════════════════"
SIG=$(sign "$PAYLOAD_UNINSTALL")
echo "Signature: $SIG"
curl -s -w "\nHTTP: %{http_code}\n" -X POST "$BASE_URL" \
  -H "Content-Type: application/json" \
  -H "x-salla-signature: $SIG" \
  -d "$PAYLOAD_UNINSTALL"

echo ""
echo "══════════════════════════════════════════════"
echo " تم. راجع wp-content/debug.log للتفاصيل."
echo "══════════════════════════════════════════════"
