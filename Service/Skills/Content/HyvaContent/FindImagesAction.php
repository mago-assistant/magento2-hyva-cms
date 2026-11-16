<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\HyvaCms\Service\Skills\Content\HyvaContent;

use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\MediaGalleryApi\Api\SearchAssetsInterface;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

/**
 * Images already in the media gallery, the only ones a page built from the chat may use
 */
class FindImagesAction implements ActionInterface
{
    private const LIMIT = 20;

    public function __construct(
        private readonly SearchAssetsInterface $searchAssets,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly FilterBuilder $filterBuilder
    ) {
    }

    public function getName(): string
    {
        return 'find_images';
    }

    public function getDescription(): string
    {
        return 'Search the media gallery for an image to use';
    }

    public function getParameterSchema(): array
    {
        return [
            'query' => [
                'type' => 'string',
                'description' => 'Only for find_images: word in the image title or file name; empty lists the first 20',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return null;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function getFieldClassification(): array
    {
        return [
            'images' => [PiiClass::PUBLIC],
            'note' => [PiiClass::PUBLIC],
            'path' => [PiiClass::PUBLIC],
            'title' => [PiiClass::PUBLIC],
            'width' => [PiiClass::PUBLIC],
            'height' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return 'Use an image as {"path": "<path>", "alt": "<what the image shows>"}. Images that are not in the '
            . 'media gallery cannot be used; the administrator uploads them in Content > Media Gallery first.';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $query = trim((string)($params['query'] ?? ''));
        $images = $this->search($query);
        if ($images !== [] || $query === '') {
            return ['images' => $images];
        }

        // A word the file names do not contain should not end the search: show what there is.
        return [
            'note' => 'No image matched "' . $query . '"; these are the images in the media gallery.',
            'images' => $this->search(''),
        ];
    }

    /**
     * Images whose title or path contains the query, all images for an empty query
     *
     * @param string $query
     * @return array
     */
    private function search(string $query): array
    {
        if ($query !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';
            $this->searchCriteriaBuilder->addFilters([
                $this->filterBuilder->setField('title')->setConditionType('like')->setValue($like)->create(),
                $this->filterBuilder->setField('path')->setConditionType('like')->setValue($like)->create(),
            ]);
        }
        $criteria = $this->searchCriteriaBuilder->setPageSize(self::LIMIT)->create();

        $images = [];
        foreach ($this->searchAssets->execute($criteria) as $asset) {
            if (!str_starts_with((string)$asset->getContentType(), 'image')) {
                continue;
            }
            $images[] = [
                'path' => $asset->getPath(),
                'title' => (string)$asset->getTitle(),
                'width' => $asset->getWidth(),
                'height' => $asset->getHeight(),
            ];
        }

        return $images;
    }
}
