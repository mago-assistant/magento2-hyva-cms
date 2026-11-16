<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\HyvaCms\Service\HyvaCms;

use Hyva\CmsLiveviewEditor\Model\Component\Provider as ComponentProvider;
use Hyva\CmsLiveviewEditor\ViewModel\ContentTypeAvailabilityMap;

/**
 * The Hyvä CMS components, and their fields, that the chat may place and fill
 *
 * Built on the editor's own merged list (file components plus active instance components, with
 * disabled ones already removed). What the chat may not touch:
 * - hidden components and those in EXCLUDED: raw HTML, third-party embeds and widget directives can
 *   carry scripts, which a CSP-strict theme must not get from generated content;
 * - the "advanced" section (free CSS classes and block ids): classes outside the component templates
 *   only render after the editor's in-browser Tailwind compile, which a chat save never runs;
 * - field types outside SUPPORTED_TYPES (product, category and entity pickers, custom types).
 *   Existing values of such fields are kept as they are;
 * - instance components whose available_for leaves out the content type (cms_page, cms_block), the
 *   same rule the editor's component picker applies.
 */
class ComponentCatalog
{
    public const SUPPORTED_TYPES = [
        'text', 'textarea', 'richtext', 'number', 'range', 'boolean', 'select', 'searchable_select',
        'text-align', 'color', 'url', 'link', 'image',
    ];
    public const TEXT_ALIGN_OPTIONS = ['text-left', 'text-center', 'text-right'];

    private const EXCLUDED = ['html', 'embed', 'widget'];
    private const SECTIONS = ['content', 'design'];
    private const OPTION_TYPES = ['select', 'searchable_select'];
    private const DESCRIPTION_LENGTH = 200;

    public function __construct(
        private readonly ComponentProvider $componentProvider,
        private readonly ContentTypeAvailabilityMap $availabilityMap
    ) {
    }

    /**
     * Declarations of the components the chat may place in a content type, by name
     *
     * @param string $contentType cms_page or cms_block
     * @return array<string, array>
     */
    public function getAll(string $contentType): array
    {
        $token = $this->availabilityMap->get()[$contentType] ?? $contentType;

        return array_filter(
            $this->componentProvider->getComponents(),
            fn (array $declaration, string $name) => $this->isUsable($name, $declaration)
                && $this->isAvailableFor($declaration, $token),
            ARRAY_FILTER_USE_BOTH
        );
    }

    public function get(string $name, string $contentType): ?array
    {
        return $this->getAll($contentType)[$name] ?? null;
    }

    /**
     * The fields the chat may set on a component, by field name
     *
     * @param array $declaration
     * @return array<string, array>
     */
    public function getFields(array $declaration): array
    {
        $fields = [];
        foreach (self::SECTIONS as $section) {
            foreach ($declaration[$section] ?? [] as $name => $field) {
                if ($name === 'variants' || !is_array($field) || !$this->isSupported($field)) {
                    continue;
                }
                $fields[$name] = $field;
            }
        }

        return $fields;
    }

    /**
     * Template paths a component offers as variants, empty when it has none
     *
     * @param array $declaration
     * @return string[]
     */
    public function getVariants(array $declaration): array
    {
        return array_values(array_map(
            static fn (array $option) => (string)($option['value'] ?? ''),
            array_filter($declaration['content']['variants']['options'] ?? [], 'is_array')
        ));
    }

    /**
     * Allowed values of a choice field
     *
     * @param array $field
     * @return string[]
     */
    public function getOptions(array $field): array
    {
        if (($field['type'] ?? '') === 'text-align') {
            return self::TEXT_ALIGN_OPTIONS;
        }

        return array_values(array_map(
            static fn (array $option) => (string)($option['value'] ?? ''),
            array_filter((array)($field['options'] ?? []), 'is_array')
        ));
    }

    /**
     * What the model needs to place a component: placement rules, and the fields when asked for
     *
     * @param string $name
     * @param array $declaration
     * @param bool $withFields
     * @return array
     */
    public function describe(string $name, array $declaration, bool $withFields): array
    {
        $description = [
            'name' => $name,
            'label' => (string)($declaration['label'] ?? $name),
            'category' => (string)($declaration['category'] ?? ''),
            'description' => mb_strimwidth(
                (string)($declaration['description'] ?? ''),
                0,
                self::DESCRIPTION_LENGTH,
                '…'
            ),
            'children' => $this->describeChildren($declaration['children'] ?? false),
        ];
        foreach (['root_only', 'require_parent'] as $flag) {
            if (!empty($declaration[$flag])) {
                $description[$flag] = true;
            }
        }
        if (!$withFields) {
            return $description;
        }

        if ($variants = $this->getVariants($declaration)) {
            $description['variants'] = $variants;
        }
        $description['fields'] = [];
        foreach ($this->getFields($declaration) as $fieldName => $field) {
            $description['fields'][$fieldName] = $this->describeField($field);
        }

        return $description;
    }

    private function describeField(array $field): array
    {
        $attributes = (array)($field['attributes'] ?? []);
        $description = ['type' => $field['type'], 'label' => (string)($field['label'] ?? '')];
        if ($this->isRequired($field)) {
            $description['required'] = true;
        }
        if (in_array($field['type'], [...self::OPTION_TYPES, 'text-align'], true)) {
            $description['options'] = $this->getOptions($field);
        }
        foreach (['min', 'max', 'minlength', 'maxlength'] as $limit) {
            if (isset($attributes[$limit]) && $attributes[$limit] !== '') {
                $description[$limit] = $attributes[$limit];
            }
        }
        if (array_key_exists('default_value', $field)) {
            $description['default'] = $field['default_value'];
        }

        return $description;
    }

    public function isRequired(array $field): bool
    {
        $attributes = (array)($field['attributes'] ?? []);

        return filter_var($attributes['required'] ?? $attributes['data-required'] ?? false, FILTER_VALIDATE_BOOL);
    }

    private function describeChildren(mixed $children): bool|array
    {
        if (!is_array($children)) {
            return (bool)$children;
        }
        $config = (array)($children['config'] ?? []);

        return array_intersect_key($config, array_flip(['accepts', 'excludes', 'max_children'])) ?: true;
    }

    private function isUsable(string $name, array $declaration): bool
    {
        return empty($declaration['hidden'])
            && empty($declaration['disabled'])
            && !in_array($name, self::EXCLUDED, true);
    }

    /**
     * The editor's rule (componentAvailability() in editor/utilities.js): no list means everywhere
     *
     * @param array $declaration
     * @param string $token The content type's availability token, e.g. "page" or "block"
     * @return bool
     */
    private function isAvailableFor(array $declaration, string $token): bool
    {
        $availableFor = $declaration['available_for'] ?? [];

        return !is_array($availableFor) || $availableFor === [] || in_array($token, $availableFor, true);
    }

    private function isSupported(array $field): bool
    {
        $type = (string)($field['type'] ?? '');
        if (!in_array($type, self::SUPPORTED_TYPES, true)) {
            return false;
        }

        // A select without inline options loads them in the browser; the chat cannot check a value.
        return !in_array($type, self::OPTION_TYPES, true) || $this->getOptions($field) !== [];
    }
}
