<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\HyvaCms\Service\HyvaCms;

use Magento\Framework\Exception\LocalizedException;

/**
 * Turns the page the chat describes into a Hyvä CMS component tree, on top of the current tree
 *
 * Input: a list of nodes `{uid?, component?, variant?, fields?, children?}`.
 * - A node with the uid of an existing component keeps everything it has (also values the editor set
 *   by hand, and fields the chat cannot edit); only the fields named are changed. Its "children" are
 *   kept when the key is absent and replaced when it is given, so moving and removing are explicit.
 * - A node without uid is new: it gets a fresh uid and the declaration's default values, like the
 *   editor's "add component".
 * - An existing component that the list does not mention any more is removed, but only when it is
 *   named in $remove too. A model that rewrites a page without its uids would otherwise silently
 *   replace every component, and with them anything set by hand in the editor.
 *
 * The placement rules the editor only enforces in the browser are checked here: which children a
 * component accepts, root_only and require_parent, and required fields.
 */
class TreeBuilder
{
    private const MAX_NODES = 300;
    private const MAX_DEPTH = 12;
    private const SECTIONS_WITH_DEFAULTS = ['content', 'design', 'advanced'];

    /** @var array<string, array> Current components by uid, for the build in progress */
    private array $existing = [];
    /** @var int Components built so far, against MAX_NODES */
    private int $nodeCount = 0;
    /** @var string Content type being built, for the components it allows */
    private string $contentType = CmsEntities::PAGE;

    public function __construct(
        private readonly ComponentCatalog $catalog,
        private readonly FieldValidator $fieldValidator
    ) {
    }

    /**
     * The new page content, after every check
     *
     * @param array $input The nodes the chat sent for the top level of the page
     * @param array $currentContent The `content` of the current tree, empty for a new page
     * @param array $remove Uids of current components the chat removes on purpose
     * @param string $contentType cms_page or cms_block
     * @return array The new `content`, keyed by uid like the editor stores it
     * @throws LocalizedException
     */
    public function build(
        array $input,
        array $currentContent,
        array $remove = [],
        string $contentType = CmsEntities::PAGE
    ): array {
        if (!array_is_list($input)) {
            throw new LocalizedException(__('"content" must be a list of components.'));
        }
        $this->existing = [];
        $this->nodeCount = 0;
        $this->contentType = $contentType;
        $this->index($currentContent);

        $nodes = array_map(fn ($node) => $this->buildNode($node, null, 1), $input);
        // Before keying by uid, which would silently drop a duplicate.
        $this->assertUniqueUids($nodes);
        $this->assertRemovalsNamed($nodes, $remove);

        return array_column($nodes, null, 'uid');
    }

    private function buildNode(mixed $input, ?string $parent, int $depth): array
    {
        if (!is_array($input)) {
            throw new LocalizedException(__('Every component must be an object.'));
        }
        if (++$this->nodeCount > self::MAX_NODES || $depth > self::MAX_DEPTH) {
            throw new LocalizedException(__(
                'A page may hold at most %1 components, nested %2 deep.',
                self::MAX_NODES,
                self::MAX_DEPTH
            ));
        }
        $unknown = array_diff(array_keys($input), ['uid', 'component', 'variant', 'fields', 'children']);
        if ($unknown !== []) {
            throw new LocalizedException(__(
                'Unknown component properties: %1. Put field values under "fields".',
                implode(', ', $unknown)
            ));
        }

        $uid = $input['uid'] ?? null;
        if ($uid !== null && !is_string($uid) && !is_int($uid)) {
            throw new LocalizedException(__('A "uid" must be the text get returned.'));
        }
        $node = $uid !== null ? $this->existingNode((string)$uid, $input) : $this->newNode($input);
        $name = (string)$node['component'];
        $declaration = $this->catalog->get($name, $this->contentType);

        if ($declaration === null) {
            // An existing component the chat may not edit (raw HTML, a hidden or excluded type): it can
            // stay and move, but not change.
            if (isset($input['fields']) || isset($input['variant']) || array_key_exists('children', $input)) {
                throw new LocalizedException(__(
                    'Component %1 (%2) cannot be changed from the chat; it can only stay, move or be removed.',
                    $node['uid'],
                    $name
                ));
            }
            return $node;
        }

        $this->assertPlacement($name, $declaration, $parent);
        if (isset($input['variant'])) {
            $node = $this->applyVariant($node, $declaration, $input['variant']);
        }
        $node = $this->applyFields($node, $declaration, $input['fields'] ?? [], $uid === null);

        if (array_key_exists('children', $input)) {
            if (!is_array($input['children']) || !array_is_list($input['children'])) {
                throw new LocalizedException(__('"children" of %1 must be a list.', $name));
            }
            $this->assertChildrenAllowed($name, $declaration, $input['children']);
            $node['children'] = array_map(
                fn ($child) => $this->buildNode($child, $name, $depth + 1),
                $input['children']
            );
        }

        return $node;
    }

