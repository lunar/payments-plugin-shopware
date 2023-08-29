<?php declare(strict_types=1);

namespace Lunar\Payment\ScheduledTask;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
/**
 * 
 */
#[AsMessageHandler]
class CheckUnpaidOrdersTaskHandler extends ScheduledTaskHandler
{
    public static function getHandledMessages(): iterable
    {
        return [CheckUnpaidOrdersTask::class];
    }

    public function run(): void
    {
        
    }
}