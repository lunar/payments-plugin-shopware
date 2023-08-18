<?php declare(strict_types=1);

namespace Lunar\Payment\Service;

// use Psr\Log\LoggerInterface;

use Shopware\Core\Checkout\Cart\CartException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

use Lunar\Lunar as ApiClient;
use Lunar\Payment\Helpers\OrderHelper;
use Lunar\Payment\Helpers\PluginHelper;
use Lunar\Payment\Helpers\CurrencyHelper;
use Lunar\Payment\Helpers\LogHelper as Logger;
use Lunar\Payment\Exception\TransactionException;


class LunarHostedCheckoutHandler implements AsynchronousPaymentHandlerInterface
{
    private const REMOTE_URL = 'https://pay.lunar.money/?id=';
    private const TEST_REMOTE_URL = 'https://hosted-checkout-git-develop-lunar-app.vercel.app/?id=';

    private const FINALIZED_ORDER_TRANSACTION_STATES = [
        OrderTransactionStates::STATE_PAID,
        OrderTransactionStates::STATE_AUTHORIZED,
    ];

    private string $shopwareVersion = '';

    private ApiClient $lunarApiClient;
    private OrderEntity $order;
    private SalesChannelContext $salesChannelContext;
    private OrderTransactionEntity $orderTransaction;
    
    private ?string $salesChannelId = null;
    private string $intentIdKey = '_lunar_intent_id';
    private bool $isInstantMode = false;
    private array $args = [];
    private string $paymentIntentId = '';
    private bool $testMode = false;
    private string $publicKey = '';


    public function __construct(
        // private LoggerInterface $logger,
        private Logger $logger,
        private SystemConfigService $systemConfigService,
        private OrderTransactionStateHandler $orderTransactionStateHandler,
        private EntityRepository $stateMachineStateRepository,
        string $shopwareVersion
    ) {
        $this->logger = $logger;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->stateMachineStateRepository = $stateMachineStateRepository;
        $this->shopwareVersion = $shopwareVersion;
    }

    /**
     * @throws AsyncPaymentProcessException
     */
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {
file_put_contents("/var/www/html/var/log/zzz.log", json_encode('here', JSON_PRETTY_PRINT) . PHP_EOL, FILE_APPEND);
        $this->salesChannelContext = $salesChannelContext;

        $this->logger->writeLog(['Start Lunar payment']);

        /** Prepare vars. */
        $context = $salesChannelContext->getContext();
        $this->salesChannelId = $salesChannelContext->getSalesChannelId();
        
        $orderTransactionId = $transaction->getOrderTransaction()->getId();

        if (!$orderTransactionId) {
            $this->logger->writeLog(['Frontend process error: No shopware order transaction ID was provided (unable to extract it)']);
            throw new TransactionException($orderTransactionId, '', null, 'TRANSACTION_ERROR');
        }

        $this->setArgs();

        try {
            $customer = $salesChannelContext->getCustomer();
            if ($customer === null) {
                throw CartException::customerNotLoggedIn();
            }

            if (! $this->getPaymentIntentFromOrder()) {
                $this->paymentIntentId = $this->lunarApiClient->payments()->create($this->args);
            }
    
            if (! $this->paymentIntentId) {
                $errorMessage = 'An error occured creating payment for order. Please try again or contact system administrator.'; // <a href="/">Go to homepage</a>'
                throw new AsyncPaymentProcessException($orderTransactionId, $errorMessage);
            }
    
            $this->savePaymentIntentOnOrder();
    
            $redirectUrl = self::REMOTE_URL . $this->paymentIntentId;
            if(isset($this->args['test'])) {
                $redirectUrl = self::TEST_REMOTE_URL . $this->paymentIntentId;
            }

            return new RedirectResponse($redirectUrl);

        } catch(\Lunar\Exception\ApiException $e) {
            $this->logger->writeLog(['API exception' => $e->getMessage()]);

            throw new AsyncPaymentProcessException($orderTransactionId, $e->getMessage());

        } catch (\Exception $e) {
            $this->logger->writeLog(['Exception' => $e->getMessage()]);

            throw new AsyncPaymentProcessException($orderTransactionId, $e->getMessage());
        }
    }

