<?php
// ============================================================
//  config/rates.php — Parky Fee Structure
// ============================================================


// First 3 hours flat
define('RATE_FIRST_BLOCK',    60);   // ₱60 for hours 1–3
define('RATE_FIRST_HOURS',     3);   // how many hours the flat rate covers


// Grace period after the first block (and after each subsequent hour)
define('GRACE_MINUTES',       15);   // 15-min grace = ₱10
define('RATE_GRACE',          10);   // ₱10 for grace period overage


// Per hour after the first block (beyond grace)
define('RATE_PER_HOUR',       30);   // ₱30 per hour


// Reservation extension
define('EXTENSION_FEE',       30);   // ₱30 for 1-hour extension
define('EXTENSION_HOURS',      1);   // how many hours extension adds


// ============================================================
//  calculateFee($seconds) — returns float
//  Pass total elapsed seconds since entry_time.
// ============================================================
function calculateFee(int $seconds): float
{
    if ($seconds <= 0) return (float) RATE_FIRST_BLOCK;


    $minutes = $seconds / 60;
    $firstBlockMinutes = RATE_FIRST_HOURS * 60; // 180 min


    // Still within first 3 hours
    if ($minutes <= $firstBlockMinutes) {
        return (float) RATE_FIRST_BLOCK;
    }


    // Beyond first 3 hours
    $fee = RATE_FIRST_BLOCK;
    $extraMinutes = $minutes - $firstBlockMinutes;


    // Each "cycle" = 15-min grace + 45-min remainder = 60 min (1 hour)
    // Grace period (1–15 min overage): ₱10
    // Beyond grace up to 60 min: ₱30
    // Each full subsequent hour: ₱30


    $fullHours = (int) floor($extraMinutes / 60);
    $remainder = $extraMinutes - ($fullHours * 60); // leftover minutes


    // Add ₱30 for each full extra hour
    $fee += $fullHours * RATE_PER_HOUR;


    // Handle the remaining partial hour
    if ($remainder > 0 && $remainder <= GRACE_MINUTES) {
        $fee += RATE_GRACE;      // within grace = ₱10
    } elseif ($remainder > GRACE_MINUTES) {
        $fee += RATE_PER_HOUR;   // beyond grace = full ₱30
    }


    return (float) $fee;
}


/**
 * Amount still owed vs what the session's paid_until already credits (same as exit kiosk).
 * $elapsedSeconds = now - entry_time. $paidUntil = column paid_until (coverage end / last settle time).
 */
function getAmountDue(int $elapsedSeconds, ?string $paidUntil, string $entryTime): float
{
    $totalFee = calculateFee($elapsedSeconds);
    if (!$paidUntil) {
        return $totalFee;
    }
    $paidElapsed = max(0, strtotime($paidUntil) - strtotime($entryTime));
    return max(0.0, $totalFee - calculateFee((int) $paidElapsed));
}

