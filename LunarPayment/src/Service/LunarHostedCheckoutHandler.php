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

    private ApiClient $lunarApiClient;
    private OrderEntity $order;
    private SalesChannelContext $salesChannelContext;
    private OrderTransactionEntity $orderTransaction;
    private AsyncPaymentTransactionStruct $paymentTransaction;

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
        private string $shopwareVersion
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
        AsyncPaymentTransactionStruct $paymentTransaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {

        $this->logger->writeLog(['Start Lunar payment']);

        $this->prepareVars($paymentTransaction, $salesChannelContext);

        $orderTransactionId = $this->orderTransaction->getId();

        if (!$orderTransactionId) {
            $this->logger->writeLog(['Frontend process error: No shopware order transaction ID was provided (unable to extract it)']);
            throw new TransactionException($orderTransactionId, '', null, 'TRANSACTION_ERROR');
        }

        $this->setArgs();

        try {
            if ($this->salesChannelContext->getCustomer() === null) {
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
        AsyncPaymentTransactionStruct $paymentTransaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {

        $this->logger->writeLog(['Started']);
        
        if ($this->transactionAlreadyFinalized($paymentTransaction, $salesChannelContext)) {
            $this->logger->writeLog(['Already finalized']);

            return;
        }
        
        $this->prepareVars($paymentTransaction, $salesChannelContext);
        
        $orderTransactionId = $this->orderTransaction->getId();
        
        if ($this->isTransactionCanceled()) {
            $this->logger->writeLog(['Customer canceled']);
            
            throw new CustomerCanceledAsyncPaymentException($orderTransactionId, 'Customer canceled the payment on the Lunar page');
        }
        
        $this->setArgs();

        $apiResponse = $this->lunarApiClient->payments()->fetch($this->getPaymentIntentFromOrder());

        $result = $this->parseApiTransactionResponse($apiResponse);

        if (! $result) {
            throw new AsyncPaymentFinalizeException($orderTransactionId, $this->getResponseError($apiResponse));
        }

        $this->paymentIntentId = $apiResponse['id'];


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



        // try {
        //     // validate settings???


        //     // if ($paymentState === 'completed') {
        //     //     // Payment completed, set transaction status to "paid"
        //     //     $this->orderTransactionStateHandler->paid($orderTransactionId, $context);
        //     // } else {
        //     //     // Payment not completed, set transaction status to "open"
        //     //     $this->orderTransactionStateHandler->reopen($orderTransactionId, $context);
        //     // }

        // } catch (\Exception $e) {
        //     throw new AsyncPaymentFinalizeException($orderTransactionId, $e->getMessage());
        // }
    }

    /**
     * 
     */
    private function prepareVars($paymentTransaction, $salesChannelContext): void
    {
        $this->salesChannelContext = $salesChannelContext;
        $this->paymentTransaction = $paymentTransaction;
        $this->orderTransaction = $this->paymentTransaction->getOrderTransaction();
        $this->order = $this->paymentTransaction->getOrder();        
        
        $this->testMode = 'test' == $this->getSalesChannelConfig('transactionMode');
        if ($this->testMode) {
            $this->publicKey =  $this->getSalesChannelConfig('testModePublicKey');
            $privateKey =  $this->getSalesChannelConfig('testModeAppKey');
        } else {
            $this->publicKey = $this->getSalesChannelConfig('liveModePublicKey');
            $privateKey = $this->getSalesChannelConfig('liveModeAppKey');
        }

        /** 
         * API Client instance 
         */
        $this->lunarApiClient = new ApiClient($privateKey);
    }

    /**
     * 
     */
    private function setArgs(): void
    {
        $currency = $this->salesChannelContext->getCurrency()->getIsoCode();
        $orderTotalAmount = $this->order->getAmountTotal();

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
        foreach ($this->order->getLineItems() as $lineItem) {
            $products[] = [
                // 'ID' => ($lineItem->product)->getProductNumber(), // not working this way
                'name' => $lineItem->getLabel(),
                'quantity' => $lineItem->getQuantity(),
            ];
        }

        // @TODO remove hardcoded value
        // $preferredPaymentMethod = $this->paymentMethodCode == PluginHelper::PAYMENT_METHOD_NAME ? 'card' : 'mobilePay';
        $preferredPaymentMethod = 'card'; 


        $this->args = [
			'integration' => [
				'key' => $this->publicKey,
                'name' => $this->getSalesChannelConfig('shopTitle'),
                'logo' =>  $this->getSalesChannelConfig('logoURL'),
			],
			'amount' => [
                'currency' => $currency,
                'decimal' => (string) $orderTotalAmount,
            ],
			'custom' => [
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
            ],
			'redirectUrl' => sprintf('%s', $this->paymentTransaction->getReturnUrl()),
			'preferredPaymentMethod' => $preferredPaymentMethod,
		];

        if ($this->getSalesChannelConfig('configurationId')) {
            $this->args['mobilePayConfiguration'] = [
                'configurationID' => $this->getSalesChannelConfig('configurationId'),
                'logo' => $this->getSalesChannelConfig('logoURL'),
            ];
        }

        if ($this->testMode) {
            $this->args['test'] = PluginHelper::getTestObject($currency);
        }

    }

    /** 
     * 
     */
    private function transactionAlreadyFinalized(): bool 
    {
        $transactionStateMachineStateId = $this->orderTransaction->getStateId();
        $criteria = new Criteria([$transactionStateMachineStateId]);

        /** @var StateMachineStateEntity|null $stateMachineState */
        $stateMachineState = $this->stateMachineStateRepository->search(
            $criteria,
            $this->salesChannelContext->getContext()
        )->get($transactionStateMachineStateId);

        if ($stateMachineState === null) {
            return false;
        }

        return in_array( $stateMachineState->getTechnicalName(), self::FINALIZED_ORDER_TRANSACTION_STATES, true);
    }

    /**
     * @TODO add logic here
     */
    private function isTransactionCanceled(): bool
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
    protected function parseApiTransactionResponse($apiResponse)
    {
        if (! $this->isTransactionSuccessful($apiResponse)) {
            $this->logger->writeLog(["Transaction with error: " . json_encode($apiResponse, JSON_PRETTY_PRINT)]);
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
    private function isTransactionSuccessful($apiResponse)
    {   
        $matchCurrency = $this->order->getCurrency()->isoCode == $apiResponse['amount']['currency'];
        $matchAmount = $this->args['amount']['decimal'] == $apiResponse['amount']['decimal'];

        return (true == $apiResponse['authorisationCreated'] && $matchCurrency && $matchAmount);
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
        return $this->systemConfigService->get(PluginHelper::PLUGIN_CONFIG_PATH . $key, $this->salesChannelContext->getSalesChannelId());
    }
}
