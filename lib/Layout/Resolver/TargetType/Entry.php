<?php

declare(strict_types=1);

namespace Netgen\Layouts\Contentful\Layout\Resolver\TargetType;

use Netgen\Layouts\Contentful\Entity\ContentfulEntry;
use Netgen\Layouts\Contentful\Exception\NotFoundException;
use Netgen\Layouts\Layout\Resolver\TargetTypeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints;

final class Entry implements TargetTypeInterface
{
    public static function getType(): string
    {
        return 'contentful_entry';
    }

    public function getConstraints(): array
    {
        return [
            new Constraints\NotBlank(),
        ];
    }

    public function provideValue(Request $request)
    {
        $id = $request->attributes->get('_content_id');
        if ($id === null) {
            return null;
        }

        $idList = explode(':', $id);
        if (count($idList) !== 2) {
            throw new NotFoundException(
                sprintf(
                    'Item ID %s not valid.',
                    $id
                )
            );
        }

        if ($idList[0] === ContentfulEntry::class) {
            return $idList[1];
        }

        return null;
    }
}
