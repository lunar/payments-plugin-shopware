<?php declare(strict_types=1);

namespace Lunar\Payment\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;

use Lunar\Payment\Helpers\OrderHelper;
use Lunar\Payment\Helpers\PluginHelper;
use Lunar\Payment\Helpers\LogHelper as Logger;

/**
 * 
 */
abstract class AbstractCronHandler extends ScheduledTaskHandler
{
    public function __construct(
        protected EntityRepository $scheduledTaskRepo,
        protected EntityRepository $stateMachineHistory,
        protected StateMachineRegistry $stateMachineRegistry,
        protected EntityRepository $lunarTransactionRepository,
        protected SystemConfigService $systemConfigService,
        protected OrderTransactionStateHandler $orderTransactionStateHandler,
        protected Logger $logger,
        protected OrderHelper $orderHelper,
        protected PluginHelper $pluginHelper
    ) {
        parent::__construct($scheduledTaskRepo);
        
        $this->stateMachineHistory = $stateMachineHistory;
        $this->stateMachineRegistry = $stateMachineRegistry;
        $this->lunarTransactionRepository = $lunarTransactionRepository;
        $this->systemConfigService = $systemConfigService;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->logger = $logger;
        $this->orderHelper = $orderHelper;
        $this->pluginHelper = $pluginHelper;
    }

    public function handle($task): void
    {
        set_error_handler([$this, 'handleError']);

        try {
            parent::handle($task);
        } catch(\Throwable $e) {
            $this->logException($e);
        } finally {
            restore_error_handler();
        }
    }

    public function handleError($code, $message, $file, $line)
    {
        $exception = new \ErrorException($message, $code, E_ERROR, $file, $line);
        $this->logException($exception);
        return true;
    }

    public function logException(\Throwable $e): void
    {
        $message =
            $e->getMessage() . "\n in " .
            $e->getFile() . "\n line " .
            $e->getLine() . "\n" . $e->getTraceAsString();
            
        $this->logger->writeLog($message);
    }
}