    private function existingNode(string $uid, array $input): array
    {
        $node = $this->existing[$uid] ?? throw new LocalizedException(__(
            'There is no component with uid "%1" on this page. Leave out "uid" to add a new component.',
            $uid
        ));
        if (isset($input['component']) && $input['component'] !== $node['component']) {
            throw new LocalizedException(__(
                'Component %1 is a %2 and cannot become a %3; remove it and add a new one instead.',
                $uid,
                $node['component'],
                (string)$input['component']
            ));
        }

        return $node;
    }

    private function newNode(array $input): array
    {
        $name = $input['component'] ?? null;
        $declaration = is_string($name) ? $this->catalog->get($name, $this->contentType) : null;
        if ($declaration === null) {
            throw new LocalizedException(__(
                'Component "%1" is not available. Use list_components for the components that can be placed.',
                is_scalar($name) ? (string)$name : ''
            ));
        }

        // The same start as the editor's "add component": declared defaults of every section.
        $node = [
            'uid' => bin2hex(random_bytes(7)),
            'component' => $name,
            // As declared: false marks a component that renders only through its parent, like the editor stores it.
            'componentPath' => $declaration['template'] ?? '',
            'children' => [],
        ];
        foreach (self::SECTIONS_WITH_DEFAULTS as $section) {
            foreach ($declaration[$section] ?? [] as $field => $definition) {
                if (is_array($definition) && isset($definition['default_value'])) {
                    $node[$field] = $definition['default_value'];
                }
            }
        }
        if (isset($node['variants']) && is_string($node['variants']) && $node['variants'] !== '') {
            $node['componentPath'] = $node['variants'];
        }

        return $node;
    }

    private function applyVariant(array $node, array $declaration, mixed $variant): array
    {
        $variants = $this->catalog->getVariants($declaration);
        if (!is_string($variant) || !in_array($variant, $variants, true)) {
            throw new LocalizedException(__(
                'Component %1 has no variant "%2". Variants: %3.',
                $node['component'],
                is_scalar($variant) ? (string)$variant : '',
                implode(', ', $variants) ?: 'none'
            ));
        }
        $node['variants'] = $variant;
        $node['componentPath'] = $variant;

        return $node;
    }

    private function applyFields(array $node, array $declaration, mixed $values, bool $isNew): array
    {
        if (!is_array($values) || ($values !== [] && array_is_list($values))) {
            throw new LocalizedException(__(
                '"fields" of %1 must be an object of field name to value.',
                $node['component']
            ));
        }
        $fields = $this->catalog->getFields($declaration);
        foreach ($values as $name => $value) {
            if (!isset($fields[$name])) {
                throw new LocalizedException(__(
                    'Component %1 has no field "%2" that the chat can set. Fields: %3.',
                    $node['component'],
                    $name,
                    implode(', ', array_keys($fields)) ?: 'none'
                ));
            }
            $node[$name] = $this->fieldValidator->normalize(
                $node['component'] . '.' . $name,
                $fields[$name],
                $value,
                $node[$name] ?? null
            );
        }
        // Only what the chat adds or edits: a component it merely keeps or moves stays as the editor left it.
        if (!$isNew && $values === []) {
            return $node;
        }
        foreach ($fields as $name => $field) {
            if ($this->catalog->isRequired($field) && !$this->fieldValidator->isFilled($node[$name] ?? null)) {
                throw new LocalizedException(__(
                    'Component %1 needs a value for field "%2".',
                    $node['component'],
                    $name
                ));
            }
        }

        return $node;
    }

