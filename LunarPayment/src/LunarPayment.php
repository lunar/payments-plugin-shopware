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
use Lunar\Payment\Service\LunarHostedCheckoutHandler;

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
        parent::build($container);

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/DependencyInjection'));
        $loader->load('services.xml');
    }

    /**
     * INSTALL
     */
    public function install(InstallContext $context): void
    {
        $this->upsertPaymentMethods($context->getContext(), $installContext = true);

        parent::install($context);
    }

    /** UPDATE */
    public function update(UpdateContext $context): void
    {
        $this->upsertPaymentMethods($context->getContext());

        parent::update($context);
    }

    /**
     * UNINSTALL
     */
    public function uninstall(UninstallContext $context): void
    {
        $this->setPaymentMethodsActive(false, $context->getContext());

        parent::uninstall($context);

        if ($context->keepUserData()) {
            return;
        }

        $connection = $this->container->get(Connection::class);
        $connection->executeUpdate('DROP TABLE IF EXISTS `' . 'lunar_transaction`');
    }

    /**
     * DEACTIVATE
     */
    public function deactivate(DeactivateContext $context): void
    {
        $this->setPaymentMethodsActive(false, $context->getContext());
        parent::deactivate($context);
    }
    
    /**
     *
     */
    private function upsertPaymentMethods(Context $context, $installContext = false): void
    {

        foreach (PluginHelper::LUNAR_PAYMENT_METHODS as $paymentMethodUuid => $paymentMethod) {

            /** 
             * Set defaults only in install context to not interfere with existing user settings 
             */
            $installContext ? $this->setConfigDefaults($paymentMethod) : null;

            $paymentMethodName = ucfirst($paymentMethod['code']);
            $paymentMethodDescription = $paymentMethod['description'];

            /** @var PluginIdProvider $pluginIdProvider */
            $pluginIdProvider = $this->container->get(PluginIdProvider::class);
            $pluginId = $pluginIdProvider->getPluginIdByBaseClass(get_class($this), $context);

            /** @var EntityRepository $paymentRepository */
            $paymentRepository = $this->container->get('payment_method.repository');
            /** @var EntityRepository $translationRepository */
            $translationRepository = $this->container->get('payment_method_translation.repository');

            $paymentRepository->upsert([
                [
                    'id' => $paymentMethodUuid,
                    'handlerIdentifier' => LunarHostedCheckoutHandler::class, // use same handler for both methods for now
                    'pluginId' => $pluginId,
                    'afterOrderEnabled' => false, // disable by default after order actions
                    'name' => $paymentMethodName,
                    'description' => $paymentMethodDescription,
                    'position' => 0,
                ]
            ], $context);

            /**
             * Translations
             */
            $languageRepo = $this->container->get('language.repository');
            $languageEN = $languageRepo->search((new Criteria())->addFilter(new EqualsFilter('name', 'English')), Context::createDefaultContext())->first()->getId();
            $languageDE = $languageRepo->search((new Criteria())->addFilter(new EqualsFilter('name', 'Deutsch')), Context::createDefaultContext())->first()->getId();

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

            /** Attach to sales channels */
            $this->attachPaymentMethodToSalesChannels($paymentMethodUuid, $context);
        }
    }

    /**
     *
     */
    private function attachPaymentMethodToSalesChannels(string $paymentMethodUuid, Context $context)
    {
        // this is properly done ONLY in install context
        /** @var EntityRepository $salesChannelRepository */
        $salesChannelRepository = $this->container->get('sales_channel.repository');
        /** @var EntityRepository $salesChannelPaymentMethodRepository */
        $salesChannelPaymentMethodRepository = $this->container->get('sales_channel_payment_method.repository');

        $channels = $salesChannelRepository->searchIds(new Criteria(), $context);

        foreach ($channels->getIds() as $channelId) {
            $data = [
                'salesChannelId'  => $channelId,
                'paymentMethodId' => $paymentMethodUuid,
            ];

            $salesChannelPaymentMethodRepository->upsert([$data], $context);
        }
        //
    }

    /**
     * 
     */
    private function setConfigDefaults($paymentMethodDefaults): void
    {
        /** @var SystemConfigService $config */
        $config = $this->container->get(SystemConfigService::class);
        $paymentMethodCode = $paymentMethodDefaults['code'];
        $configPath = PluginHelper::PLUGIN_CONFIG_PATH . $paymentMethodCode;

        PluginHelper::CARD_PAYMENT_METHOD == $paymentMethodCode
            ? $config->set(PluginHelper::PLUGIN_CONFIG_PATH . 'cardAcceptedCards', PluginHelper::ACCEPTED_CARDS)
            : null;

        $config->set($configPath . 'TransactionMode', PluginHelper::TRANSACTION_MODE);
        $config->set($configPath . 'CaptureMode', PluginHelper::CAPTURE_MODE);
        $config->set($configPath . 'ShopTitle', $config->get('core.basicInformation.shopName'));
        $config->set($configPath . 'Description', $paymentMethodDefaults['description']);

        // global setting
        $config->set(PluginHelper::PLUGIN_CONFIG_PATH . 'logsEnabled', false);
    }

    /**
     *
     */
    private function setPaymentMethodsActive(bool $active, Context $context): void
    {
        /** @var EntityRepository $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');

        foreach (PluginHelper::LUNAR_PAYMENT_METHODS as $paymentMethodUuid => $paymentMethod) {
            $paymentRepository->update([
                [
                    'id' => $paymentMethodUuid,
                    'active' => $active,
                ]
            ], $context);
        }
    }

    /**
     * In case we need this
     */
    /** ACTIVATE */
    public function activate(ActivateContext $context): void {}
    /** POST-INSTALL */
    public function postInstall(InstallContext $installContext): void {}
    /** POST_UPDATE */
    public function postUpdate(UpdateContext $updateContext): void {}
}
