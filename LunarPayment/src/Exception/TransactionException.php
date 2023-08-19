<?php declare(strict_types=1);

namespace Lunar\Payment\Exception;

use Shopware\Core\Checkout\Payment\Exception\SyncPaymentProcessException;

/**
 * Patch exception to be handled in frontend.
 */
class TransactionException extends SyncPaymentProcessException
{
    public function __construct(
        public string $orderTransactionId, 
        public string $errorMessage, 
        public ?\Throwable $e = null, 
        private string $errorCode = ''
    ){
        parent::__construct($orderTransactionId, $errorMessage, $e);
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}