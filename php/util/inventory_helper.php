<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/session.php';

function currentInventoryType(): string
{
    $role = strtolower(trim(currentUserRole()));

    // ADMIN CAN SWITCH INVENTORY USING ?inventory=
    if ($role === 'admin') {

        $requested = strtolower(trim(
            $_GET['inventory']
            ?? $_POST['inventory']
            ?? ''
        ));

        return match ($requested) {
            'components',
            'component',
            'packaging'
                => 'packaging',

            'rm',
            'raw',
            'rawmat',
            'raw_materials'
                => 'rm',

            default
                => 'products',
        };
    }

    // NORMAL ROLE-BASED INVENTORY
    return match ($role) {

        'inwards'
            => 'packaging',

        'rawmat'
            => 'rm',

        default
            => 'products',
    };
}
function inventoryTable(): string
{
    $role = strtolower(trim(currentUserRole()));

  if ($role === 'admin' && empty($_GET['inventory']) && empty($_POST['inventory'])) {
    return 'all';
}

    return match (currentInventoryType()) {
        'packaging' => 'componentlocation',
        'rm'        => 'rmlocation',
        default     => 'productlocation',
    };
}

function masterTable(): string
{
    return match (currentInventoryType()) {
        'packaging' => 'components',
        'rm'        => 'raw_materials',
        default     => 'products',
    };
}

function statusTable(): string
{
    return match (currentInventoryType()) {
        'packaging' => 'component_status',
        'rm'        => 'rm_status',
        default     => 'product_status',
    };
}

function inventoryType(): string
{
    return currentInventoryType();
}