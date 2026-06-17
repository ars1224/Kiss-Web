<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/session.php';

if (!isLoggedIn()) {
    http_response_code(401);
    exit('Authentication required');
}

/**
 * Shared helpers for simple POST-based actions (Location, pallets, etc.)
 */

/**
 * Sanitize and validate a list of integer IDs from POST.
 *
 * @param string $key The POST key, e.g. 'EntryID'
 * @return int[]      A non-empty array of positive integer IDs
 */
function require_ids(string $key = 'EntryID'): array
{
    $raw = $_POST[$key] ?? null;

    if (!is_array($raw) || !$raw) {
        http_response_code(400);
        exit('No rows selected');
    }

    // Cast to int, unique, and keep only > 0
    $ids = array_values(array_unique(array_map(
        static fn($v): int => (int)$v,
        $raw
    )));

    $ids = array_values(array_filter(
        $ids,
        static fn(int $id): bool => $id > 0
    ));

    if (!$ids) {
        http_response_code(400);
        exit('Invalid IDs');
    }

    return $ids;
}

/**
 * Normalize an expiry string to MM/YYYY or fail with HTTP 422.
 *
 * @param string|null $value      Raw value like '3/2027' or '03/2027'
 * @param bool        $allowEmpty If true, empty returns null instead of failing
 * @return string|null            Normalized 'MM/YYYY' or null
 */
function validate_expiry(?string $value, bool $allowEmpty = false): ?string
{
    $value = trim((string)($value ?? ''));

    if ($value === '') {
        if ($allowEmpty) {
            return null;
        }
        http_response_code(422);
        exit('ExpiryDate is required');
    }

    if (!preg_match('/^(0?[1-9]|1[0-2])\/\d{4}$/', $value)) {
        http_response_code(422);
        exit('Invalid ExpiryDate');
    }

    // Normalize to MM/YYYY
    return preg_replace_callback(
        '/^(\d{1,2})\/(\d{4})$/',
        static fn(array $m): string => sprintf('%02d/%s', (int)$m[1], $m[2]),
        $value
    );
}

/**
 * Safe integer cast with a minimum.
 *
 * @param mixed $value The input
 * @param int   $min   Minimum allowed
 */
function int_or_min($value, int $min = 0): int
{
    $n = (int)($value ?? 0);
    return $n < $min ? $min : $n;
}

function get_param_string(string $key, string $default = ''): string {
    $value = $_GET[$key] ?? $_POST[$key] ?? $default;
    return trim((string)$value);
}
