<?php

namespace Lunar\Payment\Helpers;

class PluginHelper
{
    public const PLUGIN_VERSION = '1.1.0';

    // generated with \Shopware\Core\Framework\Uuid\Uuid::randomHex()
    public const PAYMENT_METHOD_UUID = '1a9bc76a3c244278a51a2e90c1e6f040';

    public const VENDOR_NAME = 'lunar';

    public const PLUGIN_NAME = 'LunarPayment';

    public const PAYMENT_METHOD_ADMIN_NAME = 'Lunar Payment';
    public const PAYMENT_METHOD_NAME = 'Card';

    public const TRANSACTION_MODE = 'live';
    public const CAPTURE_MODE = 'delayed';
    public const PAYMENT_METHOD_DESCRIPTION = 'Secure payment with credit card via © Lunar';
    public const ACCEPTED_CARDS = ['visa', 'visaelectron', 'mastercard', 'maestro'];

    public const PLUGIN_CONFIG_PATH = self::PLUGIN_NAME . '.settings.';
}
