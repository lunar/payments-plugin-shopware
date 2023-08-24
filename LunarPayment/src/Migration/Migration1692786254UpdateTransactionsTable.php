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
        $connection->executeStatement('ALTER TABLE lunar_transaction DROP amount_in_minor;');
        $connection->executeStatement(
            'ALTER TABLE lunar_transaction 
            ADD 
            CONSTRAINT fk_lunar_transaction_order_id
            FOREIGN KEY (order_id) 
            REFERENCES `order`(id)
            ON DELETE CASCADE;
        ');
    }

    /** */
    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
