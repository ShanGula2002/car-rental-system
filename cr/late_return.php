<?php

function calculate_late_return_fee(string $scheduled_return, float $daily_rate, ?DateTime $actual_return = null): array
{
    $scheduled_return_dt = new DateTime($scheduled_return);
    $actual_return = $actual_return ?? new DateTime();
    $late_seconds = max(0, $actual_return->getTimestamp() - $scheduled_return_dt->getTimestamp());
    $late_hours = (int) ceil($late_seconds / (60 * 60));
    $late_days = intdiv($late_hours, 8);
    $remaining_hours = $late_hours % 8;
    $fee = ($late_days * $daily_rate) + ($remaining_hours * 200);

    return [
        'late_hours' => $late_hours,
        'late_days' => $late_days,
        'remaining_hours' => $remaining_hours,
        'fee' => $fee
    ];
}
