<?php
declare(strict_types=1);

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../util/notification_helper.php';

class OrderRepository
{
        private PDO $pdo;
        private ?bool $hasMinimumShelfLifeColumn = null;

        public function __construct()
        {
            $this->pdo = db();
            $this->ensureMinimumShelfLifeColumn();
        }

        public function createOrder(array $header): int
        {
            $minimumShelfLifeSql = $this->hasMinimumShelfLifeColumn()
                ? ',
                        min_shelf_life_months'
                : '';
            $minimumShelfLifeValueSql = $this->hasMinimumShelfLifeColumn()
                ? ',
                        :min_shelf_life_months'
                : '';

            $sql = "INSERT INTO orders (
                        invoice_no,
                        order_date,
                        delivery_date,
                        customer_code,
                        customer_name,
                        customer_address,
                        order_number,
                        packing_slip,
                        internal_reference,
                        purchase_number,
                        sales_person,
                        rounding_mode
                        {$minimumShelfLifeSql}
                    ) VALUES (
                        :invoice_no,
                        :order_date,
                        :delivery_date,
                        :customer_code,
                        :customer_name,
                        :customer_address,
                        :order_number,
                        :packing_slip,
                        :internal_reference,
                        :purchase_number,
                        :sales_person,
                        :rounding_mode
                        {$minimumShelfLifeValueSql}
                    )";

            $stmt = $this->pdo->prepare($sql);
            $params = [
                ':invoice_no' => (string)($header['invoice_no'] ?? ''),
                ':order_date' => (string)($header['order_date'] ?? ''),
                ':delivery_date' => !empty($header['delivery_date']) ? $header['delivery_date'] : null,
                ':customer_code' => (string)($header['customer_code'] ?? ''),
                ':customer_name' => (string)($header['customer_name'] ?? ''),
                ':customer_address' => (string)($header['customer_address'] ?? ''),
                ':order_number' => (string)($header['order_number'] ?? ''),
                ':packing_slip' => (string)($header['packing_slip'] ?? ''),
                ':internal_reference' => (string)($header['internal_reference'] ?? ''),
                ':purchase_number' => (string)($header['purchase_number'] ?? ''),
                ':sales_person' => (string)($header['sales_person'] ?? ''),
                ':rounding_mode' => isset($header['rounding_mode']) && (string)$header['rounding_mode'] === '1' ? 1 : 0
            ];

            if ($this->hasMinimumShelfLifeColumn()) {
                $params[':min_shelf_life_months'] =
                    (int)($header['min_shelf_life_months'] ?? 6) === 18 ? 18 : 6;
            }

            $stmt->execute($params);

            $orderId = (int)$this->pdo->lastInsertId();

            $invoiceNo = (string)($header['invoice_no'] ?? $orderId);
            $customerName = (string)($header['customer_name'] ?? 'Unknown customer');

            createNotification(
                $this->pdo,
                null,
                'outwards',
                'order',
                'New Order',
                "Order #{$invoiceNo} from {$customerName} has been created.",
                'orders_list.php?status=pending&search=' . urlencode($invoiceNo)
            );

