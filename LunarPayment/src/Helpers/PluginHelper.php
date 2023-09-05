<?php

namespace Lunar\Payment\Helpers;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * 
 */
class PluginHelper
{
    public function __construct(
        private SystemConfigService $systemConfigService,
    ) {
        $this->systemConfigService = $systemConfigService;
    }

    public const VENDOR_NAME = 'lunar';
    public const PLUGIN_CODE = 'LunarPayment';
    
    public const CARD_PAYMENT_METHOD = 'card';
    public const MOBILEPAY_PAYMENT_METHOD = 'mobilePay';

    /**
     * @TODO split the logic and make separate model for payment method
     * UUIDs are generated with \Shopware\Core\Framework\Uuid\Uuid::randomHex()
     */
    public const LUNAR_PAYMENT_METHODS = [
        '1a9bc76a3c244278a51a2e90c1e6f040' => [
                'code' => self::CARD_PAYMENT_METHOD,
                'description' => 'Secure payment with card via © Lunar',
            ],
        '018a269ee3ac73b8aef7e1a908577014' => [
                'code' => self::MOBILEPAY_PAYMENT_METHOD,
                'description' => 'Secure payment with MobilePay via © Lunar',
            ],
    ];

    public const ACCEPTED_CARDS = ['visa', 'visaelectron', 'mastercard', 'maestro'];

    public const TRANSACTION_MODE = 'live';
    public const CAPTURE_MODE = 'delayed';

    public const PLUGIN_CONFIG_PATH = self::PLUGIN_CODE . '.settings.';

    public const TASK_SCHEDULER_NAME = 'lunar_payment.check_unpaid_orders';

    /** 
     * Only keep the plugin version in composer.json
     */
    public static function getPluginVersion() 
    {
        // Cannot use \Composer\InstalledVersions::getVersion('lunar/plugin-shopware-6') right now
        return json_decode(file_get_contents(dirname(__DIR__, 2) . '/composer.json'))->version;
    }

    /**
     * 
     */
    public function getSalesChannelConfig(string $key, string $paymentMethodCode, ?string $salesChannelId = null)
    {
        return $this->systemConfigService->get(self::PLUGIN_CONFIG_PATH . $paymentMethodCode . $key, $salesChannelId);
    }

    /**
     * Used to have some data when in test mode
     */
    public static function getTestObject(string $currency): array
    {
        return [
            "card" => [
                "scheme"  => "supported",
                "code"    => "valid",
                "status"  => "valid",
                "limit"   => [
                    "decimal"  => "5284.49",
                    "currency" => $currency,
                ],
                "balance" => [
                    "decimal"  => "5284.49",
                    "currency" => $currency,
                ]
            ],
            "fingerprint" => "success",
            "tds"         => [
                "fingerprint" => "success",
                "challenge"   => true,
                "status"      => "authenticated"
            ],
        ];
    }
}
