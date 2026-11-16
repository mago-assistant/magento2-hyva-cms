<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\HyvaCms\Service\Skills\Content\HyvaContent;

use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Framework\Exception\LocalizedException;
use MagoAssistant\HyvaCms\Service\HyvaCms\CmsEntities;
use MagoAssistant\HyvaCms\Service\HyvaCms\ContentStore;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Api\Skill\CautionedActionInterface;
use MagoAssistant\Mago\Api\Skill\ValidatingActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

/**
 * Puts a page's or block's saved draft live, exactly as stored
 *
 * The revision ties the publish to the draft the chat saved: a draft changed since (in the editor or
 * another chat) is refused instead of published unseen.
 */
class PublishAction implements ActionInterface, ValidatingActionInterface, CautionedActionInterface
{
    public function __construct(
        private readonly ContentStore $contentStore,
        private readonly CmsEntities $entities,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'publish';
    }

    public function getDescription(): string
    {
        return 'Publish a page\'s or block\'s saved draft to the storefront';
    }

    public function getParameterSchema(): array
    {
        return [
            'type' => [
                'type' => 'string',
                'enum' => CmsEntities::TYPES,
                'description' => 'cms_page (default) or cms_block',
            ],
            'id' => [
                'type' => 'integer',
                'description' => 'For get, save_draft and publish: the page or block id. Leave out with "new".',
            ],
            'revision' => [
                'type' => 'string',
                'description' => 'For save_draft on existing content and for publish: the revision from get or the '
                    . 'last save_draft',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return null;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function getFieldClassification(): array
    {
        return [
            'success' => [PiiClass::PUBLIC],
            'message' => [PiiClass::PUBLIC],
            'type' => [PiiClass::PUBLIC],
            'id' => [PiiClass::PUBLIC],
            'is_active' => [PiiClass::PUBLIC],
            'storefront_urls' => [PiiClass::PUBLIC],
            'label' => [PiiClass::PUBLIC],
            'url' => [PiiClass::TOKENISE, 'url'],
        ];
    }

    public function getInstructions(): string
    {
        return 'Only publish after the user asked for it, after seeing the draft. If it is disabled, say it stays '
            . 'hidden until it is enabled (cms_data update_page or update_block with is_active). A block shows '
            . 'wherever it is placed (a widget, a layout or another page).';
    }

    public function getCautions(array $params, int $adminUserId): array
    {
        try {
            [$type, $entity, $state] = $this->prepare($params);
        } catch (LocalizedException) {
            return [];
        }
        $label = $this->entities->label($type);
        $cautions = [sprintf(
            'Replaces the live content of %s "%s" (%s) with the saved draft, in %s.',
            $label,
            $entity->getTitle(),
            $entity->getIdentifier(),
            $this->entities->describeStores($entity)
        )];
        if ($type === CmsEntities::BLOCK) {
            $cautions[] = 'A block shows everywhere it is used, on every page that includes it.';
        }
        // Only when there is classic content to lose; new content from the chat has none.
        if (!$state['is_liveview_enabled'] && trim(strip_tags((string)$entity->getContent())) !== '') {
            $cautions[] = sprintf(
                'Hyvä CMS is switched off for this %s now: publishing switches it from its classic content to '
                . 'this Hyvä CMS content.',
                $label
            );
        }
        if (!$entity->isActive()) {
            $cautions[] = sprintf('The %s itself is disabled and stays hidden until it is enabled.', $label);
        }

        return $cautions;
    }

    public function findRefusal(array $params): ?array
    {
        try {
            $this->prepare($params);
        } catch (LocalizedException $e) {
            return ['error' => $e->getMessage()];
        }

        return null;
    }

    public function execute(array $params, int $adminUserId): array
    {
        try {
            [$type, $entity, $state] = $this->prepare($params);
            $id = (int)$entity->getId();
            $this->contentStore->publish($type, $id, $state['draft'], (string)$params['revision'], $adminUserId);
        } catch (LocalizedException $e) {
            return ['error' => $e->getMessage()];
        }
        $label = $this->entities->label($type);

        return [
            'success' => true,
            'message' => 'Published. The full-page cache for this ' . $label . ' was cleaned.'
                . ($entity->isActive() ? '' : ' The ' . $label . ' is disabled, so visitors do not see it until it '
                    . 'is enabled.'),
            'type' => $type,
            'id' => $id,
            'is_active' => (bool)$entity->isActive(),
            'storefront_urls' => $entity->isActive() ? $this->entities->storefrontUrls($entity) : [],
            '_links' => [[
                'label' => 'Open in Hyvä CMS editor',
                'url' => $this->secureAdminUrl->getUrl('liveview/editor/index', ['type' => $type, 'id' => $id]),
            ]],
        ];
    }

    /**
     * The type, the page or block and its stored state, after every check
     *
     * @param array $params
     * @return array{0: string, 1: PageInterface|BlockInterface, 2: array}
     * @throws LocalizedException
     */
    private function prepare(array $params): array
    {
        $type = $this->entities->type($params['type'] ?? null);
        $entity = $this->entities->get($type, (int)($params['id'] ?? 0));
        $state = $this->contentStore->assertRevision($type, (int)$entity->getId(), $params['revision'] ?? null);
        if ($state === null || $state['draft'] === null) {
            throw new LocalizedException(__(
                'This %1 has no Hyvä CMS draft to publish.',
                $this->entities->label($type)
            ));
        }
        if (!$state['has_unpublished_changes'] && $state['is_liveview_enabled']) {
            throw new LocalizedException(__('The draft is already live; there is nothing to publish.'));
        }

        return [$type, $entity, $state];
    }
}
