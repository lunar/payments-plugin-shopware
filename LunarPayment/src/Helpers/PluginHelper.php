<?php

namespace Lunar\Payment\Helpers;

class PluginHelper
{
    public const VENDOR_NAME = 'lunar';

    public const PLUGIN_NAME = 'LunarPayment';

    public const PLUGIN_VERSION = '1.0.0';

    // generated with \Shopware\Core\Framework\Uuid\Uuid::randomHex()
    public const PAYMENT_METHOD_UUID = '1a9bc76a3c244278a51a2e90c1e6f040';

    public const PAYMENT_METHOD_NAME = 'Lunar - Cards';

    public const PAYMENT_METHOD_DESCRIPTION = 'Secure payment with credit card via Lunar';

    public const ACCEPTED_CARDS = ['visa', 'visaelectron', 'mastercard', 'maestro'];

    public const PLUGIN_CONFIG_PATH = self::PLUGIN_NAME . '.settings.';
}
