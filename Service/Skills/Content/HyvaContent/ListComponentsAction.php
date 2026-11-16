<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\HyvaCms\Service\Skills\Content\HyvaContent;

use Magento\Framework\Exception\LocalizedException;
use MagoAssistant\HyvaCms\Service\HyvaCms\CmsEntities;
use MagoAssistant\HyvaCms\Service\HyvaCms\ComponentCatalog;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

/**
 * The components a page or block can be built from: a short list, or the fields of the ones named
 */
class ListComponentsAction implements ActionInterface
{
    public function __construct(
        private readonly ComponentCatalog $catalog,
        private readonly CmsEntities $entities
    ) {
    }

    public function getName(): string
    {
        return 'list_components';
    }

    public function getDescription(): string
    {
        return 'List the components the content type can use; pass names to get their fields';
    }

    public function getParameterSchema(): array
    {
        return [
            'type' => [
                'type' => 'string',
                'enum' => CmsEntities::TYPES,
                'description' => 'cms_page (default) or cms_block',
            ],
            'names' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Only for list_components: component names to return with their fields and variants',
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
        // Component declarations: store configuration, keys follow the declarations.
        return [PiiClass::ANY => [PiiClass::PUBLIC]];
    }

    public function getInstructions(): string
    {
        return 'Pick components by their description, then call list_components again with the names you need '
            . 'to see their fields before writing the content. "children" says what a component may contain; '
            . 'require_parent components only go inside one that accepts them.';
    }

    public function execute(array $params, int $adminUserId): array
    {
        try {
            $type = $this->entities->type($params['type'] ?? null);
        } catch (LocalizedException $e) {
            return ['error' => $e->getMessage()];
        }
        $names = array_filter((array)($params['names'] ?? []), 'is_string');
        $components = [];
        foreach ($this->catalog->getAll($type) as $name => $declaration) {
            if ($names !== [] && !in_array($name, $names, true)) {
                continue;
            }
            $components[] = $this->catalog->describe($name, $declaration, $names !== []);
        }
        $unknown = array_values(array_diff($names, array_column($components, 'name')));

        return array_filter([
            'components' => $components,
            'not_available' => $unknown,
        ]);
    }
}
