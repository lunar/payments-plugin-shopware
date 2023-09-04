<?php declare(strict_types=1);

namespace Lunar\Payment\Subscriber;

use Lunar\Payment\Helpers\CurrencyHelper;
use Lunar\Payment\Helpers\PluginHelper;
use Lunar\Payment\Helpers\OrderHelper;

use Symfony\Component\Process\Exception\LogicException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Cart\Order\OrderConverter;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Page;
use Shopware\Storefront\Page\PageLoadedEvent;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPage;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPage;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;

/**
 *
 */
class CheckoutConfirmSubscriber implements EventSubscriberInterface
{
    /** @var OrderConverter */
    private $orderConverter;

    /** @var OrderHelper */
    private $orderHelper;

    /** @var SystemConfigService */
    private $systemConfigService;

    /** @var string */
    private $shopwareVersion;

    public function __construct(
        OrderConverter $orderConverter,
        OrderHelper $orderHelper,
        SystemConfigService $systemConfigService,
        string $shopwareVersion
    ) {
        $this->orderConverter = $orderConverter;
        $this->orderHelper = $orderHelper;
        $this->systemConfigService = $systemConfigService;
        $this->shopwareVersion = $shopwareVersion;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class  => 'addPluginData',
            AccountEditOrderPageLoadedEvent::class => 'addPluginData',
        ];
    }

    /**
     * @param AccountEditOrderPageLoadedEvent|CheckoutConfirmPageLoadedEvent $event
     *
     * @return void
     */
    public function addPluginData(PageLoadedEvent $event): void
    {
        if (
            !($event instanceof CheckoutConfirmPageLoadedEvent)
            && !($event instanceof AccountEditOrderPageLoadedEvent)
        ) {
            return;
        }

        $page = $event->getPage();
        $salesChannelContext = $event->getSalesChannelContext();

        if ($event instanceof CheckoutConfirmPageLoadedEvent) {
            $cart = $page->getCart();
        } else {
            $cart  = $this->transformOrderToCart($page->getOrder(), $event->getContext());
        }

        $this->excludePaymentMethodOnZeroAmount($page, $cart, $salesChannelContext);

        $configPath = PluginHelper::PLUGIN_CONFIG_PATH;
        $transactionMode = $this->systemConfigService->get($configPath . 'transactionMode');

        $publicApiKey = $this->systemConfigService->get($configPath . 'liveModePublicKey');

        if ($transactionMode == 'test') {
            $publicApiKey = $this->systemConfigService->get($configPath . 'testModePublicKey');
        }

        $popupTitle = $this->systemConfigService->get($configPath . 'popupTitle');
        $popupDescription = $this->systemConfigService->get($configPath . 'popupDescription');
        $totalPrice = $cart->getPrice()->getTotalPrice();
        $currencyCode = $page->getHeader()->getActiveCurrency()->isoCode;
        $currencyExponent = CurrencyHelper::getPluginCurrency($currencyCode)['exponent'];
        $amountValue = CurrencyHelper::getAmountInMinor($currencyCode, $totalPrice);
        $language = $page->getHeader()->getLanguages()->first()->translationCode->code;
        $customer = $salesChannelContext->getCustomer();
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
        foreach ($cart->getLineItems() as $lineItem) {
            $products[] = [
                'name' => $lineItem->getLabel(),
                'quantity' => $lineItem->getQuantity(),
            ];
        }

        $page->assign([
            PluginHelper::VENDOR_NAME => [
                'public_key' => $publicApiKey,
                'plugin_mode' => $transactionMode,
                'popup_title' => $popupTitle,
                'popup_description' => $popupDescription,
                'currency_code' => $currencyCode,
                'currency_exponent' => $currencyExponent,
                'amount_value' => $amountValue,
                'products' => $products,
                'lc' => $language,
                'name' => $customerName,
                'email' => $customer->email,
                'phone' => $address->phoneNumber,
                'address' => $customerAddress,
                'ip' => $customer->getRemoteAddress(),
                'plugin_version' => PluginHelper::PLUGIN_VERSION,
                'shopware_version' => $this->shopwareVersion,
            ]
        ]);
    }

    /**
     * @param OrderEntity $orderEntity
     * @param Context $context
     *
     * @return Cart
     */
    private function transformOrderToCart(OrderEntity $orderEntity, Context $context): Cart
    {
        $order = $this->orderHelper->getOrderById($orderEntity->getId(), $context);

        if (null === $order) throw new LogicException('The order could not be found');

        return $this->orderConverter->convertToCart($order, $context);
    }

    /**
     * @param AccountEditOrderPage|CheckoutConfirmPage $page
     * @param Cart $cart
     * @param SalesChannelContext $salesChannelContext
     *
     * @return void
     */
    private function excludePaymentMethodOnZeroAmount(Page $page, Cart $cart, SalesChannelContext $salesChannelContext): void
    {
        $totalAmount = (int) round($cart->getPrice()->getTotalPrice() * (10 ** $salesChannelContext->getCurrency()->totalRounding->getDecimals()));

        if ($totalAmount > 0) return;

        $page->setPaymentMethods(
            $page->getPaymentMethods()->filter(static function (PaymentMethodEntity $paymentMethod) {
                return mb_strpos($paymentMethod->getHandlerIdentifier(), PluginHelper::PLUGIN_NAME) === false;
            })
        );

        $salesChannelContext->assign(['paymentMethods' => $page->getPaymentMethods()]);
    }
}
