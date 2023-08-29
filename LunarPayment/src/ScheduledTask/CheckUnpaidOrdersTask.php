<?php declare(strict_types=1);

namespace Lunar\Payment\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * 
 */
class CheckUnpaidOrdersTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'lunar_payment.check_unpaid_orders';
    }

    public static function getDefaultInterval(): int
    {
        return 1200; // 20 minutes
    }
}