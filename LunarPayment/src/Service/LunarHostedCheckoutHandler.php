<?php declare(strict_types=1);

namespace Lunar\Payment\Service;

// use Psr\Log\LoggerInterface;

use Shopware\Core\Defaults;
use Shopware\Core\Checkout\Cart\CartException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
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
use Lunar\Exception\ApiException as LunarApiException;
use Lunar\Payment\Helpers\OrderHelper;
use Lunar\Payment\Helpers\PluginHelper;
use Lunar\Payment\Helpers\LogHelper as Logger;
use Lunar\Payment\Exception\TransactionException;


class LunarHostedCheckoutHandler implements AsynchronousPaymentHandlerInterface
{
    private const REMOTE_URL = 'https://pay.lunar.money/?id=';
    private const TEST_REMOTE_URL = 'https://hosted-checkout-git-develop-lunar-app.vercel.app/?id=';

    private const FINALIZED_ORDER_TRANSACTION_STATES = [
        OrderHelper::TRANSACTION_AUTHORIZED,
        OrderHelper::TRANSACTION_PAID,
    ];

    private ApiClient $lunarApiClient;
    private OrderEntity $order;
    private SalesChannelContext $salesChannelContext;
    private OrderTransactionEntity $orderTransaction;
    private AsyncPaymentTransactionStruct $paymentTransaction;

    private ?string $paymentIntentId = '';
    private bool $isInstantMode = false;
    private array $args = [];
    private bool $testMode = false;
    private string $publicKey = '';
    private string $paymentMethodCode;


    public function __construct(
        // private LoggerInterface $logger,
        private SystemConfigService $systemConfigService,
        private OrderTransactionStateHandler $orderTransactionStateHandler,
        private EntityRepository $stateMachineStateRepository,
        private EntityRepository $lunarTransactionRepository,
        private EntityRepository $orderRepository,
        private string $shopwareVersion,
        private Logger $logger,
        private OrderHelper $orderHelper,
        private PluginHelper $pluginHelper,
    ) {
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->stateMachineStateRepository = $stateMachineStateRepository;
        $this->lunarTransactionRepository = $lunarTransactionRepository;
        $this->orderRepository = $orderRepository;
        $this->shopwareVersion = $shopwareVersion;
        $this->logger = $logger;
        $this->orderHelper = $orderHelper;
        $this->pluginHelper = $pluginHelper;
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
        
        $paymentMethodId = $this->orderTransaction->getPaymentMethodId();     
        $this->paymentMethodCode = PluginHelper::LUNAR_PAYMENT_METHODS[$paymentMethodId]['code'];
        
        $this->isInstantMode = 'instant' === $this->getSetting('CaptureMode');
        $this->testMode = 'test' == $this->getSetting('TransactionMode');
        if ($this->testMode) {
            $this->publicKey =  $this->getSetting('TestModePublicKey');
            $privateKey =  $this->getSetting('TestModeAppKey');
        } else {
            $this->publicKey = $this->getSetting('LiveModePublicKey');
            $privateKey = $this->getSetting('LiveModeAppKey');
        }

        $this->lunarApiClient = new ApiClient($privateKey);
    }

