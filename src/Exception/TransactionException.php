<?php declare(strict_types=1);

namespace Lunar\Payment\Exception;

use Shopware\Core\Checkout\Payment\Exception\SyncPaymentProcessException;

/**
 * Patch exception to be handled in frontend.
 */
class TransactionException extends SyncPaymentProcessException
{
    private string $errorCode;

    public function __construct(string $orderTransactionId, string $errorMessage, ?\Throwable $e = null, string $errorCode = '')
    {
        parent::__construct($orderTransactionId, $errorMessage, $e);
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}