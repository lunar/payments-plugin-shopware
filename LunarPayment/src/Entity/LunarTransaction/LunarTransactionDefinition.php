<?php declare(strict_types=1);

namespace Lunar\Payment\Entity\LunarTransaction;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;

use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\CascadeDelete;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;

use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;

/**
 * Define Lunar transactions model
 */
class LunarTransactionDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'lunar_transaction';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return LunarTransactionCollection::class;
    }

    public function getEntityClass(): string
    {
        return LunarTransaction::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),

            (new StringField('order_id', 'orderId'))->addFlags(new Required(),  new CascadeDelete()),

            (new StringField('transaction_id', 'transactionId'))->addFlags(new Required()),

            (new StringField('transaction_type', 'transactionType'))->addFlags(new Required()),

            (new StringField('transaction_currency', 'transactionCurrency'))->addFlags(new Required()),

            (new FloatField('order_amount', 'orderAmount'))->addFlags(new Required()),

            (new FloatField('transaction_amount', 'transactionAmount'))->addFlags(new Required()),

            /** Foreign key on Orders */
            (new FkField('order_id', 'orderId', OrderDefinition::class))->addFlags(new Required()),

            /** Many transactions to one order */
            new ManyToOneAssociationField('order', 'order_id', OrderDefinition::class, 'id', false),

            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}