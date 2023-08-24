<?php declare(strict_types=1);

namespace Lunar\Payment\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use Shopware\Core\Defaults;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Api\Exception\ExpectationFailedException;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;

use Lunar\Lunar as ApiClient;
use Lunar\Payment\Helpers\OrderHelper;
use Lunar\Payment\Helpers\PluginHelper;
use Lunar\Payment\Helpers\LogHelper as Logger;
use Lunar\Payment\Entity\LunarTransaction\LunarTransaction;

/**
 * Manage payment actions on order transaction state change
 * It works on single or bulk order transaction edit.
 */
class OrderTransactionStateChangeSubscriber implements EventSubscriberInterface
{
    private const CONFIG_PATH = PluginHelper::PLUGIN_CONFIG_PATH;

    private string $paymentMethodCode;

    public function __construct(
        private EntityRepository $stateMachineHistory,
        private StateMachineRegistry $stateMachineRegistry,
        private EntityRepository $lunarTransactionRepository,
        private OrderHelper $orderHelper,
        private SystemConfigService $systemConfigService,
        private Logger $logger
    ) {
        $this->stateMachineHistory = $stateMachineHistory;
        $this->stateMachineRegistry = $stateMachineRegistry;
        $this->lunarTransactionRepository = $lunarTransactionRepository;
        $this->orderHelper = $orderHelper;
        $this->systemConfigService = $systemConfigService;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            OrderEvents::ORDER_TRANSACTION_WRITTEN_EVENT => 'makePaymentTransaction',
        ];
    }

    /**
     * @param EntityWrittenEvent $event
     */
    public function makePaymentTransaction(EntityWrittenEvent $event)
    {
        $context = $event->getContext();
        $isAdminAction = false;
        $errors = [];

        foreach ($event->getIds() as $transactionId) {
            try {
                $transaction = $this->orderHelper->getOrderTransactionById($transactionId, $context);
                /** @var OrderTransactionEntity $transaction */
                $order = $transaction->getOrder();
                $orderId = $order->getId();
             
                /**
                 * Check payment method
                 */
                if (!isset(PluginHelper::LUNAR_PAYMENT_METHODS[$transaction->paymentMethodId])) {
                    continue;
                }

                $this->paymentMethodCode = PluginHelper::LUNAR_PAYMENT_METHODS[$transaction->paymentMethodId]['code'];

                $transactionTechnicalName = $transaction->getStateMachineState()->technicalName;
                // $dbTransactionPreviousState = '';

                /**
                 * Check order transaction state sent
                 * Map statuses based on shopware actions (transaction state sent)
                 */
                switch ($transactionTechnicalName) {
                    case OrderHelper::TRANSACTION_PAID:
                        $actionType = OrderHelper::CAPTURE;
                        $lunarTransactionState = OrderHelper::AUTHORIZE;
                        // $transactionExists = $this->filterLunarTransaction($orderId, OrderHelper::CAPTURE, $context);
                        break;
                    case OrderHelper::TRANSACTION_REFUNDED:
                        $actionType = OrderHelper::REFUND;
                        $lunarTransactionState = OrderHelper::CAPTURE;
                        // $transactionExists = $this->filterLunarTransaction($orderId, OrderHelper::REFUND, $context);
                        break;
                    case OrderHelper::TRANSACTION_CANCELLED:
                        $actionType = OrderHelper::CANCEL;
                        $lunarTransactionState = OrderHelper::AUTHORIZE;
                        // $transactionExists = $this->filterLunarTransaction($orderId, OrderHelper::CANCEL, $context);
                        break;
                    default:
                        // skip parent loop
                        continue 2;
                }

                // if ($transactionExists) {
                //     continue;
                // }
                
                /** @var LunarTransaction $previousLunarTransaction */
                $previousLunarTransaction = $this->filterLunarTransaction($orderId, $lunarTransactionState, $context);

                if (!$previousLunarTransaction) {
                    continue;
                }

                /** If arrived here, then it is an admin action. */
                $isAdminAction = true;

                $lunarTransactionId = $previousLunarTransaction->getTransactionId();

                /**
                 * Instantiate Api Client
                 * Fetch transaction
                 * Proceed with transaction action
                 */
                $privateApiKey = $this->getApiKey($order);
                $apiClient = new ApiClient($privateApiKey);
                $fetchedTransaction = $apiClient->payments()->fetch($lunarTransactionId);

                if (!$fetchedTransaction) {
                    $errors[$transactionId][] = 'Fetch API transaction failed: no transaction with provided id';
                    continue;
                }

                $totalPrice = $transaction->amount->getTotalPrice();
                $currencyCode = $transaction->getOrder()->getCurrency()->isoCode;

                $transactionData = [
                    'amount' => [
                        'currency' => $currencyCode,
                        'decimal' => (string) $totalPrice,
                    ],
                ];

                /**
                 * Make capture/refund/cancel only if not made previously
                 * Prevent double transaction
                 */
                $result = $apiClient->payments()->{$actionType}($lunarTransactionId, $transactionData);

                $this->logger->writeLog([strtoupper($transactionTechnicalName) . ' request data: ', $transactionData]);

                if ('completed' !== $result["{$actionType}State"]) {
                    $this->logger->writeLog(['Error: ', $result]);
                    $errors[$transactionId][] = 'Transaction API action was unsuccesfull';
                    continue;
                }

                $transactionData = [
                    [
                        'orderId' => $orderId,
                        'transactionId' => $lunarTransactionId,
                        'transactionType' => $actionType,
                        'transactionCurrency' => $currencyCode,
                        'orderAmount' => $totalPrice,
                        'transactionAmount' => $totalPrice,
                        'createdAt' => date(Defaults::STORAGE_DATE_TIME_FORMAT),
                    ],
                ];

                /** Change order state. */
                $this->orderHelper->changeOrderState($orderId, $actionType, $context);

                /** Insert new data to custom table. */
                $this->lunarTransactionRepository->create($transactionData, $context);
                
                $this->logger->writeLog(['Succes: ', $transactionData[0]]);

            } catch (\Exception $e) {
                $errors[] = $e->getMessage();
            }
        }

        if (!empty($errors) && $isAdminAction) {
            /**
             * Revert order transaction to previous state
             */
            // foreach ($errors as $transactionIdKey => $errorMessages) {
            //     $criteria = new Criteria();
            //     $criteria->addFilter(new EqualsFilter('transactionId', $transactionIdKey));
            //     $criteria->addFilter(new EqualsFilter('transactionType',  $lunarTransactionState));

            //     $lunarTransaction = $this->lunarTransactionRepository->search($criteria, $context)->first();

            //     // $this->stateMachineHistory->
            // }
            
            $this->logger->writeLog(['ADMIN ACTION ERRORS: ', json_encode($errors)]);
            throw new ExpectationFailedException($errors);
        }
    }


    private function filterLunarTransaction($orderId, $lunarTransactionState, $context)
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        $criteria->addFilter(new EqualsFilter('transactionType',  $lunarTransactionState));

        return $this->lunarTransactionRepository->search($criteria, $context)->first();
    }


    /**
     *
     */
    private function getApiKey($order)
    {
        $salesChannelId = $order->getSalesChannelId();

        $configPath = self::CONFIG_PATH . $this->paymentMethodCode;

        $transactionMode = $this->systemConfigService->get($configPath . 'TransactionMode', $salesChannelId);

        if ($transactionMode == 'test') {
            return $this->systemConfigService->get($configPath . 'TestModeAppKey', $salesChannelId);
        }

        return $this->systemConfigService->get($configPath . 'LiveModeAppKey', $salesChannelId);
    }

}
