<?php declare(strict_types=1);

namespace Lunar\Payment\Helpers;

use Lunar\Payment\Helpers\PluginHelper;

/**
 *
 */
class LogHelper
{
    const DS = DIRECTORY_SEPARATOR;
    const LOGS_FILE_NAME =  '/var/log/' . PluginHelper::VENDOR_NAME . '.log';
    const LOGS_DATE_FORMAT = "Y-m-d  h:i:s";

    public function __construct(
        private PluginHelper $pluginHelper
    ) {
        $this->pluginHelper = $pluginHelper;
    }

    /**
     *
     * @param mixed $data
     * @return void
     */
    public function writeLog($data, $prettyPrint = true): void
    {
        if (!$this->pluginHelper->getSalesChannelConfig('logsEnabled', '')) {
            return;
        }

        $date = date(self::LOGS_DATE_FORMAT, time());

        // $separator = PHP_EOL . PHP_EOL . "=========================================================================" . PHP_EOL;

        $fileNamePath = dirname(__DIR__, 5) . self::LOGS_FILE_NAME;

        $contents = is_array($data) ? json_encode($data, $prettyPrint ? JSON_PRETTY_PRINT : 0) : $data;

        file_put_contents($fileNamePath, /*$separator .*/ PHP_EOL . "[$date] lunar.INFO: ". $contents, FILE_APPEND);
    }
}
