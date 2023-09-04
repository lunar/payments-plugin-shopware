<?php declare(strict_types=1);

namespace Lunar\Payment\Helpers;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\System\StateMachine\Transition;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;


/**
 *
 */
class OrderHelper
{
    /**
     * Order transaction states.
     */
    public const TRANSACTION_AUTHORIZED = OrderTransactionStates::STATE_AUTHORIZED;
    public const TRANSACTION_PAID = OrderTransactionStates::STATE_PAID;
    public const TRANSACTION_REFUND = 'refund';
    public const TRANSACTION_REFUNDED = OrderTransactionStates::STATE_REFUNDED;
    public const TRANSACTION_VOID = 'cancel';
    public const TRANSACTION_VOIDED = OrderTransactionStates::STATE_CANCELLED;
    public const TRANSACTION_FAILED = OrderTransactionStates::STATE_FAILED;

    /**
     * Plugin transactions statuses
     */
    public const AUTHORIZE_STATUS = 'authorize';
    public const CAPTURE_STATUS = 'capture';
    public const REFUND_STATUS = 'refund';
    public const VOID_STATUS = 'void';
    public const FAILED_STATUS = 'failed';

    /** @var EntityRepository */
    private $orderRepository;

    /** @var EntityRepository */
    private $orderTransactionRepository;

    public function __construct(
                                EntityRepository $orderRepository,
                                EntityRepository $orderTransactionRepository
    )
    {
        $this->orderRepository = $orderRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
    }

    /**
     *
     */
    public function getOrderById(string $orderId, Context $context): ?OrderEntity
    {
        if (mb_strlen($orderId, '8bit') === 16) {
            $orderId = Uuid::fromBytesToHex($orderId);
        }

        $criteria = $this->getOrderCriteria();
        $criteria->addFilter(new EqualsFilter('id', $orderId));

        return $this->orderRepository->search($criteria, $context)->first();
    }

    /**
     *
     */
    private function getOrderCriteria(): Criteria
    {
        $criteria = new Criteria();
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('transactions.stateMachineState');
        $criteria->addAssociation('orderCustomer');
        $criteria->addAssociation('addresses');
        $criteria->addAssociation('addresses.salutation');
        $criteria->addAssociation('addresses.country');
        $criteria->addAssociation('deliveries');
        $criteria->addAssociation('deliveries.shippingMethod');
        $criteria->addAssociation('deliveries.positions');
        $criteria->addAssociation('deliveries.positions.orderLineItem');
        $criteria->addAssociation('deliveries.shippingOrderAddress');
        $criteria->addAssociation('deliveries.shippingOrderAddress.country');
        $criteria->addAssociation('deliveries.shippingOrderAddress.salutation');
        $criteria->addAssociation('deliveries.shippingOrderAddress.country');
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('currency');
        $criteria->addSorting(new FieldSorting('lineItems.createdAt'));

        return $criteria;
    }

    /**
     *
     */
    public function getTransactionById(string $transactionId, Context $context): ?OrderTransactionEntity
    {
        if (mb_strlen($transactionId, '8bit') === 16) {
            $transactionId = Uuid::fromBytesToHex($transactionId);
        }

        $criteria = $this->getTransactionCriteria();
        $criteria->addFilter(new EqualsFilter('id', $transactionId));

        return $this->orderTransactionRepository->search($criteria, $context)->first();
    }

    /**
     *
     */
    private function getTransactionCriteria(): Criteria
    {
        $criteria = new Criteria();
        $criteria->addAssociation('order');
        $criteria->addAssociation('order.currency');
        $criteria->addAssociation('order.lineItems');
        $criteria->addAssociation('order.deliveries');
        $criteria->addAssociation('paymentMethod');

        return $criteria;
    }

    /**
     *
     */
    public function getOrderTransaction(string $orderTransactionId, Context $context): ?OrderTransactionEntity
    {
        $criteria = new Criteria([$orderTransactionId]);
        $criteria->addAssociations([
            'order',
            'order.currency',
            'order.lineItems',
            'order.deliveries',
            'paymentMethod',
        ]);

        return $this->orderTransactionRepository->search($criteria, $context)->first();
    }

    /**
     *
     */
    public static function getTransactionStatuses()
    {
        return [
           self::AUTHORIZE_STATUS,
           self::CAPTURE_STATUS,
           self::REFUND_STATUS,
           self::VOID_STATUS,
           self::FAILED_STATUS,
        ];
    }

    /**
     *
     */
    public static function changeOrderState($orderId, $actionType, $context, $stateMachineRegistry)
    {
        switch (strtolower($actionType)) {
            case self::CAPTURE_STATUS:
                $stateMachineRegistry->transition(new Transition(
                    OrderDefinition::ENTITY_NAME,
                    $orderId,
                    'process',
                    'stateId'
                ), $context);
                break;
            case self::REFUND_STATUS:
                $stateMachineRegistry->transition(new Transition(
                    OrderDefinition::ENTITY_NAME,
                    $orderId,
                    'complete',
                    'stateId'
                ), $context);
                break;
            case self::VOID_STATUS:
                $stateMachineRegistry->transition(new Transition(
                    OrderDefinition::ENTITY_NAME,
                    $orderId,
                    'cancel',
                    'stateId'
                ), $context);
                break;
        }
    }
}
