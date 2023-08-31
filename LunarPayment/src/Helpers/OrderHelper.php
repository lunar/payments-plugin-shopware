<?php declare(strict_types=1);

namespace Lunar\Payment\Helpers;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\System\StateMachine\Transition;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\AndFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\System\Currency\CurrencyCollection;
use Shopware\Core\System\Currency\CurrencyEntity;

use Lunar\Payment\Service\LunarHostedCheckoutHandler;

/**
 *
 */
class OrderHelper
{
    public const PAYMENT_INTENT_KEY = '_lunar_intent_id';

    /**
     * Order transaction states.
     */
    public const TRANSACTION_OPEN = OrderTransactionStates::STATE_OPEN;
    public const TRANSACTION_AUTHORIZE = 'authorize';
    public const TRANSACTION_AUTHORIZED = OrderTransactionStates::STATE_AUTHORIZED;
    public const TRANSACTION_PAID = OrderTransactionStates::STATE_PAID;
    public const TRANSACTION_REFUND = 'refund';
    public const TRANSACTION_REFUNDED = OrderTransactionStates::STATE_REFUNDED;
    public const TRANSACTION_CANCEL = 'cancel';
    public const TRANSACTION_CANCELLED = OrderTransactionStates::STATE_CANCELLED;
    public const TRANSACTION_FAILED = OrderTransactionStates::STATE_FAILED;

    /**
     * Plugin transactions statuses
     */
    public const AUTHORIZE = 'authorize';
    public const CAPTURE = 'capture';
    public const REFUND = 'refund';
    public const CANCEL = 'cancel';
    public const FAILED = 'failed';


    public function __construct(
        private StateMachineRegistry $stateMachineRegistry,
        private EntityRepository $orderRepository,
        private EntityRepository $orderTransactionRepository,
        private EntityRepository $currencyRepository
    ) {
        $this->stateMachineRegistry = $stateMachineRegistry;
        $this->orderRepository = $orderRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->currencyRepository = $currencyRepository;
    }

    /**
     * 
     */
    public function getOrdersFrom($dateTime)
    {    
        $criteria = new Criteria();

        $criteria->addAssociations([
            'salesChannel',
            'transactions.stateMachineState',
            'transactions.paymentMethod',
        ]);

        $criteria->addFilter(
            new AndFilter([
                new EqualsFilter('transactions.stateMachineState.technicalName', self::TRANSACTION_OPEN),
                new EqualsFilter('transactions.paymentMethod.handlerIdentifier', LunarHostedCheckoutHandler::class),
                // new RangeFilter('createdAt', [RangeFilter::GTE => $dateTime->format('Y-m-d H:i:s'), RangeFilter::LTE => (new \DateTime())->format('Y-m-d H:i:s')]),
                new RangeFilter('createdAt', [RangeFilter::GT => $dateTime->format('Y-m-d H:i:s')]), // >=
            ])
        );

        return $this->orderRepository->search($criteria, Context::createDefaultContext())->getEntities();
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
    public function getOrderTransactionById(string $id, Context $context): ?OrderTransactionEntity
    {
        if (mb_strlen($id, '8bit') === 16) {
            $id = Uuid::fromBytesToHex($id);
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $id));
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
    public function getPaymentIntentFromOrder(OrderEntity $order): ?string
    {
        return $order->getCustomFields()[self::PAYMENT_INTENT_KEY] ?? ''; // getCustomFieldsValue($field) - available in SW 6.5
    }

    /**
     *
     */
    public function savePaymentIntentOnOrder(OrderEntity $order, string $paymentIntentId, Context $context): void
    {
        $oldCustomFields = $order->getCustomFields() ?? [];
        $customFields = array_merge([self::PAYMENT_INTENT_KEY => $paymentIntentId], $oldCustomFields);
        $this->orderRepository->update([
            [
                'id' => $order->getId(),
                'customFields' => $customFields,
            ]
        ], $context);
    }

    /**
     * Fallback if order currency returns null
     * 
     * @throws \Exception
     */
    public function getCurrencyCode(OrderEntity $order): string
    {
        /** @var CurrencyEntity|null $currencyEntity */
        $currencyEntity = $order->getCurrency();
        if ($currencyEntity) {
            return $order->getCurrency()->getIsoCode();
        } 

        $currencyId = $order->getCurrencyId();
        $criteria = new Criteria([$currencyId]);

        /** @var CurrencyCollection $currencyCollection */
        $currencyCollection = $this->currencyRepository->search($criteria, Context::createDefaultContext())->getEntities();

        /** @var CurrencyEntity|null $currencyEntity */
        $currencyEntity = $currencyCollection->get($currencyId);
        if ($currencyEntity === null) {
            throw new \Exception('Currency provided not found');
        }
          
        return $currencyEntity->getIsoCode();
    }

    /**
     *
     */
    public function changeOrderState($orderId, $actionType, $context)
    {
        switch ($actionType) {
            case self::CAPTURE:
                $this->stateMachineRegistry->transition(
                    new Transition(
                        OrderDefinition::ENTITY_NAME,
                        $orderId, 'process', 'stateId'
                    ), $context
                );
                break;
            case self::REFUND:
                $this->stateMachineRegistry->transition(
                    new Transition(
                        OrderDefinition::ENTITY_NAME,
                        $orderId, 'complete', 'stateId'
                    ), $context
                );
                break;
            case self::CANCEL:
                $this->stateMachineRegistry->transition(
                    new Transition(
                        OrderDefinition::ENTITY_NAME,
                        $orderId, 'cancel', 'stateId'
                    ), $context
                );
                break;
        }
    }
}
