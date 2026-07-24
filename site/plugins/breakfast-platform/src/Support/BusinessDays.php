<?php

declare(strict_types=1);

namespace Breakfast\Platform\Support;

/**
 * Business-day date arithmetic for template relative dates (kickoff + 5 business
 * days, etc.). Weekends are skipped; the calculation is deterministic and
 * timezone-agnostic (dates only). Offset 0 returns the anchor date; a negative
 * offset walks backwards.
 */
final class BusinessDays
{
    /** Add (or subtract, if negative) N business days to a Y-m-d date. */
    public static function add(string $date, int $businessDays): string
    {
        $t = strtotime($date);
        if ($t === false) {
            return $date;
        }
        if ($businessDays === 0) {
            return date('Y-m-d', $t);
        }
        $step = $businessDays > 0 ? 1 : -1;
        $remaining = abs($businessDays);
        while ($remaining > 0) {
            $t += $step * 86400;
            $dow = (int) date('N', $t); // 1 (Mon) .. 7 (Sun)
            if ($dow < 6) {
                $remaining--;
            }
        }

        return date('Y-m-d', $t);
    }
}
