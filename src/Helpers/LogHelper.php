<?php declare(strict_types=1);

namespace Lunar\Payment\Helpers;

/**
 *
 */
class LogHelper
{
    const DS = DIRECTORY_SEPARATOR;
    const LOGS_FILE_NAME =  '/var/log/' . PluginHelper::VENDOR_NAME . '.log';
    const LOGS_DATE_FORMAT = "Y-m-d  h:i:s";

    /**
     *
     * @param mixed $data
     * @return void
     */
    public function writeLog($data): void
    {
        $date = date(self::LOGS_DATE_FORMAT, time());

        $separator = PHP_EOL . PHP_EOL . "=========================================================================" . PHP_EOL;

        $fileNamePath = dirname(__DIR__, 5) . self::LOGS_FILE_NAME;

        file_put_contents($fileNamePath, $separator . '>>>>>>  ' . $date . '  <<<<<< '. PHP_EOL . json_encode($data, JSON_PRETTY_PRINT), FILE_APPEND);
    }
}
