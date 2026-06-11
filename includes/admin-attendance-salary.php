<?php

declare(strict_types=1);

/**
 * Salary after leave / LOP for one editor month.
 *
 * LOP days = unapproved absences + approved leave above the monthly allowance.
 * Half-day approved leave counts as 0.5 day (from excused_leave_days).
 */
function akh_admin_attendance_salary_calc(
    float $monthlySalary,
    float $allowedLeaves,
    int $workingDays,
    float $excusedLeaveDays,
    int $unapprovedLeaveDays
): array {
    $workingDays = max(1, $workingDays);
    $monthlySalary = max(0.0, $monthlySalary);
    $allowedLeaves = max(0.0, $allowedLeaves);
    $excusedLeaveDays = max(0.0, $excusedLeaveDays);
    $unapprovedLeaveDays = max(0, $unapprovedLeaveDays);

    $perDayRate = $monthlySalary / $workingDays;
    $paidLeaveDays = min($excusedLeaveDays, $allowedLeaves);
    $lopFromApproved = max(0.0, $excusedLeaveDays - $allowedLeaves);
    $lopFromAbsent = (float) $unapprovedLeaveDays;
    $lopDays = $lopFromAbsent + $lopFromApproved;
    $lopDeduction = round($lopDays * $perDayRate, 2);
    $netSalary = round(max(0.0, $monthlySalary - $lopDeduction), 2);

    return [
        'monthly_salary' => $monthlySalary,
        'allowed_leaves' => $allowedLeaves,
        'working_days' => $workingDays,
        'per_day_rate' => round($perDayRate, 2),
        'approved_leave_days' => round($excusedLeaveDays, 1),
        'paid_leave_days' => round($paidLeaveDays, 1),
        'unapproved_absent_days' => $unapprovedLeaveDays,
        'lop_from_approved' => round($lopFromApproved, 1),
        'lop_from_absent' => round($lopFromAbsent, 1),
        'lop_days' => round($lopDays, 1),
        'lop_deduction' => $lopDeduction,
        'net_salary' => $netSalary,
    ];
}

function akh_admin_attendance_salary_format_inr(float $amount): string
{
    $rounded = round($amount, 2);
    if (abs($rounded - round($rounded)) < 0.001) {
        return '₹' . number_format((float) round($rounded), 0, '.', ',');
    }

    return '₹' . number_format($rounded, 2, '.', ',');
}

function akh_admin_attendance_salary_format_days(float $days): string
{
    $v = round($days, 1);
    if (abs($v - round($v)) < 0.001) {
        return (string) (int) round($v);
    }

    return number_format($v, 1, '.', '');
}
