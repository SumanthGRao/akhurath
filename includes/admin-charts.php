<?php

declare(strict_types=1);

/**
 * Chart colours aligned with admin stack-bar status segments.
 */
function akh_admin_chart_status_color(string $status): string
{
    $map = [
        'new' => 'hsl(42, 55%, 42%)',
        'assigned' => 'hsl(218, 38%, 42%)',
        'in_progress' => 'hsl(205, 42%, 40%)',
        'review' => 'hsl(262, 36%, 48%)',
        'delivered' => 'hsl(132, 40%, 36%)',
        'reverted' => 'hsl(28, 55%, 42%)',
        'closed' => 'hsl(268, 12%, 42%)',
        'other' => 'hsl(200, 8%, 45%)',
    ];

    return $map[$status] ?? $map['other'];
}

/**
 * Distinct colours for edit-type and workload bar charts.
 *
 * @return list<string>
 */
function akh_admin_chart_palette(int $count): array
{
    $base = [
        'hsl(42, 55%, 42%)',
        'hsl(218, 38%, 42%)',
        'hsl(132, 40%, 36%)',
        'hsl(262, 36%, 48%)',
        'hsl(28, 55%, 42%)',
        'hsl(205, 42%, 40%)',
        'hsl(168, 32%, 38%)',
        'hsl(338, 36%, 45%)',
    ];
    if ($count <= count($base)) {
        return array_slice($base, 0, max(0, $count));
    }
    $out = [];
    for ($i = 0; $i < $count; ++$i) {
        $out[] = $base[$i % count($base)];
    }

    return $out;
}