    private function assertPlacement(string $name, array $declaration, ?string $parent): void
    {
        if ($parent !== null && !empty($declaration['root_only'])) {
            throw new LocalizedException(__('%1 can only be placed at the top level of the page.', $name));
        }
        if ($parent === null && !empty($declaration['require_parent'])) {
            throw new LocalizedException(__('%1 must be placed inside a component that accepts it.', $name));
        }
    }

    private function assertChildrenAllowed(string $name, array $declaration, array $children): void
    {
        $rule = $declaration['children'] ?? false;
        if ($children === []) {
            return;
        }
        if (!$rule) {
            throw new LocalizedException(__('%1 cannot contain other components.', $name));
        }
        $config = is_array($rule) ? (array)($rule['config'] ?? []) : [];
        if (isset($config['max_children']) && count($children) > (int)$config['max_children']) {
            throw new LocalizedException(__('%1 holds at most %2 components.', $name, $config['max_children']));
        }
        foreach ($children as $child) {
            $childUid = is_array($child) && is_scalar($child['uid'] ?? null) ? (string)$child['uid'] : '';
            $childName = is_array($child)
                ? (string)($child['component'] ?? $this->existing[$childUid]['component'] ?? '')
                : '';
            $accepted = (!isset($config['accepts']) || in_array($childName, (array)$config['accepts'], true))
                && !in_array($childName, (array)($config['excludes'] ?? []), true);
            if (!$accepted) {
                throw new LocalizedException(__(
                    '%1 cannot contain %2.%3',
                    $name,
                    $childName,
                    isset($config['accepts'])
                        ? ' ' . __('It accepts: %1.', implode(', ', (array)$config['accepts']))
                        : ''
                ));
            }
        }
    }

    private function index(array $nodes): void
    {
        foreach ($nodes as $node) {
            if (!is_array($node) || !isset($node['uid'])) {
                continue;
            }
            $this->existing[(string)$node['uid']] = $node;
            $this->index((array)($node['children'] ?? []));
        }
    }

    private function assertRemovalsNamed(array $nodes, array $remove): void
    {
        $kept = [];
        $walk = function (array $nodes) use (&$walk, &$kept): void {
            foreach ($nodes as $node) {
                $kept[(string)$node['uid']] = true;
                $walk((array)($node['children'] ?? []));
            }
        };
        $walk($nodes);
        $remove = array_map('strval', $remove);

        $missing = array_diff(array_keys($this->existing), array_keys($kept), $remove);
        if ($missing !== []) {
            throw new LocalizedException(__(
                'These components are missing from "content" but not listed in "remove": %1. Keep a component by '
                . 'sending it with its "uid" (fields you leave out stay as they are), or list its uid in "remove" '
                . 'to delete it.',
                implode(', ', array_map(fn ($uid) => $uid . ' (' . $this->existing[$uid]['component'] . ')', $missing))
            ));
        }
        $wrong = array_diff($remove, array_diff(array_keys($this->existing), array_keys($kept)));
        if ($wrong !== []) {
            throw new LocalizedException(__(
                '"remove" names %1, which is not on the page or is still in "content".',
                implode(', ', $wrong)
            ));
        }
    }

    private function assertUniqueUids(array $nodes): void
    {
        $seen = [];
        $walk = function (array $nodes) use (&$walk, &$seen): void {
            foreach ($nodes as $node) {
                $uid = (string)$node['uid'];
                if (isset($seen[$uid])) {
                    throw new LocalizedException(__(
                        'Component %1 appears twice; a component can be in one place only.',
                        $uid
                    ));
                }
                $seen[$uid] = true;
                $walk((array)($node['children'] ?? []));
            }
        };
        $walk($nodes);
    }
}
