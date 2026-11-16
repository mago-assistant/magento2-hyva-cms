<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\HyvaCms\Service\Skills\Content\HyvaContent;

use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\LocalizedException;
use MagoAssistant\HyvaCms\Service\HyvaCms\CmsEntities;
use MagoAssistant\HyvaCms\Service\HyvaCms\ContentStore;
use MagoAssistant\HyvaCms\Service\HyvaCms\TreeBuilder;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Api\Skill\ValidatingActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

/**
 * Saves the page or block the chat describes as a Hyvä CMS draft; the live content does not change
 *
 * New content is created disabled first. Everything, Hyvä's own guard included, is checked before the
 * confirmation card (findRefusal) and again before saving, against the content as it is at that moment.
 */
class SaveDraftAction implements ActionInterface, ValidatingActionInterface
{
    public function __construct(
        private readonly TreeBuilder $treeBuilder,
        private readonly ContentStore $contentStore,
        private readonly CmsEntities $entities,
        private readonly AuthorizationInterface $authorization,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'save_draft';
    }

    public function getDescription(): string
    {
        return 'Save a page or block as a draft (new, or existing Hyvä CMS content); publishing is a separate step';
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
            'new' => [
                'type' => 'object',
                'description' => 'Only for save_draft, to create the page or block (it starts disabled)',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'identifier' => [
                        'type' => 'string',
                        'description' => 'URL key of a page, identifier of a block, e.g. "summer-sale"',
                    ],
                    'store_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'Store views; [0] (default) is all',
                    ],
                ],
            ],
            'revision' => [
                'type' => 'string',
                'description' => 'For save_draft on existing content and for publish: the revision from get or the '
                    . 'last save_draft',
            ],
            'remove' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Only for save_draft on existing content: uids of components to delete. A current '
                    . 'component that is neither in content nor here is refused, never dropped silently.',
            ],
            'content' => [
                'type' => 'array',
                'description' => 'Only for save_draft: all components, top level first. Each item: {"uid": existing '
                    . 'uid or leave out for a new component, "component": name, "variant": optional, "fields": '
                    . '{field: value}, "children": [items]}',
                'items' => ['type' => 'object'],
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
            'title' => [PiiClass::PUBLIC],
            'identifier' => [PiiClass::PUBLIC],
            'is_active' => [PiiClass::PUBLIC],
            'revision' => [PiiClass::PUBLIC],
            // Signed: the hash lets anyone with the link see the unpublished draft for a week.
            'preview_url' => [PiiClass::TOKENISE, 'url'],
            'label' => [PiiClass::PUBLIC],
            'url' => [PiiClass::TOKENISE, 'url'],
        ];
    }

    public function getInstructions(): string
    {
        return 'The draft is saved, not live. Give the preview link and ask whether to publish; publishing is the '
            . 'publish action with the returned revision and needs its own confirmation. If the draft was refused, '
            . 'fix what the message names and propose it again.';
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
            [$type, $entity, $document] = $this->prepare($params);
            $created = $entity === null;
            $entity ??= $this->entities->create($type, (array)$params['new']);
            $id = (int)$entity->getId();
            try {
                $revision = $this->contentStore->saveDraft(
                    $type,
                    $id,
                    $document,
                    $created ? null : (string)$params['revision'],
                    $adminUserId
                );
            } catch (LocalizedException $e) {
                // Leave nothing behind: an empty disabled page or block would block a retry with its identifier.
                if ($created) {
                    $this->entities->delete($entity);
                }
                throw $e;
            }
        } catch (LocalizedException $e) {
            return ['error' => $e->getMessage()];
        }

        $label = $this->entities->label($type);
        $result = [
            'success' => true,
            'message' => 'Draft saved. The live ' . $label . ' did not change.'
                . ($entity->isActive() ? '' : ' The ' . $label . ' is disabled, so it is not on the storefront yet.'),
            'type' => $type,
            'revision' => $revision,
            '_links' => [[
                'label' => 'Open in Hyvä CMS editor',
                'url' => $this->secureAdminUrl->getUrl('liveview/editor/index', ['type' => $type, 'id' => $id]),
            ]],
        ] + $this->entities->describe($entity);
        unset($result['store_ids']);
        if ($previewUrl = $this->contentStore->previewUrl($type, $id)) {
            $result['preview_url'] = $previewUrl;
        }

        return $result;
    }

    /**
     * Everything checked, nothing written
     *
     * @param array $params
     * @return array{0: string, 1: PageInterface|BlockInterface|null, 2: array} Type, existing entity (null
     *         when new), the document to store
     * @throws LocalizedException
     */
    private function prepare(array $params): array
    {
        $type = $this->entities->type($params['type'] ?? null);
        if (!isset($params['content']) || !is_array($params['content'])) {
            throw new LocalizedException(__('save_draft needs "content": the components.'));
        }
        if (!empty($params['id']) === !empty($params['new'])) {
            throw new LocalizedException(__('save_draft needs either id (existing content) or new, not both.'));
        }

        if (!empty($params['new'])) {
            if (!$this->authorization->isAllowed($this->entities->acl($type, CmsEntities::CREATE))) {
                throw new LocalizedException(__(
                    'You do not have permission to create a CMS %1.',
                    $this->entities->label($type)
                ));
            }
            $this->entities->assertCanCreate($type, (array)$params['new']);
            $content = $this->treeBuilder->build($params['content'], [], [], $type);

            return [$type, null, $this->contentStore->draftDocument($type, 0, [], $content)];
        }

        $id = (int)$params['id'];
        $entity = $this->entities->get($type, $id);
        $state = $this->contentStore->assertRevision($type, $id, $params['revision'] ?? null);
        if ($state === null) {
            // Classic content: a Hyvä publish would replace it. That conversion stays a decision made in the editor.
            throw new LocalizedException(__(
                'This %1 is not built with Hyvä CMS. The chat only edits Hyvä CMS content; open it in the Hyvä CMS '
                . 'editor first to convert it.',
                $this->entities->label($type)
            ));
        }
        $current = $this->contentStore->workingDocument($state);
        $content = $this->treeBuilder->build(
            $params['content'],
            (array)($current['content'] ?? []),
            array_filter((array)($params['remove'] ?? []), 'is_scalar'),
            $type
        );

        return [$type, $entity, $this->contentStore->draftDocument($type, $id, $current, $content)];
    }
}
