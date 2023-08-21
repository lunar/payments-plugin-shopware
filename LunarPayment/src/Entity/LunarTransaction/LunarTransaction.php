<?php declare(strict_types=1);

namespace Lunar\Payment\Entity\LunarTransaction;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

/**
 * Lunar transactions model
 */
class LunarTransaction extends Entity
{
    use EntityIdTrait;

    protected string $orderId;

    protected string $transactionId;

    protected string $transactionType;

    protected string $transactionCurrency;

    protected float $orderAmount;

    protected float $transactionAmount;

    protected int $amountInMinor;

    /**
     * Get/Set orderId
     */
    public function getOrderId(): string
    {
        return $this->orderId;
    }
    public function setOrderId($orderId): void
    {
        $this->orderId = $orderId;
    }

    /**
     * Get/Set transactionId
     */
    public function getTransactionId(): string
    {
        return $this->transactionId;
    }
    public function setTransactionId($transactionId): void
    {
        $this->transactionId = $transactionId;
    }

    /**
     * Get/Set transactionType
     */
    public function getTransactionType(): string
    {
        return $this->transactionType;
    }
    public function setTransactionType($transactionType): void
    {
        $this->transactionType = $transactionType;
    }

    /**
     * Get/Set transactionCurrency
     */
    public function getTransactionCurrency(): string
    {
        return $this->transactionCurrency;
    }
    public function setTransactionCurrency($transactionCurrency): void
    {
        $this->transactionCurrency = $transactionCurrency;
    }

    /**
     * Get/Set orderAmount
     */
    public function getOrderAmount(): float
    {
        return $this->orderAmount;
    }
    public function setOrderAmount($orderAmount): void
    {
        $this->orderAmount = $orderAmount;
    }

    /**
     * Get/Set transactionAmount
     */
    public function getTransactionAmount(): float
    {
        return $this->transactionAmount;
    }
    public function setTransactionAmount($transactionAmount): void
    {
        $this->transactionAmount = $transactionAmount;
    }

    /**
     * Get/Set amountInMinor
     */
    public function getAmountInMinort(): float
    {
        return $this->amountInMinor;
    }
    public function setAmountInMinor($amountInMinor): void
    {
        $this->amountInMinor = $amountInMinor;
    }
}