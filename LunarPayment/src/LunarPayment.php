<?php declare(strict_types=1);

namespace Lunar\Payment;

/**
 * Load sdk from vendor folder if exists
 * It can be installed also via composer without problems
 */
if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    $loader = require_once dirname(__DIR__) . '/vendor/autoload.php';
    if ($loader !== true) {
        spl_autoload_unregister([$loader, 'loadClass']);
        $loader->register(false);
    }
}

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Context;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\Config\FileLocator;

use Doctrine\DBAL\Connection;

use Lunar\Payment\Helpers\PluginHelper;
use Lunar\Payment\Service\LunarPaymentHandler;

/**
 *
 */
class LunarPayment extends Plugin
{
    /**
     * Load dependency injection configuration from xml file
     */
    public function build(ContainerBuilder $container): void
    {
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/DependencyInjection'));
        $loader->load('services.xml');

        parent::build($container);
    }

    /**
     * INSTALL
     */
    public function install(InstallContext $context): void
    {
        parent::install($context);

        $this->addPaymentMethod($context->getContext());

        /** Defaults for multi-select field "acceptedCards". */
        $config = $this->container->get(SystemConfigService::class);
        $config->set(PluginHelper::PLUGIN_CONFIG_PATH . 'acceptedCards', PluginHelper::ACCEPTED_CARDS);
        $config->set(PluginHelper::PLUGIN_CONFIG_PATH . 'transactionMode', PluginHelper::TRANSACTION_MODE);
        $config->set(PluginHelper::PLUGIN_CONFIG_PATH . 'captureMode', PluginHelper::CAPTURE_MODE);
        $config->set(PluginHelper::PLUGIN_CONFIG_PATH . 'shopTitle', $config->get('core.basicInformation.shopName'));
        $config->set(PluginHelper::PLUGIN_CONFIG_PATH . 'description', PluginHelper::PAYMENT_METHOD_DESCRIPTION);
    }

    /**
     * UNINSTALL
     */
    public function uninstall(UninstallContext $context): void
    {
        $this->setPaymentMethodIsActive(false, $context->getContext());

        parent:: uninstall($context);
        if ($context->keepUserData()) {
            return;
        }

        $connection = $this->container->get(Connection::class);
        $connection->executeUpdate('DROP TABLE IF EXISTS `' . PluginHelper::VENDOR_NAME . '_transactions`');
    }

    /**
     * ACTIVATE
     */
    public function activate(ActivateContext $context): void
    {
        $this->setPaymentMethodIsActive(true, $context->getContext());
        parent::activate($context);
    }

    /**
     * DEACTIVATE
     */
    public function deactivate(DeactivateContext $context): void
    {
        $this->setPaymentMethodIsActive(false, $context->getContext());
        parent::deactivate($context);
    }

    /**
     *
     */
    private function addPaymentMethod(Context $context): void
    {
        $paymentMethodExists = $this->getPaymentMethodId();

        if ($paymentMethodExists) {
            return;
        }

        /** @var PluginIdProvider $pluginIdProvider */
        $pluginIdProvider = $this->container->get(PluginIdProvider::class);
        $pluginId = $pluginIdProvider->getPluginIdByBaseClass(get_class($this), $context);
        $languageRepo = $this->container->get('language.repository');
        $languageEN = $languageRepo->search((new Criteria())->addFilter(new EqualsFilter('name','English')), Context::createDefaultContext())->first()->getId();
        $languageDE = $languageRepo->search((new Criteria())->addFilter(new EqualsFilter('name','Deutsch')), Context::createDefaultContext())->first()->getId();

        $paymentMethodUuid = PluginHelper::PAYMENT_METHOD_UUID;
        $paymentMethodName = PluginHelper::PAYMENT_METHOD_NAME;
        $paymentMethodDescription = PluginHelper::PAYMENT_METHOD_DESCRIPTION;

        $paymentMethodData = [
            'id' => $paymentMethodUuid,
            'handlerIdentifier' => LunarPaymentHandler::class,
            'pluginId' => $pluginId,
            'afterOrderEnabled' => false, // disable by default after order actions
            'name' => PluginHelper::PAYMENT_METHOD_ADMIN_NAME,
            'description' => $paymentMethodDescription,
        ];

        /** @var EntityRepository $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');
        $translationRepository = $this->container->get('payment_method_translation.repository');

        $paymentRepository->upsert([$paymentMethodData], $context);


        $translationRepository->upsert([
            [
                'paymentMethodId' => $paymentMethodUuid,
                'languageId' => $languageEN,
                'name' => $paymentMethodName,
                'description' => $paymentMethodDescription,
            ],
            [
                'paymentMethodId' => $paymentMethodUuid,
                'languageId' => $languageDE,
                'name' => $paymentMethodName,
                'description' => $paymentMethodDescription,
            ]
        ], $context);

        $this->attachPaymentMethodToSalesChannels($context);
    }

    /**
     *
     */
    private function setPaymentMethodIsActive(bool $active, Context $context): void
    {
        /** @var EntityRepository $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');

        $paymentMethodId = $this->getPaymentMethodId();

        if (!$paymentMethodId) {
            return;
        }

        $paymentMethod = [
            'id' => $paymentMethodId,
            'active' => $active,
        ];

        $paymentRepository->update([$paymentMethod], $context);
    }

    /**
     *
     */
    private function getPaymentMethodId(): ?string
    {
        /** @var EntityRepository $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');

        $paymentCriteria = (new Criteria())->addFilter(new EqualsFilter('handlerIdentifier', LunarPaymentHandler::class));
        return $paymentRepository->searchIds($paymentCriteria, Context::createDefaultContext())->firstId();
    }

    /**
     *
     */
    private function attachPaymentMethodToSalesChannels(Context $context)
    {
        // this is properly done ONLY in install context
        /** @var EntityRepository $salesChannelRepository */
        $salesChannelRepository = $this->container->get('sales_channel.repository');
        /** @var EntityRepository $salesChannelPaymentMethodRepository */
        $salesChannelPaymentMethodRepository = $this->container->get('sales_channel_payment_method.repository');

        $channels = $salesChannelRepository->searchIds(new Criteria(), $context);

        foreach ($channels->getIds() as $channel) {
            $data = [
                'salesChannelId'  => $channel,
                'paymentMethodId' => $this->getPaymentMethodId(),
            ];

            $salesChannelPaymentMethodRepository->upsert([$data], $context);
        }
        //
    }

    /**
     *
     */
    private function findPaymentMethodEntity(string $id, Context $context): ?PaymentMethodEntity
    {
        /** @var EntityRepository $paymentMethodRepository */
        $paymentMethodRepository = $this->container->get('payment_method.repository');

        return $paymentMethodRepository->search(new Criteria([$id]), $context)->first();
    }

    /**
     * In case we need this
     */
    /** UPDATE */
    public function update(UpdateContext $context): void {}
    /** POST-INSTALL */
    public function postInstall(InstallContext $installContext): void {}
    /** POST_UPDATE */
    public function postUpdate(UpdateContext $updateContext): void {}
}
