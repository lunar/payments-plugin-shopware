<?php

namespace Lunar\Payment\Helpers;

class PluginHelper
{
    // generated with \Shopware\Core\Framework\Uuid\Uuid::randomHex()
    public const PAYMENT_METHOD_UUID = '1a9bc76a3c244278a51a2e90c1e6f040';

    public const VENDOR_NAME = 'lunar';

    public const PLUGIN_CODE = 'LunarPayment';
    public const PAYMENT_METHOD_NAME = 'Card';

    public const TRANSACTION_MODE = 'live';
    public const CAPTURE_MODE = 'delayed';
    public const PAYMENT_METHOD_DESCRIPTION = 'Secure payment with credit card via © Lunar';
    public const ACCEPTED_CARDS = ['visa', 'visaelectron', 'mastercard', 'maestro'];

    public const PLUGIN_CONFIG_PATH = self::PLUGIN_CODE . '.settings.';

    /** 
     * Only keep the plugin version in composer.json
     */
    public static function getPluginVersion() 
    {
        // Cannot use \Composer\InstalledVersions::getVersion('lunar/plugin-shopware-6') right now
        return json_decode(file_get_contents(dirname(__DIR__, 2) . '/composer.json'))->version;
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