    /**
     * @throws AsyncPaymentFinalizeException
     * @throws CustomerCanceledAsyncPaymentException
     */
    public function finalize(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {

        $this->logger->writeLog(['Started']);
        
        if ($this->transactionAlreadyFinalized($transaction, $salesChannelContext)) {
            $this->logger->writeLog(['Already finalized']);

            return;
        }

        $this->orderTransaction = $transaction->getOrderTransaction();
        $orderTransactionId = $this->orderTransaction->getId();
        
        if ($this->isTransactionCanceled($transaction, $salesChannelContext)) {
            $this->logger->writeLog(['Customer canceled']);
            
            throw new CustomerCanceledAsyncPaymentException($orderTransactionId, 'Customer canceled the payment on the Lunar page');
        }
        
        $this->salesChannelContext = $salesChannelContext;

        
        $this->setArgs();

        
        $orderId = $transaction->getOrder()->getId();


        // $orderCurrency = $salesChannelContext->getCurrency()->getIsoCode();
        
        // $orderTransactionId = $this->orderTransaction->getId();
        // $transactionAmount = $this->orderTransaction->getAmount()->getTotalPrice();


        // $amountInMinor = (int) CurrencyHelper::getAmountInMinor($orderCurrency, $transactionAmount);

        // $transactionData = [
        //     [
        //         'orderId' => $orderId,
        //         'transactionId' => $transactionId,
        //         'transactionType' => OrderHelper::AUTHORIZE_STATUS,
        //         'transactionCurrency' => $orderCurrency,
        //         'orderAmount' => $transactionAmount,
        //         'transactionAmount' => $transactionAmount,
        //         'amountInMinor' => 0,
        //         'createdAt' => date(Defaults::STORAGE_DATE_TIME_FORMAT),
        //     ],
        // ];

        // $this->logger->writeLog([['Frontend request data: ', $transactionData[0]]]);



        try {
            // validate settings???


            // if ($paymentState === 'completed') {
            //     // Payment completed, set transaction status to "paid"
            //     $this->orderTransactionStateHandler->paid($orderTransactionId, $context);
            // } else {
            //     // Payment not completed, set transaction status to "open"
            //     $this->orderTransactionStateHandler->reopen($orderTransactionId, $context);
            // }

        } catch (\Exception $e) {
            throw new AsyncPaymentFinalizeException($orderTransactionId, $e->getMessage());
        }
    }

    /**
     * 
     */
    private function setArgs()
    {
        $currency = $this->salesChannelContext->getCurrency()->getIsoCode();
        $orderTotalAmount = $this->order->getAmountTotal();
        
        if ($this->testMode) {
            $this->args['test'] = PluginHelper::getTestObject($currency);
        }

        $this->args['integration'] = [
            'key' => $this->publicKey,
            'name' => $this->getSalesChannelConfig('shopTitle'),
            'logo' =>  $this->getSalesChannelConfig('logoUrl'),
        ];

        $this->args['amount'] = [
            'currency' => $currency,
            'decimal' => $orderTotalAmount,
        ];

        if ($this->getSalesChannelConfig('configurationId')) {
            $this->args['mobilePayConfiguration'] = [
                'configurationID' => $this->getSalesChannelConfig('configurationId'),
                'logo'            => $this->getSalesChannelConfig('logoUrl'),
            ];
        }

        $customer = $this->salesChannelContext->getCustomer();
        $address = $customer->getActiveBillingAddress();
        if ( ! $address) {
            $address = $customer->getDefaultBillingAddress();
        }
        $customerName = $address->firstName . ' ' . $address->lastName;
        $customerAddress = $address->street . ' '
                            . $address->city . ' '
                            . $address->getCountry()->name  . ' '
                            . $address->getCountry()->iso;

        $products = [];
        foreach ($this->order->getLineItems() as $product) {
            $products[] = [
                'ID' => $product->getAutoIncrement(),
                'name' => $product->getLabel(),
                'quantity' => $product->getQuantity(),
            ];
        }

        $this->args['custom'] = [
            'orderId' => $this->order->getOrderNumber(),
            'products' => $products,
            'customer' => [
                'name' => $customerName,
                'email' => $customer->getEmail(),
                'phoneNo' => null, // update this
                'address' => $customerAddress,
                'IP' => $customer->getRemoteAddress(),
            ],
            'platform' => [
                'name' => 'Shopware',
                'version' => $this->shopwareVersion,
            ],
            'lunarPluginVersion' => PluginHelper::getPluginVersion(),
        ];

        // $this->args['redirectUrl'] = ..................

        // @TODO remove hardcoded value
        // $this->args['preferredPaymentMethod'] = $this->paymentMethodCode == PluginHelper::PAYMENT_METHOD_NAME ? 'card' : 'mobilePay';
        $this->args['preferredPaymentMethod'] = 'card'; 

    }

    /** 
     * 
     */
    private function transactionAlreadyFinalized(
        AsyncPaymentTransactionStruct $transaction,
        SalesChannelContext $salesChannelContext
    ): bool {
        $transactionStateMachineStateId = $transaction->getOrderTransaction()->getStateId();
        $criteria = new Criteria([$transactionStateMachineStateId]);

        /** @var StateMachineStateEntity|null $stateMachineState */
        $stateMachineState = $this->stateMachineStateRepository->search(
            $criteria,
            $salesChannelContext->getContext()
        )->get($transactionStateMachineStateId);

        if ($stateMachineState === null) {
            return false;
        }

        return \in_array(
            $stateMachineState->getTechnicalName(),
            self::FINALIZED_ORDER_TRANSACTION_STATES,
            true
        );
    }

    /**
     * @TODO add logic here
     */
    private function isTransactionCanceled($transaction, $salesChannelContext): bool
    {
        //
        return false;
    }

        /**
     *
     */
    private function getPaymentIntentFromOrder()
    {
        $paymentIntentId = $this->orderTransaction->getCustomFieldsValue($this->intentIdKey);
        return $paymentIntentId;
    }

    /**
     *
     */
    private function savePaymentIntentOnOrder()
    {
        $oldCustomFields = $this->orderTransaction->getCustomFields();
        $customFields = array_merge([$this->intentIdKey => $this->paymentIntentId], $oldCustomFields);
        $this->orderTransaction->setCustomFields($customFields);
    }

    /**
     * Parses api transaction response for errors
     */
    protected function parseApiTransactionResponse($transaction)
    {
        if (! $this->isTransactionSuccessful($transaction)) {
            $this->logger->writeLog(["Transaction with error: " . json_encode($transaction, JSON_PRETTY_PRINT)]);
            return false;
        }

        return true;
    }

    /**
	 * Checks if the transaction was successful and
	 * the data was not tempered with.
     * 
     * @return bool
     */
    private function isTransactionSuccessful($transaction)
    {   
        $matchCurrency = $this->order->getCurrency()->isoCode == $transaction['amount']['currency'];
        $matchAmount = $this->args['amount']['decimal'] == $transaction['amount']['decimal'];

        return (true == $transaction['authorisationCreated'] && $matchCurrency && $matchAmount);
    }

    /**
     * Gets errors from a failed api request
     * @param array $result The result returned by the api wrapper.
     * @return string
     */
    private function getResponseError($result)
    {
        $error = [];
        // if this is just one error
        if (isset($result['text'])) {
            return $result['text'];
        }

        if (isset($result['code']) && isset($result['error'])) {
            return $result['code'] . '-' . $result['error'];
        }

        // otherwise this is a multi field error
        if ($result) {
            foreach ($result as $fieldError) {
                $error[] = $fieldError['field'] . ':' . $fieldError['message'];
            }
        }

        return implode(' ', $error);
    }

    /**
     * 
     */
    private function getSalesChannelConfig(string $key)
    {
        return $this->systemConfigService->get(PluginHelper::PLUGIN_CONFIG_PATH . $key, $this->salesChannelId);
    }
}
