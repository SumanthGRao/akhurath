<?php

declare(strict_types=1);

const AKH_PASTE_MAX_BYTES = 100_000;
const AKH_PASTE_TTL_SECONDS = 86_400;
const AKH_PASTE_ID_LENGTH = 8;

function akh_paste_dir(): string
{
    $dir = AKH_ROOT . '/data/pastes';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    return $dir;
}

function akh_paste_generate_id(): string
{
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $len = strlen($chars);
    $id = '';
    for ($i = 0; $i < AKH_PASTE_ID_LENGTH; $i++) {
        $id .= $chars[random_int(0, $len - 1)];
    }

    return $id;
}

function akh_paste_sanitize_id(string $id): ?string
{
    if (!preg_match('/^[a-zA-Z0-9]{6,12}$/', $id)) {
        return null;
    }

    return $id;
}

/** @return array{id?: string, url?: string, error?: string} */
function akh_paste_save(string $content): array
{
    $content = trim($content);
    if ($content === '') {
        return ['error' => 'empty'];
    }
    if (strlen($content) > AKH_PASTE_MAX_BYTES) {
        return ['error' => 'too_large'];
    }

    akh_paste_cleanup();

    $dir = akh_paste_dir();
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $id = akh_paste_generate_id();
        $path = $dir . '/' . $id . '.json';
        if (is_file($path)) {
            continue;
        }

        $payload = json_encode(
            [
                'content' => $content,
                'created' => time(),
            ],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        if (@file_put_contents($path, $payload, LOCK_EX) !== false) {
            return [
                'id' => $id,
                'url' => rtrim(akh_absolute_url('paste/' . $id), '/'),
            ];
        }
    }

    return ['error' => 'save_failed'];
}

function akh_paste_load(string $id, bool $deleteAfterRead = true): ?string
{
    $id = akh_paste_sanitize_id($id);
    if ($id === null) {
        return null;
    }

    $path = akh_paste_dir() . '/' . $id . '.json';
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }

    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        @unlink($path);

        return null;
    }

    if (!is_array($data) || !isset($data['content'], $data['created'])) {
        @unlink($path);

        return null;
    }

    if (time() - (int) $data['created'] > AKH_PASTE_TTL_SECONDS) {
        @unlink($path);

        return null;
    }

    if ($deleteAfterRead) {
        @unlink($path);
    }

    return (string) $data['content'];
}

function akh_paste_cleanup(): void
{
    $dir = akh_paste_dir();
    $cutoff = time() - AKH_PASTE_TTL_SECONDS;

    foreach (glob($dir . '/*.json') ?: [] as $file) {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            @unlink($file);
            continue;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            @unlink($file);
            continue;
        }

        if (!is_array($data) || !isset($data['created']) || (int) $data['created'] < $cutoff) {
            @unlink($file);
        }
    }
}
