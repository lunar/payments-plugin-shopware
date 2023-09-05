<?php declare(strict_types=1);

namespace Lunar\Payment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;


class Migration1655746819CreateTransactionsTable extends MigrationStep
{
    /** */
    public function getCreationTimestamp(): int
    {
        return 1655746819;
    }

    /**
     *
     */
    public function update(Connection $connection): void
    {
        $sql = '
            CREATE TABLE IF NOT EXISTS lunar_transaction (
                `id` BINARY(16) NOT NULL PRIMARY KEY,
                `order_id` BINARY(16) NOT NULL,
                `transaction_id` VARCHAR(50) NOT NULL,
                `transaction_type` CHAR(20) NOT NULL,
                `transaction_currency` CHAR(5) NOT NULL,
                `order_amount` DECIMAL(15,4) NOT NULL,
                `transaction_amount` DECIMAL(15,4) NOT NULL,
                `amount_in_minor` INT UNSIGNED NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ';

        $connection->executeStatement($sql);
    }

    /** */
    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
