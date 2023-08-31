<?php declare(strict_types=1);

namespace Lunar\Payment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;


class Migration1692786254UpdateTransactionsTable extends MigrationStep
{
    /** */
    public function getCreationTimestamp(): int
    {
        return 1692786254;
    }

    /**
     *
     */
    public function update(Connection $connection): void
    {
        $connection->executeStatement('ALTER TABLE lunar_transaction ADD payment_method VARCHAR(255) NOT NULL AFTER transaction_amount');

        $connection->executeStatement('ALTER TABLE lunar_transaction ADD order_number VARCHAR(64) NOT NULL AFTER order_id');

        $connection->executeStatement('ALTER TABLE lunar_transaction DROP amount_in_minor;');
    }

    /**
     * 
     */
    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
