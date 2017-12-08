<?php

namespace Netgen\BlockManager\Contentful\Layout\Resolver\TargetHandler\Doctrine;

use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Type;
use Netgen\BlockManager\Persistence\Doctrine\QueryHandler\TargetHandlerInterface;

final class Space implements TargetHandlerInterface
{
    public function handleQuery(QueryBuilder $query, $value)
    {
        $query->andWhere(
            $query->expr()->eq('rt.value', ':target_value')
        )
            ->setParameter('target_value', $value, Type::STRING);
    }
}
