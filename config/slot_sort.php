<?php
/**
 * Slot codes: {floor}{row}{column} e.g. 1A2, 1A10, 2B9.
 * String ORDER BY slot_code puts 1A10 before 1A2; use column index for grid order.
 */


declare(strict_types=1);


function parky_slot_column_index(string $slotCode): int
{
    if (preg_match('/^\d+[A-Za-z]+(\d+)$/u', $slotCode, $m)) {
        return (int) $m[1];
    }


    return PHP_INT_MAX;
}


/** Left-to-right order within a row (1A2 before 1A10). */
function parky_usort_row_slots(array &$rowSlots): void
{
    usort($rowSlots, static function (array $a, array $b): int {
        $ca = parky_slot_column_index($a['slot_code'] ?? '');
        $cb = parky_slot_column_index($b['slot_code'] ?? '');
        if ($ca !== $cb) {
            return $ca <=> $cb;
        }


        return strcmp($a['slot_code'] ?? '', $b['slot_code'] ?? '');
    });
}