    /**
     * PAY
     * 
     * @throws AsyncPaymentProcessException
     */
    public function pay(
        AsyncPaymentTransactionStruct $paymentTransaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {

        $this->logger->writeLog('Start Lunar payment');

        $this->prepareVars($paymentTransaction, $salesChannelContext);

        $orderTransactionId = $this->orderTransaction->getId();

        if (!$orderTransactionId) {
            $this->logger->writeLog('No shopware order transaction ID was provided (unable to extract it)');
            throw new TransactionException($orderTransactionId, '', null, 'TRANSACTION_ERROR');
        }

        $this->setArgs();
        $this->logger->writeLog($this->args, false);

        try {
            if ($this->salesChannelContext->getCustomer() === null) {
                throw CartException::customerNotLoggedIn();
            }

            $this->paymentIntentId = $this->orderHelper->getPaymentIntentFromOrder($this->order);
            if (!$this->paymentIntentId) {
                $this->paymentIntentId = $this->lunarApiClient->payments()->create($this->args);
            }
    
            if (empty($this->paymentIntentId)) {
                $errorMessage = 'An error occured creating payment for order. Please try again or contact system administrator.'; // <a href="/">Go to homepage</a>'
                throw new AsyncPaymentProcessException($orderTransactionId, $errorMessage);
            }
    
            $this->orderHelper->savePaymentIntentOnOrder(
                $this->order,
                $this->paymentIntentId,
                $this->salesChannelContext->getContext()
            );
    
            $redirectUrl = self::REMOTE_URL . $this->paymentIntentId;
            if(isset($this->args['test'])) {
                $redirectUrl = self::TEST_REMOTE_URL . $this->paymentIntentId;
            }

            return new RedirectResponse($redirectUrl);

        } catch(LunarApiException $e) {
            $this->logger->writeLog(['API exception' => $e->getMessage()]);

            throw new AsyncPaymentProcessException($orderTransactionId, $e->getMessage());

        } catch (\Exception $e) {
            $this->logger->writeLog(['Async Pay() Exception' => $e->getMessage()]);

            throw new AsyncPaymentProcessException($orderTransactionId, $e->getMessage());
        }
    }

    /**
     * FINALIZE
     * 
     * @throws AsyncPaymentFinalizeException
     * @throws CustomerCanceledAsyncPaymentException
     */
    public function finalize(
        AsyncPaymentTransactionStruct $paymentTransaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {

        $this->logger->writeLog('Start finalize payment');

        $context = $salesChannelContext->getContext();

        $this->prepareVars($paymentTransaction, $salesChannelContext);

        if ($this->transactionAlreadyFinalized()) {
            $this->logger->writeLog('Already finalized');

            return;
        }
        
        $orderTransactionId = $this->orderTransaction->getId();
        $paymentIntentId = $this->orderHelper->getPaymentIntentFromOrder($this->order);

        if (! $paymentIntentId) {
            throw new AsyncPaymentFinalizeException($orderTransactionId, 'Missing payment intent');
        }

        $apiResponse = $this->lunarApiClient->payments()->fetch($paymentIntentId);
        
        if (! $this->parseApiTransactionResponse($apiResponse)) {
            throw new AsyncPaymentFinalizeException($orderTransactionId, $this->getResponseError($apiResponse));
        }

        $orderCurrency = $this->orderHelper->getCurrencyCode($this->order);
        $transactionAmount = $this->orderTransaction->getAmount()->getTotalPrice();

        $transactionData = [
            [
                'orderId' => $this->order->getId(),
                'orderNumber' => $this->order->getOrderNumber(),
                'transactionId' => $paymentIntentId,
                'transactionType' => OrderHelper::AUTHORIZE,
                'transactionCurrency' => $orderCurrency,
                'orderAmount' => $transactionAmount,
                'transactionAmount' => $transactionAmount,
                'paymentMethod' => $this->paymentMethodCode,
                'createdAt' => date(Defaults::STORAGE_DATE_TIME_FORMAT),
            ],
        ];

        $params = [
            'amount' => [
                'currency' => $orderCurrency,
                'decimal' => (string) $transactionAmount,
            ]
        ];
        
        $this->logger->writeLog(['Finalize payment params' => $params], false);


        if ($this->isInstantMode) {          

            try {
                $this->lunarApiClient->payments()->capture($paymentIntentId, $params);

            } catch (LunarApiException $e) {
                throw new AsyncPaymentFinalizeException($orderTransactionId, $e->getMessage());
            }

            /** Set order transaction status -> "Paid" */
            $this->orderTransactionStateHandler->paid($orderTransactionId, $context);

            /** Change type of transaction for data to be saved in DB cutom table. */
            $transactionData[0]['transactionType'] = OrderHelper::CAPTURE;

        } else {
            $this->orderTransactionStateHandler->authorize($orderTransactionId, $context); 
        }

        /** Insert transaction data to custom table. */
        $this->lunarTransactionRepository->create($transactionData, $context);

        /** 
         * Change order state in instant mode.
         * We run here to have already inserted lunar transaction in custom table
         */
        $this->isInstantMode 
            ? $this->orderHelper->changeOrderState($this->order->getId(), OrderHelper::CAPTURE, $context) 
            : null;
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

        $this->args = [
			'integration' => [
				'key' => $this->publicKey,
                'name' => $this->getSetting('ShopTitle'),
                'logo' =>  $this->getSetting('LogoURL'),
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
			'preferredPaymentMethod' => $this->paymentMethodCode,
		];

        if ($this->getSetting('ConfigurationID')) {
            $this->args['mobilePayConfiguration'] = [
                'configurationID' => $this->getSetting('ConfigurationID'),
                'logo' => $this->getSetting('LogoURL'),
            ];
        }

        if ($this->testMode) {
            $this->args['test'] = PluginHelper::getTestObject($currency);
        }

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
        $matchCurrency = $this->orderHelper->getCurrencyCode($this->order) == $apiResponse['amount']['currency'];
        $matchAmount = $this->order->getAmountTotal() == $apiResponse['amount']['decimal'];

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

        return in_array($stateMachineState->getTechnicalName(), self::FINALIZED_ORDER_TRANSACTION_STATES, true);
    }

    /**
     * 
     */
    private function getSetting(string $key)
    {
        return $this->pluginHelper->getSalesChannelConfig(
            $key, 
            $this->paymentMethodCode, 
            $this->salesChannelContext->getSalesChannelId()
        );
    }
}
