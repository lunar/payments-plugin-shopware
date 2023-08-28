<?php declare(strict_types=1);

namespace Lunar\Payment\Subscriber;

use Shopware\Core\Defaults;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Api\Exception\ExpectationFailedException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;

use Lunar\Lunar as ApiClient;
use Lunar\Payment\Helpers\OrderHelper;
use Lunar\Payment\Helpers\PluginHelper;
use Lunar\Payment\Helpers\LogHelper as Logger;
use Lunar\Payment\Entity\LunarTransaction\LunarTransaction;

/**
 * Check for unpaid orders
 */
class OrderLoadedSubscriber implements \Symfony\Component\EventDispatcher\EventSubscriberInterface
{
    private bool $isInstantMode = false;
    private string $paymentMethodCode;

    public function __construct(
        private EntityRepository $stateMachineHistory,
        private StateMachineRegistry $stateMachineRegistry,
        private EntityRepository $lunarTransactionRepository,
        private SystemConfigService $systemConfigService,
        private Logger $logger,
        private OrderHelper $orderHelper,
        private PluginHelper $pluginHelper
    ) {
        $this->stateMachineHistory = $stateMachineHistory;
        $this->stateMachineRegistry = $stateMachineRegistry;
        $this->lunarTransactionRepository = $lunarTransactionRepository;
        $this->systemConfigService = $systemConfigService;
        $this->logger = $logger;
        $this->orderHelper = $orderHelper;
        $this->pluginHelper = $pluginHelper;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            OrderEvents::ORDER_LOADED_EVENT => 'checkUnpaidOrders',
        ];
    }

    /**
     * @param \Shopware\Core\Framework\DataAbstractionLayer\Event\EntityLoadedEvent $event
     */
    public function checkUnpaidOrders($event)
    {
        $errors = [];
        $context = $event->getContext();

        /** @var OrderEntity|null $order */
        foreach ($event->getEntities() as $order) {  
            $orderId = $order->getId();

            /** 
             * Order placed long ago are too old, so we don't check them. 
             * We check so far because this is not a true cron, so it depends on a user visiting the site.
             */
            if ($order->getCreatedAt() < new \DateTime("-50 minutes")) {
                continue;
            }

            /** 
             * Make sure we don't have an authorization/capture for order transaction 
             */
            $authorizedOrCapturedTransaction = $this->filterLunarTransaction($orderId, [OrderHelper::AUTHORIZE, OrderHelper::CAPTURE], $context);
            
            if ($authorizedOrCapturedTransaction) {
                continue;
            }

            $orderTransactions = $order->getTransactions();

            if (!$orderTransactions) {
                continue;
            }

            /** @var OrderTransactionEntity|null $transaction */
            $transaction = $orderTransactions->first();

            if ('open' != $transaction->getStateMachineState()->technicalName) {
                continue;
            }

            if (!isset(PluginHelper::LUNAR_PAYMENT_METHODS[$transaction->paymentMethodId])) {
                continue;
            }

            $this->paymentMethodCode = PluginHelper::LUNAR_PAYMENT_METHODS[$transaction->paymentMethodId]['code'];
            $this->isInstantMode = 'instant' == $this->pluginHelper->getSalesChannelConfig(
                'CaptureMode', 
                $this->paymentMethodCode, 
                $order->getSalesChannelId()
            );

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

                if (true != $fetchedTransaction['authorisationCreated']) {
                    continue;
                }

                $totalPrice = $transaction->amount->getTotalPrice();
                $currencyCode = $order->getCurrency()->isoCode;

                $apiTransactionData = [
                    'amount' => [
                        'currency' => $currencyCode,
                        'decimal' => (string) $totalPrice,
                    ],
                ];

                $actionType = OrderHelper::AUTHORIZE;
                $transactionData = [
                    [
                        'orderId' => $orderId,
                        'transactionId' => $paymentIntentId,
                        'transactionType' => $actionType,
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
                
                $this->logger->writeLog(['Polling success:', array_merge(['orderNumber' => $orderNumber], $apiTransactionData)]);

                $this->orderHelper->changeOrderState($orderId, $actionType, $context);

            } catch (\Exception $e) {
                $errors[$orderNumber]['General exception'] = $e->getMessage();
                // parse that bellow
            }
        }

        if (!empty($errors)) {            
            $this->logger->writeLog(['ADMIN ACTION ERRORS: ', json_encode($errors)]);
            throw new ExpectationFailedException($errors);
        }
    }

    /**
     * 
     */
    private function filterLunarTransaction($orderId, $transactionStates, $context)
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
