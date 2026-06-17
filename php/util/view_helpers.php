<?php
declare(strict_types=1);

/**
 * Returns a class name based on expiry status.
 *
 * Used in tables:
 *   <tr class="<?= expiry_class($row['ExpiryDate']) ?>">
 */
function expiry_class(?string $exp): string
{
    if (!$exp) return '';

    if (!preg_match('/^(0[1-9]|1[0-2])\/\d{4}$/', $exp)) {
        return ''; // invalid format → no color
    }

    [$mm, $yy] = explode('/', $exp);
    $expDate = strtotime("{$yy}-{$mm}-01");
    $now     = strtotime('first day of this month');

    if ($expDate < $now) return 'expired';
    if ($expDate === $now) return 'expiring-soon';

    return '';
}

/**
 * Formats location code safely (uppercase, no extra spacing)
 */
function fmt_loc(?string $loc): string
{
    return strtoupper(trim((string)$loc));
}

/**
 * Formats quantity with commas
 */
function fmt_qty(int $qty): string
{
    return number_format($qty);
}
