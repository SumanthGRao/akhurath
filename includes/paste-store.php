<?php

declare(strict_types=1);

const AKH_PASTE_MAX_BYTES = 100_000;

function akh_paste_path(): string
{
    $dir = AKH_ROOT . '/data/pastes';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    return $dir . '/shared.json';
}

/** @return array{content: string, updated: int} */
function akh_paste_get(): array
{
    $default = ['content' => '', 'updated' => 0];
    $path = akh_paste_path();
    if (!is_file($path)) {
        return $default;
    }

    $raw = @file_get_contents($path);
    if ($raw === false) {
        return $default;
    }

    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return $default;
    }

    if (!is_array($data)) {
        return $default;
    }

    return [
        'content' => isset($data['content']) ? (string) $data['content'] : '',
        'updated' => isset($data['updated']) ? (int) $data['updated'] : 0,
    ];
}

/** @return array{content: string, updated: int, error?: string} */
function akh_paste_set(string $content): array
{
    if (strlen($content) > AKH_PASTE_MAX_BYTES) {
        return ['error' => 'too_large', 'content' => '', 'updated' => 0];
    }

    $updated = time();
    $payload = json_encode(
        [
            'content' => $content,
            'updated' => $updated,
        ],
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );

    if (@file_put_contents(akh_paste_path(), $payload, LOCK_EX) === false) {
        return ['error' => 'save_failed', 'content' => '', 'updated' => 0];
    }

    return [
        'content' => $content,
        'updated' => $updated,
    ];
}
