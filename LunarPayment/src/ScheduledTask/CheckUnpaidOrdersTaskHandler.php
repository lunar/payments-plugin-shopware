<?php declare(strict_types=1);

namespace Lunar\Payment\ScheduledTask;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

/**
 * 
 */
#[AsMessageHandler]
class CheckUnpaidOrdersTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        protected EntityRepository $scheduledTaskRepository
    ) {
        $this->scheduledTaskRepository = $scheduledTaskRepository;
    }

    public static function getHandledMessages(): iterable
    {
        return [CheckUnpaidOrdersTask::class];
    }

    public function run(): void
    {
        
    }
}