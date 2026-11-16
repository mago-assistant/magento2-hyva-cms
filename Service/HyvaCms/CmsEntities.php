<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\HyvaCms\Service\HyvaCms;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Api\Data\BlockInterfaceFactory;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\Data\PageInterfaceFactory;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The two content types the chat builds with Hyvä CMS: CMS pages and CMS blocks
 *
 * Everything that differs between them lives here: the Magento repository, the Hyvä CMS ACL
 * resources (Stores > Permissions > Hyvä CMS > Pages / Blocks), and what a new one needs.
 */
class CmsEntities
{
    public const PAGE = 'cms_page';
    public const BLOCK = 'cms_block';
    public const TYPES = [self::PAGE, self::BLOCK];

    public const VIEW = 'view';
    public const SAVE = 'save';
    public const PUBLISH = 'publish';
    public const CREATE = 'create';

    private const ACL = [
        self::PAGE => [
            self::VIEW => 'Hyva_Cms::page_view',
            self::SAVE => 'Hyva_Cms::page_save',
            self::PUBLISH => 'Hyva_Cms::page_publish',
            self::CREATE => 'Magento_Cms::save',
        ],
        self::BLOCK => [
            self::VIEW => 'Hyva_Cms::block_view',
            self::SAVE => 'Hyva_Cms::block_save',
            self::PUBLISH => 'Hyva_Cms::block_publish',
            self::CREATE => 'Magento_Cms::block',
        ],
    ];
    private const LABELS = [self::PAGE => 'page', self::BLOCK => 'block'];
    private const IDENTIFIER_PATTERN = '/^[a-z0-9][a-z0-9_\/-]{0,99}$/';

    public function __construct(
        private readonly PageRepositoryInterface $pageRepository,
        private readonly PageInterfaceFactory $pageFactory,
        private readonly BlockRepositoryInterface $blockRepository,
        private readonly BlockInterfaceFactory $blockFactory,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * The ACL resource for doing $verb to a type; an unknown type gets the page's (fail closed per verb)
     *
     * @param string $type
     * @param string $verb One of VIEW, SAVE, PUBLISH, CREATE
     * @return string
     */
    public function acl(string $type, string $verb): string
    {
        return self::ACL[$type][$verb] ?? self::ACL[self::PAGE][$verb];
    }

    /**
     * The type the chat named, cms_page when it named none
     *
     * @param mixed $type
     * @return string
     * @throws LocalizedException
     */
    public function type(mixed $type): string
    {
        $type ??= self::PAGE;
        if (!in_array($type, self::TYPES, true)) {
            throw new LocalizedException(__('"type" must be one of: %1.', implode(', ', self::TYPES)));
        }

        return $type;
    }

    public function label(string $type): string
    {
        return self::LABELS[$type];
    }

    /**
     * The page or block with this id
     *
     * @param string $type
     * @param int $id
     * @return PageInterface|BlockInterface
     * @throws LocalizedException
     */
    public function get(string $type, int $id): PageInterface|BlockInterface
    {
        try {
            return $type === self::BLOCK ? $this->blockRepository->getById($id) : $this->pageRepository->getById($id);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('There is no CMS %1 with id %2.', $this->label($type), $id));
        }
    }

    /**
     * Every page or block with this identifier, in any store view
     *
     * @param string $type
     * @param string $identifier
     * @return array<PageInterface|BlockInterface>
     */
    public function findByIdentifier(string $type, string $identifier): array
    {
        $criteria = $this->searchCriteriaBuilder->addFilter('identifier', $identifier)->create();
        $repository = $type === self::BLOCK ? $this->blockRepository : $this->pageRepository;

        return array_values($repository->getList($criteria)->getItems());
    }

