<?php declare(strict_types=1);

namespace Lunar\Payment\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

use Lunar\Payment\Helpers\PluginHelper;

/**
 * 
 */
class CheckUnpaidOrdersTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return PluginHelper::TASK_SCHEDULER_NAME;
    }

    public static function getDefaultInterval(): int
    {
        return 1200; // 20 minutes
    }
}