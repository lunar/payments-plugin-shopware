<?php declare(strict_types=1);

namespace Lunar\Payment\ScheduledTask;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
// use Shopware\Core\Framework\Api\Exception\ExpectationFailedException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;

use Lunar\Lunar as ApiClient;
use Lunar\Payment\Helpers\OrderHelper;
use Lunar\Payment\Helpers\PluginHelper;
use Lunar\Payment\Entity\LunarTransaction\LunarTransaction;

/**
 * 
 */
#[AsMessageHandler(handles: CheckUnpaidOrdersTask::class)]
class CheckUnpaidOrdersTaskHandler extends AbstractCronHandler
{
    private string $paymentMethodCode;


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

            try {

                /** 
                 * Make sure we don't have an authorization/capture for order transaction 
                 * @var LunarTransaction|null $authorizedOrCapturedTransaction
                 */
                $lunarTransactionStates = [OrderHelper::AUTHORIZE, OrderHelper::CAPTURE];
                $authorizedOrCapturedTransaction = $this->filterLunarTransaction($orderId, $lunarTransactionStates, $context);
                
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

                $paymentIntentId = $this->orderHelper->getPaymentIntentFromOrder($order);
                $orderNumber = $order->getOrderNumber();
                $errorKey = "Order number -> $orderNumber";

                if (!$paymentIntentId) {
                    $errors[$errorKey][] = 'No payment intent ID on order.';
                    continue;
                }

                $lunarApiClient = new ApiClient($this->getApiKey($order->getSalesChannelId()));
                $fetchedTransaction = $lunarApiClient->payments()->fetch($paymentIntentId);

                if (!$fetchedTransaction) {
                    $errors[$errorKey][] = 'Fetch API transaction failed: no transaction with provided id: ' . $paymentIntentId;
                    continue;
                }

                if (!isset($fetchedTransaction['authorisationCreated'])) {
                    continue;
                }

                $transactionData = [
                    [
                        'orderId' => $orderId,
                        'orderNumber' => $orderNumber,
                        'transactionId' => $paymentIntentId,
                        'transactionType' => OrderHelper::AUTHORIZE,
                        'transactionCurrency' => $this->orderHelper->getCurrencyCode($order),
                        'orderAmount' => $orderTransaction->amount->getTotalPrice(),
                        'transactionAmount' => $orderTransaction->amount->getTotalPrice(),
                        'paymentMethod' => $this->paymentMethodCode,
                        'createdAt' => date(Defaults::STORAGE_DATE_TIME_FORMAT),
                    ],
                ];

                
                $this->lunarTransactionRepository->create($transactionData, $context);
                
                $checkoutMode = $this->pluginHelper->getSalesChannelConfig('CaptureMode', $this->paymentMethodCode, $order->getSalesChannelId());
                $isInstantMode = 'instant' == $checkoutMode;
                $actionType = $isInstantMode ? OrderHelper::TRANSACTION_PAID : OrderHelper::TRANSACTION_AUTHORIZE;

                /** Changing order transaction state to "authorized" => nothing will happen */
                $this->orderTransactionStateHandler->{$actionType}($orderTransaction->getId(), $context);

                (! $isInstantMode) 
                    ? ($this->orderHelper->changeOrderState($orderId, OrderHelper::TRANSACTION_AUTHORIZED, $context))
                    : null;

                $this->logger->writeLog(['Polling success for order no: ' => $orderNumber], false);

            } catch (\Exception $e) {
                $errors[$errorKey]['General exception'] = $e->getMessage();
                // parse that bellow
            }
        }

        if (!empty($errors)) {            
            $this->logger->writeLog(['CRON ERRORS: ', json_encode($errors)], false);
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