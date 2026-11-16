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
use MagoAssistant\HyvaCms\Service\HyvaCms\ComponentCatalog;
use MagoAssistant\HyvaCms\Service\HyvaCms\ContentStore;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

/**
 * A page's or block's current component tree, in the shape save_draft takes back, plus its revision
 */
class GetAction implements ActionInterface
{
    /** Link parts the chat sees; prefix and suffix are shown so a rewrite can keep them */
    private const LINK_KEYS = ['type', 'value', 'label', 'open_in_new_tab', 'aria_label', 'prefix', 'suffix'];

    public function __construct(
        private readonly CmsEntities $entities,
        private readonly ContentStore $contentStore,
        private readonly ComponentCatalog $catalog
    ) {
    }

    public function getName(): string
    {
        return 'get';
    }

    public function getDescription(): string
    {
        return 'Read a Hyvä CMS page\'s or block\'s components and revision before changing it';
    }

    public function getParameterSchema(): array
    {
        return [
            'type' => [
                'type' => 'string',
                'enum' => CmsEntities::TYPES,
                'description' => 'cms_page (default) or cms_block',
            ],
            'id' => ['type' => 'integer', 'description' => 'CMS page or block id'],
            'identifier' => [
                'type' => 'string',
                'description' => 'Only for get: URL key of the page or identifier of the block, when the id is '
                    . 'not known',
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
        // Storefront content and its settings; field names come from the component declarations.
        return [PiiClass::ANY => [PiiClass::PUBLIC]];
    }

    public function getInstructions(): string
    {
        return 'To change it, send the whole "content" back to save_draft with your changes and the "revision". '
            . 'Every component that stays keeps its "uid" (also when it moves into a new row); only list the fields '
            . 'you change, everything else on it is kept, also settings made by hand in the editor. Components you '
            . 'delete go in "remove" by uid.';
    }

    public function execute(array $params, int $adminUserId): array
    {
        try {
            $type = $this->entities->type($params['type'] ?? null);
            $entity = $this->find($type, $params);
        } catch (LocalizedException $e) {
            return ['error' => $e->getMessage()];
        }
        $state = $this->contentStore->load($type, (int)$entity->getId());
        $document = $this->contentStore->workingDocument($state);

        return [
            'type' => $type,
            $this->entities->label($type) => $this->entities->describe($entity),
            'is_hyva_cms' => $state !== null,
            'is_live_in_hyva_cms' => (bool)($state['is_liveview_enabled'] ?? false),
            'has_unpublished_changes' => (bool)($state['has_unpublished_changes'] ?? false),
            'revision' => $state['revision'] ?? null,
            'content' => $this->summarize($type, (array)($document['content'] ?? [])),
        ];
    }

    /**
     * The page or block named by id or identifier
     *
     * @param string $type
     * @param array $params
     * @return PageInterface|BlockInterface
     * @throws LocalizedException
     */
    private function find(string $type, array $params): PageInterface|BlockInterface
    {
        if (!empty($params['id'])) {
            return $this->entities->get($type, (int)$params['id']);
        }
        $identifier = (string)($params['identifier'] ?? '');
        if ($identifier === '') {
            throw new LocalizedException(__('get needs id or identifier.'));
        }
        $matches = $this->entities->findByIdentifier($type, $identifier);
        if (count($matches) !== 1) {
            throw new LocalizedException(count($matches) === 0
                ? __('There is no CMS %1 "%2".', $this->entities->label($type), $identifier)
                : __(
                    'Several store views have a %1 "%2": ids %3. Ask which one.',
                    $this->entities->label($type),
                    $identifier,
                    implode(', ', array_map(static fn ($entity) => $entity->getId(), $matches))
                ));
        }

        return $matches[0];
    }

    /**
     * The tree with the values the chat can set, in save_draft's input shape
     *
     * @param string $type
     * @param array $nodes
     * @return array
     */
    private function summarize(string $type, array $nodes): array
    {
        $summary = [];
        foreach ($nodes as $node) {
            if (!is_array($node) || !isset($node['uid'], $node['component'])) {
                continue;
            }
            $declaration = $this->catalog->get((string)$node['component'], $type);
            $item = ['uid' => (string)$node['uid'], 'component' => (string)$node['component']];
            if ($declaration === null) {
                $item['editable'] = false;
                $summary[] = $item;
                continue;
            }
            if ($this->catalog->getVariants($declaration) !== [] && isset($node['variants'])) {
                $item['variant'] = $node['variants'];
            }
            $fields = [];
            foreach (array_keys($this->catalog->getFields($declaration)) as $name) {
                if (array_key_exists($name, $node)) {
                    $fields[$name] = $this->summarizeValue($node[$name]);
                }
            }
            $item['fields'] = $fields;
            if (!empty($node['children'])) {
                $item['children'] = $this->summarize($type, (array)$node['children']);
            }
            $summary[] = $item;
        }

        return $summary;
    }

    private function summarizeValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        // A link has a type; the editor stores a src on links too, so src alone does not mean image.
        if (isset($value['type'])) {
            return array_intersect_key($value, array_flip(self::LINK_KEYS));
        }

        return isset($value['src']) ? ['path' => $value['src'], 'alt' => $value['alt'] ?? ''] : $value;
    }
}