    /**
     * Refuse what a new page or block may not be: no title, a bad or taken identifier, an unknown store
     *
     * @param string $type
     * @param array $new {title, identifier, store_ids}
     * @return void
     * @throws LocalizedException
     */
    public function assertCanCreate(string $type, array $new): void
    {
        $label = $this->label($type);
        $identifier = (string)($new['identifier'] ?? '');
        if (trim((string)($new['title'] ?? '')) === '' || !preg_match(self::IDENTIFIER_PATTERN, $identifier)) {
            throw new LocalizedException(__(
                '"new" needs a title and an identifier of lowercase letters, numbers, hyphens and slashes.'
            ));
        }
        if ($this->findByIdentifier($type, $identifier) !== []) {
            throw new LocalizedException(__(
                'A %1 with identifier "%2" already exists. An existing identifier is never taken over; choose '
                . 'another one, or edit that %1 with get and save_draft.',
                $label,
                $identifier
            ));
        }
        foreach ($this->storeIds($new) as $storeId) {
            try {
                $this->storeManager->getStore($storeId);
            } catch (NoSuchEntityException) {
                throw new LocalizedException(__('There is no store view with id %1.', $storeId));
            }
        }
    }

    /**
     * Create a page or block, disabled, without content of its own
     *
     * @param string $type
     * @param array $new {title, identifier, store_ids}
     * @return PageInterface|BlockInterface
     * @throws LocalizedException
     */
    public function create(string $type, array $new): PageInterface|BlockInterface
    {
        $entity = $type === self::BLOCK ? $this->blockFactory->create() : $this->pageFactory->create();
        $entity->setTitle(trim((string)$new['title']));
        $entity->setIdentifier((string)$new['identifier']);
        $entity->setContent('');
        $entity->setIsActive(false);
        $entity->setData('store_id', $this->storeIds($new));

        return $type === self::BLOCK ? $this->blockRepository->save($entity) : $this->pageRepository->save($entity);
    }

    /**
     * Remove a page or block this request created, when its first draft could not be saved
     *
     * @param PageInterface|BlockInterface $entity
     * @return void
     * @throws LocalizedException
     */
    public function delete(PageInterface|BlockInterface $entity): void
    {
        if ($entity instanceof BlockInterface) {
            $this->blockRepository->delete($entity);
            return;
        }
        $this->pageRepository->delete($entity);
    }

    /**
     * What the chat shows of a page or block
     *
     * @param PageInterface|BlockInterface $entity
     * @return array
     */
    public function describe(PageInterface|BlockInterface $entity): array
    {
        return [
            'id' => (int)$entity->getId(),
            'title' => (string)$entity->getTitle(),
            'identifier' => (string)$entity->getIdentifier(),
            'is_active' => (bool)$entity->isActive(),
            'store_ids' => $this->assignedStoreIds($entity),
        ];
    }

    /**
     * Store views a page or block is in, empty for all
     *
     * @param PageInterface|BlockInterface $entity
     * @return int[]
     */
    public function assignedStoreIds(PageInterface|BlockInterface $entity): array
    {
        $storeIds = array_map('intval', (array)$entity->getData('store_id'));

        return in_array(0, $storeIds, true) ? [] : $storeIds;
    }

    /**
     * "all store views", or the names of the ones it is in
     *
     * @param PageInterface|BlockInterface $entity
     * @return string
     */
    public function describeStores(PageInterface|BlockInterface $entity): string
    {
        $storeIds = $this->assignedStoreIds($entity);
        if ($storeIds === []) {
            return 'all store views';
        }

        return 'store view(s) ' . implode(', ', array_map(
            fn (int $id) => $this->storeManager->getStore($id)->getName(),
            $storeIds
        ));
    }

    /**
     * A page's address in each store view it is in; blocks have none of their own
     *
     * @param PageInterface|BlockInterface $entity
     * @return string[]
     */
    public function storefrontUrls(PageInterface|BlockInterface $entity): array
    {
        if (!$entity instanceof PageInterface) {
            return [];
        }
        $storeIds = $this->assignedStoreIds($entity);
        $stores = $storeIds === []
            ? $this->storeManager->getStores()
            : array_map(fn (int $id) => $this->storeManager->getStore($id), $storeIds);

        return array_values(array_unique(array_map(
            static fn ($store) => $store->getBaseUrl() . $entity->getIdentifier(),
            $stores
        )));
    }

    /**
     * Store views of new content, all (0) when none are given
     *
     * @param array $new
     * @return int[]
     */
    private function storeIds(array $new): array
    {
        $storeIds = array_map('intval', (array)($new['store_ids'] ?? []));

        return $storeIds ?: [0];
    }
}
