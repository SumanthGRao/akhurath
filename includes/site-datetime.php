<?php

declare(strict_types=1);

function akh_site_timezone(): DateTimeZone
{
    static $tz = null;
    if ($tz instanceof DateTimeZone) {
        return $tz;
    }

    $name = defined('AKH_SITE_TIMEZONE') ? trim((string) AKH_SITE_TIMEZONE) : 'Asia/Kolkata';
    if ($name === '') {
        $name = 'Asia/Kolkata';
    }

    try {
        $tz = new DateTimeZone($name);
    } catch (Throwable) {
        $tz = new DateTimeZone('Asia/Kolkata');
    }

    return $tz;
}

function akh_datetime_has_timezone(string $raw): bool
{
    $raw = trim($raw);

    return preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $raw) === 1
        || (str_contains($raw, 'T') && preg_match('/[+-]\d{2}:\d{2}/', $raw) === 1);
}

function akh_parse_datetime_to_site(string $raw): ?DateTimeImmutable
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    if (ctype_digit($raw)) {
        $ts = (int) $raw;
        if ($ts > 0) {
            return (new DateTimeImmutable('@' . $ts))->setTimezone(akh_site_timezone());
        }
    }

    if (akh_datetime_has_timezone($raw)) {
        try {
            return (new DateTimeImmutable($raw))->setTimezone(akh_site_timezone());
        } catch (Throwable) {
            // fall through
        }
    }

    // Naive datetimes from DB / WhatsApp bot are already in site local time (IST) — do not treat as UTC.
    $site = akh_site_timezone();
    foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'd-m-Y H:i:s', 'd-m-Y H:i'] as $fmt) {
        $dt = DateTimeImmutable::createFromFormat($fmt, $raw, $site);
        if ($dt instanceof DateTimeImmutable) {
            return $dt;
        }
    }

    foreach ([DateTimeInterface::ATOM, 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s'] as $fmt) {
        $dt = DateTimeImmutable::createFromFormat($fmt, $raw, $site);
        if ($dt instanceof DateTimeImmutable) {
            return $dt;
        }
    }

    try {
        return new DateTimeImmutable($raw, $site);
    } catch (Throwable) {
        return null;
    }
}

function akh_format_datetime_site(string $raw, string $format = 'D, M j, Y · g:i A'): string
{
    if (trim($raw) === '') {
        return '';
    }

    $dt = akh_parse_datetime_to_site($raw);
    if ($dt === null) {
        return $raw;
    }

    return $dt->format($format);
}

function akh_format_datetime_site_short(string $raw): string
{
    return akh_format_datetime_site($raw, 'M j, g:i A');
}
