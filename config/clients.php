<?php

declare(strict_types=1);

/**
 * Marquee (under hero): all partner names as text.
 *
 * Bottom (#clients): exactly five logos (3 + 2 grid). Images only — no names in the grid.
 *
 * Put files in: assets/images/client-logos/
 * For each row below, either:
 *   - set `file` to the exact filename you copied (e.g. from ~/Documents/logo/website logo), or
 *   - omit `file` and use a file named {logo}.png (or .webp / .jpg / .jpeg / .svg).
 *
 * Sync from your Mac folder (run from project root):
 *   bash scripts/sync-client-logos.sh
 *
 * On the server (e.g. XAMPP), those image files must exist or the site shows name labels instead.
 * Hero / about videos: add assets/video/hero-background.{mp4,webm,mov} and about-reel.{mp4,m4v} locally.
 */
$marquee_names = [
    'Kiran ARK Photography',
    'Plutography',
    'Team Trinity Photography',
    'Silverline Productions',
    'Miracle Media Productions',
    'Anandu Das',
    'Wedding Raja',
    'Lens Talks',
    'R K Photography',
    'Avinash Photography',
    'Joel Fernandes Photography',
];

/**
 * Bottom strip: five logos (3 + 2). `file` must match the filename in assets/images/client-logos/.
 */
$bottom_logos = [
    /** `img_class` `client-list__logo--ink` = white/light artwork → black via CSS. */
    ['name' => 'ARK', 'file' => 'ark no bg - white.png', 'img_class' => 'client-list__logo--ink client-list__logo--size-ark'],
    ['name' => 'Krithikraj Bhat', 'file' => 'Krithikraj-Bhat-black.png'],
    ['name' => 'Lens Talks', 'file' => 'lens talks.png', 'img_class' => 'client-list__logo--ink client-list__logo--size-lens'],
    ['name' => 'Rêves', 'file' => 'rêves logo shortened white.png', 'img_class' => 'client-list__logo--ink'],
    ['name' => 'Satvam', 'file' => 'satvam.png', 'img_class' => 'client-list__logo--size-satvam'],
];

return [
    'marquee_names' => $marquee_names,
    'bottom_logos' => $bottom_logos,
];
