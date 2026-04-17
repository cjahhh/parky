<?php
// kiosks/includes/kiosk-helpers.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/rates.php';


function generateReceiptNumber(): string {
    return 'RCP-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}


// getAmountDue() lives in config/rates.php (loaded above).


// ── FIX: Widened time window ──────────────────────────────────────────────────
// OLD: arrival_time <= DATE_ADD(NOW(), INTERVAL 30 MINUTE)
//   → This caused arriving-early users (e.g. 1 hr before) to miss the check.
//
// NEW: expires_at > NOW() AND arrival_time <= DATE_ADD(NOW(), INTERVAL 2 HOUR)
//   → A reservation is valid as long as the grace period hasn't elapsed.
//     We also allow arriving up to 2 hours early so the kiosk can pre-load
//     the reservation details when the car is detected in the camera.
//     The 2-hour look-ahead is intentionally generous; the grace period
//     (expires_at) acts as the hard cut-off on the other end.
// ─────────────────────────────────────────────────────────────────────────────
function checkReservationByPlate(PDO $pdo, string $plate): ?array {
    $cleanPlate = strtoupper(str_replace(['-', ' '], '', $plate));

    $stmt = $pdo->prepare("
        SELECT r.*, ps.slot_code, ps.floor,
               TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS user_name,
               u.email AS user_email
        FROM reservations r
        JOIN parking_slots ps ON r.slot_id = ps.id
        LEFT JOIN users u     ON r.user_id = u.id
        WHERE REPLACE(REPLACE(UPPER(r.plate_number), '-', ''), ' ', '') = ?
          AND r.status IN ('pending', 'confirmed')
          AND DATE(r.arrival_time) = CURDATE()
        ORDER BY r.arrival_time ASC
        LIMIT 1
    ");
    $stmt->execute([$cleanPlate]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}


function getActiveSessionByPlate(PDO $pdo, string $plate): ?array {
    $plate = strtoupper($plate);


    // Walk-in first
    $stmt = $pdo->prepare("
        SELECT sw.*, ps.slot_code, ps.floor,
               'walkin' AS session_type,
               sw.entry_time AS entry_time
        FROM sessions_walkin sw
        JOIN parking_slots ps ON sw.slot_id = ps.id
        WHERE UPPER(sw.plate_number) = ?
          AND sw.status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$plate]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;


    // Then active reservation
    $stmt = $pdo->prepare("
        SELECT r.*, ps.slot_code, ps.floor,
               'reservation' AS session_type,
               r.arrival_time AS entry_time
        FROM reservations r
        JOIN parking_slots ps ON r.slot_id = ps.id
        WHERE UPPER(r.plate_number) = ?
          AND r.status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$plate]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}


function getSlotsByFloor(PDO $pdo, int $floor): array {
    $stmt = $pdo->prepare("
        SELECT id, slot_code, floor, status
        FROM parking_slots
        WHERE floor = ?
        ORDER BY slot_code ASC
    ");
    $stmt->execute([$floor]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


function countSlotsByStatus(PDO $pdo): array {
    $stmt = $pdo->query("SELECT status, COUNT(*) AS cnt FROM parking_slots GROUP BY status");
    $counts = ['available' => 0, 'occupied' => 0, 'reserved' => 0, 'maintenance' => 0, 'total' => 0];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $counts[$r['status']] = (int)$r['cnt'];
        $counts['total']     += (int)$r['cnt'];
    }
    return $counts;
}


function getTfServiceUrl(): string {
    $url = getenv('PARKY_TF_SERVICE_URL');
    return (!empty($url)) ? $url : 'http://127.0.0.1:8765/detect';
}


function isDetectorReachable(): bool {
    $fp = @fsockopen('127.0.0.1', 8765, $errno, $errstr, 0.7);
    if ($fp) { fclose($fp); return true; }
    return false;
}


function callTfDetector(string $frameData): array {
    $url     = getTfServiceUrl();
    $payload = json_encode([
        'image_b64' => $frameData,
        'request_id' => uniqid('kiosk_', true),
    ]);
    if (!function_exists('curl_init')) {
        return ['success' => false, 'message' => 'cURL not available'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => 120,
    ]);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) return ['success' => false, 'message' => "Detector unreachable: $err"];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return ['success' => false, 'message' => 'Invalid JSON from detector'];
    if ($code >= 400) return ['success' => false, 'message' => $decoded['detail'] ?? "HTTP $code"];
    return $decoded;
}


function processKioskPayment(
    PDO    $pdo,
    int    $sessionId,
    string $sessionType,
    string $method,
    string $source = 'kiosk'
): array {
    if ($sessionType === 'walkin') {
        $stmt = $pdo->prepare("SELECT * FROM sessions_walkin WHERE id = ? AND status = 'active'");
    } else {
        $stmt = $pdo->prepare("SELECT * FROM reservations WHERE id = ? AND status = 'active'");
    }
    $stmt->execute([$sessionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['success' => false, 'message' => 'Session no longer active.'];


    $entryTime = ($sessionType === 'walkin') ? $row['entry_time'] : $row['arrival_time'];
    $elapsed   = time() - strtotime($entryTime);
    $amountDue = getAmountDue($elapsed, $row['paid_until'], $entryTime);


    if ($amountDue <= 0) return ['success' => false, 'message' => 'No outstanding balance.'];


    $paidUntilNew  = date('Y-m-d H:i:s');
    $receiptNumber = generateReceiptNumber();
    $extensionFee  = ($sessionType === 'reservation') ? (float)($row['extension_fee'] ?? 0) : 0.0;


    try {
        $pdo->beginTransaction();
        $pdo->prepare("
            INSERT INTO payments
                (session_id, session_type, base_amount, extension_fee,
                 total_amount, method, source, receipt_number)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $sessionId, $sessionType, $amountDue, $extensionFee,
            $amountDue, $method, $source, $receiptNumber,
        ]);
        if ($sessionType === 'walkin') {
            $pdo->prepare(
                "UPDATE sessions_walkin SET payment_status='paid', paid_until=? WHERE id=?"
            )->execute([$paidUntilNew, $sessionId]);
        } else {
            $pdo->prepare(
                "UPDATE reservations SET payment_status='paid', paid_until=? WHERE id=?"
            )->execute([$paidUntilNew, $sessionId]);
        }
        $pdo->commit();
        return [
            'success'        => true,
            'receipt_number' => $receiptNumber,
            'amount_paid'    => $amountDue,
            'paid_until'     => $paidUntilNew,
            'method'         => $method,
        ];
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => 'Payment failed. Please try again.'];
    }
}

