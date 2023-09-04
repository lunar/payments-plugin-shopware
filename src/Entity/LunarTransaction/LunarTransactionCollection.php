<?php declare(strict_types=1);

namespace Lunar\Payment\Entity\LunarTransaction;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * Manage Lunar transactions collection
 *
 * @method void                   add(LunarTransaction $entity)
 * @method void                   set(string $key, LunarTransaction $entity)
 * @method LunarTransaction[]    getIterator()
 * @method LunarTransaction[]    getElements()
 * @method LunarTransaction|null get(string $key)
 * @method LunarTransaction|null first()
 * @method LunarTransaction|null last()
 */
class LunarTransactionCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return LunarTransaction::class;
    }
}