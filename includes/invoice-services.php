<?php

declare(strict_types=1);

/**
 * Default studio service catalog for invoice line items (rates are editable on the form).
 *
 * @return array<string, array{label: string, inr: int}>
 */
function akh_invoice_service_catalog(): array
{
    $catalog = [
        'teaser' => ['label' => 'Cinematic teaser', 'inr' => 3000],
        'highlights_5' => ['label' => '5 min highlights film', 'inr' => 8000],
        'highlights_7' => ['label' => '7 min highlights film', 'inr' => 10000],
        'reel' => ['label' => 'Instagram / social reel', 'inr' => 1000],
        'traditional' => ['label' => 'Traditional video (up to 1 hr)', 'inr' => 3000],
        'doc_teaser' => ['label' => '2–3 min documentary teaser', 'inr' => 5000],
        'highlights_10' => ['label' => '5–10 min highlights / film', 'inr' => 12000],
        'film_30' => ['label' => '30 min film', 'inr' => 15000],
        'color_grade' => ['label' => 'Color grading (per project)', 'inr' => 4000],
        'sound_design' => ['label' => 'Sound design & mix', 'inr' => 3500],
        'revision_pack' => ['label' => 'Additional revision round', 'inr' => 1500],
        'custom' => ['label' => 'Custom service (describe below)', 'inr' => 0],
    ];

    if (defined('AKH_INVOICE_EXTRA_SERVICES') && is_array(AKH_INVOICE_EXTRA_SERVICES)) {
        foreach (AKH_INVOICE_EXTRA_SERVICES as $key => $meta) {
            if (!is_string($key) || !is_array($meta)) {
                continue;
            }
            $label = trim((string) ($meta['label'] ?? ''));
            $inr = (int) ($meta['inr'] ?? 0);
            if ($label !== '') {
                $catalog[$key] = ['label' => $label, 'inr' => max(0, $inr)];
            }
        }
    }

    return $catalog;
}

function akh_invoice_service_label(string $key): string
{
    $catalog = akh_invoice_service_catalog();
    if ($key !== '' && isset($catalog[$key])) {
        return (string) $catalog[$key]['label'];
    }

    return '';
}

function akh_invoice_service_default_inr(string $key): int
{
    $catalog = akh_invoice_service_catalog();
    if ($key !== '' && isset($catalog[$key])) {
        return (int) $catalog[$key]['inr'];
    }

    return 0;
}
