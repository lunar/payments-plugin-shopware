<?php declare(strict_types=1);

namespace Lunar\Payment\ScheduledTask;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
// use Shopware\Core\Framework\Api\Exception\ExpectationFailedException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;

use Lunar\Lunar as ApiClient;
use Lunar\Payment\Helpers\OrderHelper;
use Lunar\Payment\Helpers\PluginHelper;
use Lunar\Payment\Helpers\LogHelper as Logger;
use Lunar\Payment\Entity\LunarTransaction\LunarTransaction;

/**
 * 
 */
#[AsMessageHandler]
class CheckUnpaidOrdersTaskHandler extends ScheduledTaskHandler
{
    private bool $isInstantMode = false;
    private string $paymentMethodCode;


    public function __construct(
        protected EntityRepository $scheduledTaskRepo,
        private EntityRepository $stateMachineHistory,
        private StateMachineRegistry $stateMachineRegistry,
        private EntityRepository $lunarTransactionRepository,
        private SystemConfigService $systemConfigService,
        private OrderTransactionStateHandler $orderTransactionStateHandler,
        private Logger $logger,
        private OrderHelper $orderHelper,
        private PluginHelper $pluginHelper
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


    public static function getHandledMessages(): iterable
    {
        return [CheckUnpaidOrdersTask::class];
    }

    public function run(): void
    {
        $errors = [];

        $this->logger->writeLog('Start Lunar polling');

        /** 
         * Placed orders older than 1 day are too old, so we don't check them.
         */
        $orders = $this->orderHelper->getOrdersFrom(new \DateTimeImmutable("-24 hours"));
        $context = Context::createDefaultContext();

        /** @var OrderEntity $order */
        foreach ($orders as $order) {  
            $orderId = $order->getId();

            /** 
             * Make sure we don't have an authorization/capture for order transaction 
             * @var LunarTransaction|null $authorizedOrCapturedTransaction
             */
            $authorizedOrCapturedTransaction = $this->filterLunarTransaction($orderId, [OrderHelper::AUTHORIZE, OrderHelper::CAPTURE], $context);
            
            if ($authorizedOrCapturedTransaction) {
                continue;
            }

            $orderTransactions = $order->getTransactions();

            /** @var OrderTransactionEntity|null $orderTransaction */
            $orderTransaction = $orderTransactions->first();

            if (!isset(PluginHelper::LUNAR_PAYMENT_METHODS[$orderTransaction->paymentMethodId])) {
                continue;
            }

            $this->paymentMethodCode = PluginHelper::LUNAR_PAYMENT_METHODS[$orderTransaction->paymentMethodId]['code'];

            $checkoutMode = $this->pluginHelper->getSalesChannelConfig('CaptureMode', $this->paymentMethodCode, $order->getSalesChannelId());
            $this->isInstantMode = 'instant' == $checkoutMode;

            $paymentIntentId = $this->orderHelper->getPaymentIntentFromOrder($order);
            $orderNumber = $order->getOrderNumber();

            try {
                /**
                 * Instantiate Api Client
                 * Fetch transaction and make api action
                 * Change order transaction payment state
                 */
                $lunarApiClient = new ApiClient($this->getApiKey($order->getSalesChannelId()));
                $fetchedTransaction = $lunarApiClient->payments()->fetch($paymentIntentId);

                if (!$fetchedTransaction) {
                    $errors[$orderNumber][] = 'Fetch API transaction failed: no transaction with provided id: ' . $paymentIntentId;
                    continue;
                }

                if (!isset($fetchedTransaction['authorisationCreated'])) {
                    continue;
                }

                $totalPrice = $orderTransaction->amount->getTotalPrice();
                $currencyCode = $this->orderHelper->getCurrencyCode($order);

                $apiTransactionData = [
                    'amount' => [
                        'currency' => $currencyCode,
                        'decimal' => (string) $totalPrice,
                    ],
                ];

                $actionType = OrderHelper::TRANSACTION_AUTHORIZED;

                $transactionData = [
                    [
                        'orderId' => $orderId,
                        'transactionId' => $paymentIntentId,
                        'transactionType' => OrderHelper::AUTHORIZE,
                        'transactionCurrency' => $currencyCode,
                        'orderAmount' => $totalPrice,
                        'transactionAmount' => $totalPrice,
                        'paymentMethod' => $this->paymentMethodCode,
                        'createdAt' => date(Defaults::STORAGE_DATE_TIME_FORMAT),
                    ],
                ];

                if ($this->isInstantMode) {
                    $apiResult = $lunarApiClient->payments()->capture($paymentIntentId, $apiTransactionData);

                    $this->logger->writeLog(['Capture request data (observer): ', $apiTransactionData]);

                    if ('completed' !== $apiResult["captureState"]) {
                        $errors[$orderNumber][] = 'Transaction API action was unsuccesfull';
                        continue;
                    }

                    $transactionData[0]['transactionType'] = OrderHelper::CAPTURE;
                    $actionType = OrderHelper::TRANSACTION_PAID; 
                }

                $this->lunarTransactionRepository->create($transactionData, $context);

                $this->orderTransactionStateHandler->{$actionType}($orderTransaction->getId(), $context);

                $this->orderHelper->changeOrderState($orderId, $actionType, $context);
                
                $this->logger->writeLog(['Polling success:', array_merge(['orderNumber' => $orderNumber], $apiTransactionData)], false);

            } catch (\Exception $e) {
                $errors[$orderNumber]['General exception'] = $e->getMessage();
                // parse that bellow
            }
        }

        if (!empty($errors)) {            
            $this->logger->writeLog(['ADMIN ACTION ERRORS: ', json_encode($errors)]);
            // throw new ExpectationFailedException($errors);
            throw new \Exception(json_encode($errors));
        }
    }

    /**
     * 
     */
    private function filterLunarTransaction($orderId, $transactionStates, $context): ?LunarTransaction
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        $criteria->addFilter(new OrFilter([
            new EqualsFilter('transactionType',  $transactionStates[0]),
            new EqualsFilter('transactionType',  $transactionStates[1]),
        ]));

        return $this->lunarTransactionRepository->search($criteria, $context)->first();
    }

    /**
     *
     */
    private function getApiKey($salesChannelId)
    {
        $transactionMode = $this->pluginHelper->getSalesChannelConfig('TransactionMode', $this->paymentMethodCode, $salesChannelId);

        if ($transactionMode == 'test') {
            return $this->pluginHelper->getSalesChannelConfig('TestModeAppKey', $this->paymentMethodCode, $salesChannelId);
        }

        return $this->pluginHelper->getSalesChannelConfig('LiveModeAppKey', $this->paymentMethodCode, $salesChannelId);
    }
}