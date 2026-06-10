#!/bin/bash
# ============================================================
# Decrypt Worker - Chạy trên MacBook, poll queue & decrypt IPA
# ============================================================
set -euo pipefail

# ── CONFIG ── (sửa lại theo bạn)
SITE_URL="https://TEN-MIEN-CUA-BAN"
QUEUE_TOKEN="393bbdf85314c5a2f2e912898528ed31568aecab324a115e239d33db05086244"
BOT_TOKEN="8357024413:AAHoZdr4b5x8WPbIxOP_LdSDIQ0_XTQyxOU"
QUEUE_URL="${SITE_URL}/queue.php?token=${QUEUE_TOKEN}"

# ── POLL QUEUE ──
echo "[$(date '+%H:%M:%S')] 🔍 Kiểm tra queue..."
JOBS=$(curl -s --max-time 15 "${QUEUE_URL}" 2>/dev/null || echo '{"ok":false}')

COUNT=$(echo "$JOBS" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('count',0))" 2>/dev/null || echo "0")

if [ "$COUNT" = "0" ]; then
    echo "[$(date '+%H:%M:%S')] ✅ Không có job nào."
    exit 0
fi

echo "[$(date '+%H:%M:%S')] 📦 Có ${COUNT} job đang chờ!"

# ── LẤY JOB ĐẦU TIÊN ──
JOB_ID=$(echo "$JOBS" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['jobs'][0]['id'])" 2>/dev/null)
APP_ID=$(echo "$JOBS" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['jobs'][0]['app_id'])" 2>/dev/null)
CHAT_ID=$(echo "$JOBS" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['jobs'][0]['chat_id'])" 2>/dev/null)
MSG_ID=$(echo "$JOBS" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['jobs'][0].get('message_id',''))" 2>/dev/null)

echo "[$(date '+%H:%M:%S')] 🚀 Bắt đầu decrypt App ID: ${APP_ID} (job: ${JOB_ID})"

# ── GỬI TIN NHẮN "đang xử lý" ──
send_telegram() {
    local text="$1"
    curl -s -X POST "https://api.telegram.org/bot${BOT_TOKEN}/sendMessage" \
        -d "chat_id=${CHAT_ID}" \
        -d "text=${text}" \
        -d "parse_mode=HTML" \
        -d "disable_web_page_preview=true" \
        -d "reply_to_message_id=${MSG_ID}" > /dev/null 2>&1 || true
}

send_telegram "🔐 Đang decrypt App ID: <code>${APP_ID}</code>... (MacBook đang chạy)"

# ── CHẠY IPATOOL ──
WORK_DIR="/tmp/ipatool-decrypt-${JOB_ID}"
mkdir -p "${WORK_DIR}"

cd "${WORK_DIR}"

# Kiểm tra ipatool đã cài chưa
if ! command -v ipatool &>/dev/null; then
    echo "[$(date '+%H:%M:%S')] 📥 Cài đặt ipatool..."
    brew install ipatool
fi

# Decrypt
echo "[$(date '+%H:%M:%S')] 📱 Đang tải IPA..."
DECRYPT_OUTPUT=$(ipatool download \
    --non-interactive \
    --bundle-identifier "${APP_ID}" \
    --purchase \
    --output app.ipa 2>&1) || {
    echo "[$(date '+%H:%M:%S')] ❌ ipatool thất bại: ${DECRYPT_OUTPUT}"
    send_telegram "❌ Decrypt thất bại: ${DECRYPT_OUTPUT}"
    
    # Đánh dấu job thất bại
    curl -s -X POST "${QUEUE_URL}" \
        -H "Content-Type: application/json" \
        -d "{\"job_id\":\"${JOB_ID}\",\"action\":\"complete\",\"download_url\":\"error: ${DECRYPT_OUTPUT}\"}" > /dev/null 2>&1 || true
    rm -rf "${WORK_DIR}"
    exit 1
}

echo "[$(date '+%H:%M:%S')] ✅ Decrypt xong! Đang upload..."

# ── UPLOAD LÊN GOFILE ──
IPA_FILE=$(ls *.ipa 2>/dev/null | head -1)
if [ -z "${IPA_FILE}" ]; then
    send_telegram "❌ Không tìm thấy file IPA sau khi decrypt."
    rm -rf "${WORK_DIR}"
    exit 1
fi

FILE_SIZE=$(du -h "${IPA_FILE}" | cut -f1)
echo "[$(date '+%H:%M:%S')] 📤 Upload ${IPA_FILE} (${FILE_SIZE}) lên Gofile..."

UPLOAD_RESPONSE=$(curl -s -F "file=@${IPA_FILE}" "https://store1.gofile.io/uploadFile" 2>/dev/null)
DOWNLOAD_URL=$(echo "$UPLOAD_RESPONSE" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['downloadPage'])" 2>/dev/null || echo "")

if [ -z "${DOWNLOAD_URL}" ]; then
    send_telegram "❌ Upload Gofile thất bại."
    rm -rf "${WORK_DIR}"
    exit 1
fi

echo "[$(date '+%H:%M:%S')] ✅ Upload xong: ${DOWNLOAD_URL}"

# ── GỬI LINK CHO USER ──
send_telegram "ipa đã được Decrypt xong 🎉%0A%0A📦 Tải về: ${DOWNLOAD_URL}%0A📏 Dung lượng: ${FILE_SIZE}"

# ── ĐÁNH DẤU JOB HOÀN THÀNH ──
curl -s -X POST "${QUEUE_URL}" \
    -H "Content-Type: application/json" \
    -d "{\"job_id\":\"${JOB_ID}\",\"action\":\"complete\",\"download_url\":\"${DOWNLOAD_URL}\"}" > /dev/null 2>&1 || true

# ── DỌN DẸP ──
rm -rf "${WORK_DIR}"
echo "[$(date '+%H:%M:%S')] 🎉 Hoàn tất job ${JOB_ID}!"
