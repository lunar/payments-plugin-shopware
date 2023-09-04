<?php declare(strict_types=1);

namespace Lunar\Payment\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use Shopware\Core\Defaults;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Api\Exception\ExceptionFailedException;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;

use Lunar\Payment\lib\ApiClient;
use Lunar\Payment\Helpers\OrderHelper;
use Lunar\Payment\Helpers\PluginHelper;
use Lunar\Payment\Helpers\CurrencyHelper;
use Lunar\Payment\Helpers\LogHelper as Logger;

/**
 * Manage payment actions on order transaction state change
 * It works on single or bulk order transaction edit.
 */
class OrderTransactionStateChangeSubscriber implements EventSubscriberInterface
{
    public const CONFIG_PATH = PluginHelper::PLUGIN_CONFIG_PATH;

    /** @var EntityRepository */
    private $stateMachineHistory;

    /** @var StateMachineRegistry */
    private $stateMachineRegistry;

    /** @var EntityRepository */
    private $lunarTransactionRepository;

    /** @var OrderHelper */
    private $orderHelper;

    /** @var SystemConfigService */
    private $systemConfigService;

    /** @var Logger */
    private $logger;

    /** @var OrderTransactionEntity */
    private $orderTransaction;

    public function __construct(
        EntityRepository $stateMachineHistory,
        StateMachineRegistry $stateMachineRegistry,
        EntityRepository $lunarTransactionRepository,
        OrderHelper $orderHelper,
        SystemConfigService $systemConfigService,
        Logger $logger
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
     * @TODO unify code with that from \Controller\OrderTransactionController.php
     *
     * @param EntityWrittenEvent $event
     */
    public function makePaymentTransaction(EntityWrittenEvent $event)
    {
        $context = $event->getContext();
        $isAdminAction = false;
        $errors = [];

        foreach ($event->getIds() as $transactionId) {
            try {
                $transaction = $this->orderTransaction = $this->orderHelper->getTransactionById($transactionId, $context);

                /**
                 * Check payment method
                 */
                if (PluginHelper::PAYMENT_METHOD_UUID !== $transaction->paymentMethodId) {
                    continue;
                }

                $transactionTechnicalName = $transaction->getStateMachineState()->technicalName;

                /** Defaults to authorize. */
                $dbTransactionPreviousState = OrderHelper::AUTHORIZE_STATUS;

                /**
                 * Check order transaction state sent
                 * Map statuses based on shopware actions (transaction state sent)
                 */
                switch ($transactionTechnicalName) {
                    case OrderHelper::TRANSACTION_PAID:
                        $actionType = OrderHelper::CAPTURE_STATUS;
                        $dbTransactionPreviousState = OrderHelper::AUTHORIZE_STATUS;
                        $transactionType = OrderHelper::CAPTURE_STATUS;
                        $amountToCheck = 'capturedAmount';
                        break;
                    case OrderHelper::TRANSACTION_REFUNDED:
                        $actionType = OrderHelper::REFUND_STATUS;
                        $dbTransactionPreviousState = OrderHelper::CAPTURE_STATUS;
                        $transactionType = OrderHelper::REFUND_STATUS;
                        $amountToCheck = 'refundedAmount';
                        break;
                    case OrderHelper::TRANSACTION_VOIDED:
                        $actionType = OrderHelper::VOID_STATUS;
                        $dbTransactionPreviousState = OrderHelper::AUTHORIZE_STATUS;
                        $transactionType = OrderHelper::VOID_STATUS;
                        $amountToCheck = 'voidedAmount';
                        break;
                }

                /**
                 * Check transaction registered in custom table
                 */
                $criteria = new Criteria();
                $order = $transaction->getOrder();
                $orderId = $order->getId();
                $criteria->addFilter(new EqualsFilter('orderId', $orderId));
                $criteria->addFilter(new EqualsFilter('transactionType',  $dbTransactionPreviousState));

                $paymentTransaction = $this->lunarTransactionRepository->search($criteria, $context)->first();

                if (!$paymentTransaction) {
                    continue;
                }

                /** If arrive here, then it is an admin action. */
                $isAdminAction = true;

                $paymentTransactionId = $paymentTransaction->getTransactionId();

                /**
                 * Instantiate Api Client
                 * Fetch transaction
                 * Check amount & currency
                 * Proceed with transaction action
                 */
                $privateApiKey = $this->getApiKey($order);
                $apiClient = new ApiClient($privateApiKey);
                $fetchedTransaction = $apiClient->transactions()->fetch($paymentTransactionId);

                if (!$fetchedTransaction) {
                    $errors[$transactionId][] = 'Fetch API transaction failed: no transaction with provided id';
                    continue;
                }

                $totalPrice = $transaction->amount->getTotalPrice();
                $currencyCode = $transaction->getOrder()->getCurrency()->isoCode;
                $amountValue = (int) CurrencyHelper::getAmountInMinor($currencyCode, $totalPrice);


                $transactionData = [
                    'amount' => $amountValue,
                    'currency' => $currencyCode,
                ];

                $result['successful'] = false;

                /**
                 * Make capture/refund/void only if not made previously
                 * Prevent double transaction on
                 */
                if (
                    $this->isCaptureAction()
                    && $fetchedTransaction[$amountToCheck] === 0
                    && $fetchedTransaction['pendingAmount'] !== 0
                ) {
                    /**
                     * Capture.
                     */
                    $result = $apiClient->transactions()->capture($paymentTransactionId, $transactionData);

                } elseif (
                    $this->isRefundAction()
                    && $fetchedTransaction[$amountToCheck] === 0
                    && $fetchedTransaction['capturedAmount'] !== 0
                ) {
                    /**
                     * Refund.
                     */
                    $result = $apiClient->transactions()->refund($paymentTransactionId, $transactionData);

                } elseif (
                    $this->isVoidAction()
                    && $fetchedTransaction[$amountToCheck] === 0
                    && $fetchedTransaction['pendingAmount'] !== 0
                ) {
                    /**
                     * Void.
                     */
                    $result = $apiClient->transactions()->void($paymentTransactionId, $transactionData);

                } else  {
                    continue;
                }

                $this->logger->writeLog([strtoupper($transactionTechnicalName) . ' request data: ', $transactionData]);

                if (true !== $result['successful']) {
                    $this->logger->writeLog(['Error: ', $result]);
                    $errors[$transactionId][] = 'Transaction API action was unsuccesfull';
                    continue;
                }

                $transactionAmount = CurrencyHelper::getAmountInMajor($currencyCode, $result[$amountToCheck]);

                $transactionData = [
                    [
                        'orderId' => $orderId,
                        'transactionId' => $paymentTransactionId,
                        'transactionType' => $transactionType,
                        'transactionCurrency' => $currencyCode,
                        'orderAmount' => $totalPrice,
                        'transactionAmount' => $transactionAmount,
                        'amountInMinor' => $result[$amountToCheck],
                        'createdAt' => date(Defaults::STORAGE_DATE_TIME_FORMAT),
                    ],
                ];

                /** Insert new data to database and log it. */
                $this->lunarTransactionRepository->create($transactionData, $context);

                $this->logger->writeLog(['Succes: ', $transactionData[0]]);

                /** Change order state. */
                OrderHelper::changeOrderState($orderId, $actionType, $context, $this->stateMachineRegistry);

            } catch (\Exception $e) {
                $errors[] = $e->getMessage();
            }
        }

        if (!empty($errors) && $isAdminAction) {
            /**
             * Revert order transaction to previous state
             */
            foreach ($errors as $transactionIdKey => $errorMessages) {
                $criteria = new Criteria();
                $criteria->addFilter(new EqualsFilter('transactionId', $transactionIdKey));
                $criteria->addFilter(new EqualsFilter('transactionType',  $dbTransactionPreviousState));

                $paymentTransaction = $this->lunarTransactionRepository->search($criteria, $context)->first();

                // $this->stateMachineHistory->
            }

            $this->logger->writeLog(['ADMIN ACTION ERRORS: ', json_encode($errors)]);
            throw new ExceptionFailedException($errors);
        }
    }

    /**
     *
     */
    private function getApiKey($order)
    {
        $salesChannelId = $order->getSalesChannelId();

        $transactionMode = $this->systemConfigService->get(self::CONFIG_PATH . 'transactionMode', $salesChannelId);

        if ($transactionMode == 'test') {
            return $this->systemConfigService->get(self::CONFIG_PATH . 'testModeAppKey', $salesChannelId);
        }

        return $this->systemConfigService->get(self::CONFIG_PATH . 'liveModeAppKey', $salesChannelId);
    }

    /**
     *
     */
    private function isCaptureAction(): bool
    {
        return OrderHelper::TRANSACTION_PAID === $this->orderTransaction->getStateMachineState()->technicalName;
    }

    /**
     *
     */
    private function isRefundAction(): bool
    {
        return OrderHelper::TRANSACTION_REFUNDED === $this->orderTransaction->getStateMachineState()->technicalName;
    }

    /**
     *
     */
    private function isVoidAction(): bool
    {
        return OrderHelper::TRANSACTION_VOIDED === $this->orderTransaction->getStateMachineState()->technicalName;
    }
}
