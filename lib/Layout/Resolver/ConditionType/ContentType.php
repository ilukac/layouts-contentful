<?php

declare(strict_types=1);

namespace Netgen\Layouts\Contentful\Layout\Resolver\ConditionType;

use Netgen\BlockManager\Layout\Resolver\ConditionTypeInterface;
use Netgen\Layouts\Contentful\Entity\ContentfulEntry;
use Netgen\Layouts\Contentful\Service\Contentful;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints;
use Throwable;

final class ContentType implements ConditionTypeInterface
{
    /**
     * @var \Netgen\Layouts\Contentful\Service\Contentful
     */
    private $contentful;

    public function __construct(Contentful $contentful)
    {
        $this->contentful = $contentful;
    }

    public static function getType(): string
    {
        return 'contentful_content_type';
    }

    public function getConstraints(): array
    {
        return [
            new Constraints\NotBlank(),
            new Constraints\Type(['type' => 'array']),
        ];
    }

    public function matches(Request $request, $value): bool
    {
        if (!is_array($value) || empty($value)) {
            return false;
        }

        $contentId = $request->attributes->get('_content_id');
        if ($contentId === null) {
            return false;
        }

        $contentIds = explode(':', $contentId);
        if (count($contentIds) !== 2) {
            return false;
        }

        if ($contentIds[0] !== ContentfulEntry::class) {
            return false;
        }

        try {
            $contentfulEntry = $this->contentful->loadContentfulEntry($contentIds[1]);
        } catch (Throwable $t) {
            return false;
        }

        return in_array($contentfulEntry->getContentType()->getId(), $value, true);
    }
}