            return $orderId;
        }

    public function insertOrderItem(int $orderId, array $item): void
    {
        $sql = "INSERT INTO order_items (
            order_id,
            sku_code,
            description,
            batch_no,
            picked_batch_no,
            expiry_date,
            order_qty,
            total_qty,
            qty_supplied_per_batch,
            total_qty_supplied,
            qty_supplied,
            units_per_ctn,
            full_ctn,
            ctn_no,
            picked_ctn_no,
            picked_done,
            location,
            comment
        ) VALUES (
            :order_id,
            :sku_code,
            :description,
            :batch_no,
            :picked_batch_no,
            :expiry_date,
            :order_qty,
            :total_qty,
            :qty_supplied_per_batch,
            :total_qty_supplied,
            :qty_supplied,
            :units_per_ctn,
            :full_ctn,
            :ctn_no,
            :picked_ctn_no,
            :picked_done,
            :location,
            :comment
        )";

        $stmt = $this->pdo->prepare($sql);

        $stmt->execute([
            ':order_id' => $orderId,
            ':sku_code' => (string)($item['sku_code'] ?? ''),
            ':description' => (string)($item['description'] ?? ''),
            ':batch_no' => (string)($item['batch_expiry'] ?? $item['batch_no'] ?? ''),
            ':picked_batch_no' => '',
            ':expiry_date' => '',
            ':order_qty' => (string)($item['quantity'] ?? $item['order_qty'] ?? ''),
            ':total_qty' => (string)($item['total_qty'] ?? ''),
            ':qty_supplied' => (string)($item['qty_supplied'] ?? ''),
            ':qty_supplied_per_batch' => (string)($item['qty_supplied_per_batch'] ?? ''),
            ':total_qty_supplied' => (string)($item['total_qty_supplied'] ?? $item['qty_supplied'] ?? ''),
            ':units_per_ctn' => (string)($item['units_per_ctn'] ?? ''),
            ':full_ctn' => (string)($item['no_full_ctn'] ?? $item['full_ctn'] ?? ''),
            ':ctn_no' => (string)($item['ctn_no'] ?? ''),
            ':picked_ctn_no' => '',
            ':picked_done' => '',
            ':location' => (string)($item['location'] ?? ''),
            ':comment' => (string)($item['comment'] ?? '')
        ]);
    }


        public function getAvailableStockBySku(string $sku): array
        {
            $sql = "SELECT
                        Location,
                        SKU_Code,
                        BatchNo,
                        ExpiryDate,
                        TotalQty,
                        QtyPerCtn,
                        Comments
                    FROM productLocation
                    WHERE SKU_Code = :sku
                    AND TotalQty > 0
                    AND SUBSTRING_INDEX(UPPER(TRIM(Location)), '-', 1)
                        NOT IN ('A', 'B', 'C')
                    AND (
                            Comments IS NULL
                            OR TRIM(Comments) = ''
                            OR (
                                UPPER(TRIM(Comments)) NOT LIKE '%DAMAGE%'
                                AND UPPER(TRIM(Comments)) NOT LIKE '%DAMAGED%'
                            )
                    )
                    ORDER BY
                        CASE
                            WHEN Comments IS NOT NULL
                                AND TRIM(Comments) <> ''
                                AND UPPER(TRIM(Comments)) NOT LIKE '%DAMAGE%'
                                AND UPPER(TRIM(Comments)) NOT LIKE '%DAMAGED%'
                            THEN 0
                            ELSE 1
                        END,
                        CASE
                            WHEN Location REGEXP '^[A-Za-z]+-[0-9]+-[0-9]+$'
                            THEN CAST(SUBSTRING_INDEX(Location, '-', -1) AS UNSIGNED)
                            ELSE 9999
                        END ASC,
                        CASE
                            WHEN ExpiryDate IS NULL OR ExpiryDate = '' THEN 1
                            ELSE 0
                        END,
                        ExpiryDate ASC,
                        Location ASC";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':sku' => $sku
            ]);

            return $stmt->fetchAll();
        }

        public function beginTransaction(): void
        {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
            }
        }

        public function commit(): void
        {
            if ($this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        }

        public function rollBack(): void
        {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }
    

    function extractFirstNumeric(string $value): float
    {
        if (preg_match('/-?\d+(?:\.\d+)?/', $value, $m)) {
            return (float)$m[0];
        }
        return 0.0;
    }

    public function updateOrder(int $orderId, array $header): void
    {
        $minimumShelfLifeSql = $this->hasMinimumShelfLifeColumn()
            ? ',
                min_shelf_life_months = :min_shelf_life_months'
            : '';

        $stmt = $this->pdo->prepare("
            UPDATE orders
            SET
                invoice_no = :invoice_no,
                order_date = :order_date,
                delivery_date = :delivery_date,
                customer_code = :customer_code,
                customer_name = :customer_name,
                customer_address = :customer_address,
                order_number = :order_number,
                packing_slip = :packing_slip,
                internal_reference = :internal_reference,
                purchase_number = :purchase_number,
                sales_person = :sales_person,
                rounding_mode = :rounding_mode
                {$minimumShelfLifeSql}
            WHERE id = :id
        ");

        $params = [
            ':invoice_no' => $header['invoice_no'] ?? '',
            ':order_date' => $header['order_date'] ?? null,
            ':delivery_date' => $header['delivery_date'] ?: null,
            ':customer_code' => $header['customer_code'] ?? '',
            ':customer_name' => $header['customer_name'] ?? '',
            ':customer_address' => $header['customer_address'] ?? '',
            ':order_number' => $header['order_number'] ?? '',
            ':packing_slip' => $header['packing_slip'] ?? '',
            ':internal_reference' => $header['internal_reference'] ?? '',
            ':purchase_number' => $header['purchase_number'] ?? '',
            ':sales_person' => $header['sales_person'] ?? '',
            ':rounding_mode' => (string)($header['rounding_mode'] ?? '1') === '1' ? 1 : 0,
            ':id' => $orderId
        ];

        if ($this->hasMinimumShelfLifeColumn()) {
            $params[':min_shelf_life_months'] =
                (int)($header['min_shelf_life_months'] ?? 6) === 18 ? 18 : 6;
        }

        $stmt->execute($params);
    }

    private function hasMinimumShelfLifeColumn(): bool
    {
        if ($this->hasMinimumShelfLifeColumn !== null) {
            return $this->hasMinimumShelfLifeColumn;
        }

        $stmt = $this->pdo->query("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'orders'
              AND COLUMN_NAME = 'min_shelf_life_months'
        ");

        $this->hasMinimumShelfLifeColumn = (int)$stmt->fetchColumn() > 0;

        return $this->hasMinimumShelfLifeColumn;
    }

    private function ensureMinimumShelfLifeColumn(): void
    {
        if ($this->hasMinimumShelfLifeColumn()) {
            return;
        }

        try {
            $this->pdo->exec("
                ALTER TABLE orders
                ADD COLUMN min_shelf_life_months TINYINT UNSIGNED NOT NULL DEFAULT 6
                AFTER rounding_mode
            ");
            $this->hasMinimumShelfLifeColumn = true;
        } catch (Throwable $e) {
            $this->hasMinimumShelfLifeColumn = null;

            if (!$this->hasMinimumShelfLifeColumn()) {
                error_log(
                    'Could not add orders.min_shelf_life_months: ' . $e->getMessage()
                );
            }
        }
    }

    public function deleteOrderItems(int $orderId): void
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM order_items
            WHERE order_id = :order_id
        ");

        $stmt->execute([
            ':order_id' => $orderId
        ]);
    }


public function deleteEditableOrderItems(int $orderId): void
{
    $stmt = $this->pdo->prepare("
        DELETE FROM order_items
        WHERE order_id = :order_id
          AND COALESCE(picked_done, '0') != '1'
    ");

    $stmt->execute([
        ':order_id' => $orderId
    ]);
}

}
