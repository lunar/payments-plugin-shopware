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
        $errors = [];
        $context = $event->getContext();

        foreach ($event->getIds() as $transactionId) {

            $transaction = $this->orderHelper->getOrderTransactionById($transactionId, $context);
            /** @var OrderTransactionEntity|null $transaction */
            $order = $transaction->getOrder();
            $orderId = $order->getId();
            $orderNumber = $order->getOrderNumber();
            
            if (!isset(PluginHelper::LUNAR_PAYMENT_METHODS[$transaction->paymentMethodId])) {
                continue;
            }

            $this->paymentMethodCode = PluginHelper::LUNAR_PAYMENT_METHODS[$transaction->paymentMethodId]['code'];

            $transactionTechnicalName = $transaction->getStateMachineState()->technicalName;

            switch ($transactionTechnicalName) {
                case OrderHelper::TRANSACTION_PAID:
                    $actionType = OrderHelper::CAPTURE;
                    $lunarTransactionState = OrderHelper::AUTHORIZE;
                    $transactionExists = $this->filterLunarTransaction($orderId, OrderHelper::CAPTURE, $context);
                    break;
                case OrderHelper::TRANSACTION_REFUNDED:
                    $actionType = OrderHelper::REFUND;
                    $lunarTransactionState = OrderHelper::CAPTURE;
                    $transactionExists = $this->filterLunarTransaction($orderId, OrderHelper::REFUND, $context);
                    break;
                case OrderHelper::TRANSACTION_CANCELLED:
                    $actionType = OrderHelper::CANCEL;
                    $lunarTransactionState = OrderHelper::AUTHORIZE;
                    $transactionExists = $this->filterLunarTransaction($orderId, OrderHelper::CANCEL, $context);
                    break;
                default:
                    // skip parent loop
                    continue 2;
            }

            if ($transactionExists) {
                continue;
            }
            
            /** @var LunarTransaction|null $previousLunarTransaction */
            $previousLunarTransaction = $this->filterLunarTransaction($orderId, $lunarTransactionState, $context);

            if (!$previousLunarTransaction) {
                continue;
            }

            $lunarTransactionId = $previousLunarTransaction->getTransactionId();

            try {

                $apiClient = new ApiClient($this->getApiKey($order));
                $fetchedTransaction = $apiClient->payments()->fetch($lunarTransactionId);

                if (!$fetchedTransaction) {
                    $errors[$orderNumber][] = 'Fetch API transaction failed: no transaction with provided id: ' . $transactionId;
                    continue;
                }

                $totalPrice = $transaction->amount->getTotalPrice();
                $currencyCode = $transaction->getOrder()->getCurrency()->isoCode;

                $apiTransactionData = [
                    'amount' => [
                        'currency' => $currencyCode,
                        'decimal' => (string) $totalPrice,
                    ],
                ];

                $apiResult = $apiClient->payments()->{$actionType}($lunarTransactionId, $apiTransactionData);

                $this->logger->writeLog([strtoupper($actionType) . ' request data: ', $apiTransactionData]);

                if ('completed' !== $apiResult["{$actionType}State"]) {
                    $errors[$orderNumber][] = 'Transaction API action was unsuccesfull';
                    continue;
                }

                $this->lunarTransactionRepository->create([
                    [
                        'orderId' => $orderId,
                        'transactionId' => $lunarTransactionId,
                        'transactionType' => $actionType,
                        'transactionCurrency' => $currencyCode,
                        'orderAmount' => $totalPrice,
                        'transactionAmount' => $totalPrice,
                        'paymentMethod' => $this->paymentMethodCode,
                        'createdAt' => date(Defaults::STORAGE_DATE_TIME_FORMAT),
                    ],
                ], $context);
                
                $this->logger->writeLog(['Succes:', array_merge(['orderNumber' => $orderNumber, 'action' => $actionType], $apiTransactionData)]);

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
