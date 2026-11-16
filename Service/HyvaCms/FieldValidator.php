<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\HyvaCms\Service\HyvaCms;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\MediaGalleryApi\Api\GetAssetsByPathsInterface;

/**
 * Checks a value the chat wants to put in a Hyvä CMS field and turns it into what the editor stores
 *
 * The editor checks most of this only in the browser, so a server-side save has to do it here:
 * option membership, number and length limits, the HTML pattern attributes, that a link points at a
 * page, category or product that exists, and that an image is a file in the media gallery.
 */
class FieldValidator
{
    private const LINK_TYPES = ['cms_page', 'category', 'product', 'custom_url'];
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];
    private const MAX_TEXT_BYTES = 20000;
    private const COLOR_PATTERN = '/^#?[a-zA-Z0-9,().\-\s%]{1,64}$/';
    // Same-site path or http(s); everything else (javascript:, data:, protocol-relative) is refused.
    private const URL_PATTERN = '#^(/(?!/)|https?://)[^\s<>"\'`]*$#i';
    /** Same list as Hyvä's Block\Element::renderRichText(); a richtext value is checked as Hyvä will render it. */
    private const RICHTEXT_TAGS = [
        'br', 'wbr', 'p', 'strong', 'em', 'u', 'ol', 'ul', 'li', 'blockquote', 'h1', 'h2', 'h3', 'pre', 'code',
        's', 'a',
    ];
    /** The editor's own richtext directives; any other {{ }} would reach Magento's block filter. */
    private const RICHTEXT_DIRECTIVES = ['hyva_link', 'hyva_image'];
    /** Link parts only the editor sets, kept while the link keeps pointing at the same target. */
    private const LINK_KEPT_KEYS = ['prefix', 'suffix', 'src'];

    public function __construct(
        private readonly ComponentCatalog $catalog,
        private readonly GetAssetsByPathsInterface $getAssetsByPaths,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly Escaper $escaper
    ) {
    }

    /**
     * The value as the editor stores it, or a refusal naming what is wrong
     *
     * @param string $name Field name, for the error message
     * @param array $field The field's declaration
     * @param mixed $value The value the chat sent
     * @param mixed $current The field's value now; parts of a link or image the chat does not name are kept
     * @return mixed The value as the editor stores it
     * @throws LocalizedException
     */
    public function normalize(string $name, array $field, mixed $value, mixed $current = null): mixed
    {
        return match ($field['type']) {
            'text', 'textarea' => $this->text($name, $field, $value),
            'richtext' => $this->richText($name, $value),
            'number', 'range' => $this->number($name, $field, $value),
            'boolean' => $this->boolean($name, $value),
            'select', 'searchable_select', 'text-align' => $this->option(
                $name,
                $this->catalog->getOptions($field),
                $value
            ),
            'color' => $this->color($name, $field, $value),
            'url' => $this->url($name, $value),
            'link' => $this->link($name, $value, is_array($current) ? $current : []),
            'image' => $this->image($name, $value, is_array($current) ? $current : []),
            default => throw new LocalizedException(__('Field "%1" cannot be set from the chat.', $name)),
        };
    }

    /**
     * Whether a field counts as filled in, for required fields
     *
     * @param mixed $value
     * @return bool
     */
    public function isFilled(mixed $value): bool
    {
        if (is_array($value)) {
            return ($value['src'] ?? $value['value'] ?? '') !== '';
        }

        return $value !== null && $value !== '' && $value !== false;
    }

    private function text(string $name, array $field, mixed $value): string
    {
        $value = $this->string($name, $value);
        $attributes = (array)($field['attributes'] ?? []);
        $length = mb_strlen($value);
        if (isset($attributes['maxlength']) && $length > (int)$attributes['maxlength']) {
            throw new LocalizedException(__('Field "%1" is at most %2 characters.', $name, $attributes['maxlength']));
        }
        if (isset($attributes['minlength']) && $value !== '' && $length < (int)$attributes['minlength']) {
            throw new LocalizedException(__('Field "%1" is at least %2 characters.', $name, $attributes['minlength']));
        }
        $pattern = $attributes['pattern'] ?? $attributes['data-pattern'] ?? null;
        if ($value !== '' && is_string($pattern) && $pattern !== '' && !$this->matchesHtmlPattern($pattern, $value)) {
            throw new LocalizedException(__(
                'Field "%1" does not match the format the editor requires: %2',
                $name,
                (string)($attributes['data-pattern-msg'] ?? $pattern)
            ));
        }

        return $value;
    }

    private function richText(string $name, mixed $value): string
    {
        $value = $this->string($name, $value);
        // Checked on what Hyvä renders, not on the raw value: its escaper drops comments and disallowed
        // tags, which would glue "{<!-- -->{block ...}}" into a working directive. Quotes are restored
        // the way Hyvä does before it runs the directive filter.
        $rendered = str_replace(
            ['&quot;', '&#039;'],
            ['"', "'"],
            $this->escaper->escapeHtml($value, self::RICHTEXT_TAGS)
        );
        preg_match_all('/\{\{\s*([a-z_]*)/i', $rendered, $directives);
        $unknown = array_diff(array_map('strtolower', $directives[1]), self::RICHTEXT_DIRECTIVES);
        if ($unknown !== [] || preg_match('/<\s*script|\bon[a-z]+\s*=|javascript:/i', $value)) {
            throw new LocalizedException(__(
                'Field "%1" may contain formatted HTML only: no scripts, event handlers or {{ }} directives '
                . 'other than the editor\'s own hyva_link and hyva_image.',
                $name
            ));
        }

        return $value;
    }

    private function number(string $name, array $field, mixed $value): int|float
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            throw new LocalizedException(__('Field "%1" must be a number.', $name));
        }
        $number = $value + 0;
        if (!is_finite((float)$number)) {
            throw new LocalizedException(__('Field "%1" must be a finite number.', $name));
        }
        $attributes = (array)($field['attributes'] ?? []);
        // A range input without min starts at 0 in the browser, so the editor never stores less.
        $defaults = $field['type'] === 'range' ? ['min' => 0] : [];
        foreach (['min' => -1, 'max' => 1] as $limit => $direction) {
            $bound = $attributes[$limit] ?? $attributes['data-' . $limit] ?? $defaults[$limit] ?? null;
            if (is_numeric($bound) && ($number <=> $bound + 0) === $direction) {
                throw new LocalizedException(__(
                    'Field "%1" must be %2 %3.',
                    $name,
                    $direction < 0 ? 'at least' : 'at most',
                    $bound
                ));
            }
        }

        return $number;
    }

    private function boolean(string $name, mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new LocalizedException(__('Field "%1" must be true or false.', $name));
        }

        return $value;
    }

    /**
     * A value from the field's options
     *
     * @param string $name
     * @param string[] $options
     * @param mixed $value
     * @return string
     * @throws LocalizedException
     */
    private function option(string $name, array $options, mixed $value): string
    {
        if (!is_scalar($value) || !in_array((string)$value, $options, true)) {
            throw new LocalizedException(__('Field "%1" must be one of: %2.', $name, implode(', ', $options)));
        }

        return (string)$value;
    }

    private function color(string $name, array $field, mixed $value): string
    {
        $value = $this->string($name, $value);
        $pattern = $field['attributes']['data-pattern'] ?? null;
        $valid = $value === '' || (preg_match(self::COLOR_PATTERN, $value)
            && (!is_string($pattern) || $this->matchesHtmlPattern($pattern, $value)));
        if (!$valid) {
            throw new LocalizedException(__('Field "%1" must be a CSS colour such as #1f2937 or rgb(0, 0, 0).', $name));
        }

        return $value;
    }

    private function url(string $name, mixed $value): string
    {
        $value = $this->string($name, $value);
        if ($value !== '' && !preg_match(self::URL_PATTERN, $value)) {
            throw new LocalizedException(__(
                'Field "%1" must be a path on this store (starting with /) or an http(s) URL.',
                $name
            ));
        }

        return $value;
    }

    /**
     * A link, merged onto the current one
     *
     * While type and value stay the same, the parts only the editor sets (a STORE_URL prefix, a #anchor
     * or ?utm suffix) are kept, and label, new tab and aria label keep their value unless the chat names
     * them: changing a button's label must not drop what someone set by hand.
     *
     * @param string $name
     * @param mixed $value
     * @param array $current
     * @return array
     * @throws LocalizedException
     */
    private function link(string $name, mixed $value, array $current): array
    {
        if (!is_array($value)) {
            throw new LocalizedException(__('Field "%1" must be a link object with "type" and "value".', $name));
        }
        $type = $value['type'] ?? $current['type'] ?? null;
        $target = $value['value'] ?? $current['value'] ?? null;
        $sameTarget = $current !== []
            && $type === ($current['type'] ?? null)
            && (string)$target === (string)($current['value'] ?? '');
        if (!$sameTarget && !in_array($type, self::LINK_TYPES, true)) {
            throw new LocalizedException(__(
                'Field "%1" must be a link object with "type" (%2) and "value".',
                $name,
                implode(', ', self::LINK_TYPES)
            ));
        }
        if (!is_scalar($target) || (string)$target === '') {
            throw new LocalizedException(__('Field "%1" needs a "value" for its %2 link.', $name, (string)$type));
        }
        $target = (string)$target;
        // The target's own name, which the storefront shows when the link has no label (the editor stores
        // it the same way when an item is picked); without it the button shows the bare URL. An unchanged
        // target keeps what it had: it was accepted when it was set, also in a form the chat cannot write.
        $placeholder = $sameTarget ? ($current['placeholder'] ?? '') : match ($type) {
            'cms_page' => $this->pageTitle($name, $target),
            'category' => $this->categoryName($name, $target),
            'product' => $this->productName($name, $target),
            'custom_url' => $this->url($name, $target),
        };

        $link = ($sameTarget ? array_intersect_key($current, array_flip(self::LINK_KEPT_KEYS)) : []) + [
            'type' => $type,
            'value' => $type === 'category' ? (int)$target : $target,
            'label' => array_key_exists('label', $value)
                ? $this->string($name, $value['label'])
                : (string)($current['label'] ?? ''),
            'placeholder' => $placeholder,
            'open_in_new_tab' => (bool)($value['open_in_new_tab'] ?? $current['open_in_new_tab'] ?? false),
        ];
        if (array_key_exists('aria_label', $value) || isset($current['aria_label'])) {
            $link['aria_label'] = $this->string($name, $value['aria_label'] ?? $current['aria_label']);
        }
        if ($type === 'custom_url' && $link['label'] === '') {
            throw new LocalizedException(__('Field "%1" needs a "label" for its custom_url link.', $name));
        }

        return $link;
    }

    /**
     * An image from the media gallery, merged onto the current one
     *
     * Without a path the current image stays (to change only its alt text); its classes are kept.
     *
     * @param string $name
     * @param mixed $value
     * @param array $current
     * @return array
     * @throws LocalizedException
     */
    private function image(string $name, mixed $value, array $current): array
    {
        $path = is_array($value) ? ($value['path'] ?? $value['src'] ?? $current['src'] ?? null) : $value;
        $alt = is_array($value) ? ($value['alt'] ?? $current['alt'] ?? null) : ($current['alt'] ?? null);
        if (!is_string($path) || $path === '') {
            throw new LocalizedException(__(
                'Field "%1" must be an image object with "path" (from find_images) and "alt".',
                $name
            ));
        }
        $path = ltrim($path, '/');
        $path = str_starts_with($path, 'media/') ? substr($path, strlen('media/')) : $path;
        $extension = strtolower((string)substr((string)strrchr($path, '.'), 1));
        if (str_contains($path, '..')
            || preg_match('#^[a-z]+:#i', $path)
            || !in_array($extension, self::IMAGE_EXTENSIONS, true)
        ) {
            throw new LocalizedException(__(
                'Field "%1" must point to an image in the media gallery, found with find_images.',
                $name
            ));
        }
        if (!is_string($alt) || trim($alt) === '') {
            throw new LocalizedException(__('Field "%1" needs an "alt" text that describes the image.', $name));
        }

        $assets = $this->getAssetsByPaths->execute([$path]);
        $asset = reset($assets) ?: null;
        if ($asset === null) {
            throw new LocalizedException(__(
                'Image "%1" is not in the media gallery. Only images already in the store\'s media can be used; '
                . 'use find_images to pick one.',
                $path
            ));
        }

        return [
            'src' => $asset->getPath(),
            'alt' => trim($alt),
            'width' => (string)$asset->getWidth(),
            'height' => (string)$asset->getHeight(),
            'classes' => $current['classes'] ?? null,
            'preview_url' => '/media/' . $asset->getPath(),
            'displaySidebar' => true,
            'imageOptions' => [
                'id' => $asset->getId(),
                'path' => $asset->getPath(),
                'image_url' => '/media/' . $asset->getPath(),
                'title' => trim($alt),
            ],
        ];
    }

    private function string(string $name, mixed $value): string
    {
        if (!is_string($value) || strlen($value) > self::MAX_TEXT_BYTES) {
            throw new LocalizedException(__(
                'Field "%1" must be text of at most %2 bytes.',
                $name,
                self::MAX_TEXT_BYTES
            ));
        }
        // An invisible control character is never meant: a model stream that loses a character (seen with
        // "€" arriving as U+007F) must not end up stored as a box on the storefront.
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new LocalizedException(__(
                'Field "%1" contains an invisible control character where a character (such as € or a quote) was '
                . 'probably meant. Send the text again with the character written out.',
                $name
            ));
        }

        return $value;
    }

    /**
     * Apply an HTML pattern attribute the way a browser does: anchored to the whole value
     *
     * @param string $pattern
     * @param string $value
     * @return bool
     */
    private function matchesHtmlPattern(string $pattern, string $value): bool
    {
        $regex = '/^(?:' . str_replace('/', '\/', $pattern) . ')$/u';
        // An invalid pattern in a vendor declaration must not break the save.
        // phpcs:ignore Generic.PHP.NoSilencedErrors
        $result = @preg_match($regex, $value);

        return $result !== 0;
    }

    private function pageTitle(string $name, string $identifier): string
    {
        $criteria = $this->searchCriteriaBuilder->addFilter('identifier', $identifier)->setPageSize(1)->create();
        $pages = $this->pageRepository->getList($criteria)->getItems();
        $page = reset($pages);
        if (!$page) {
            throw new LocalizedException(__(
                'Field "%1" links to CMS page "%2", which does not exist.',
                $name,
                $identifier
            ));
        }

        return (string)$page->getTitle();
    }

    private function categoryName(string $name, string $id): string
    {
        try {
            return (string)$this->categoryRepository->get((int)$id)->getName();
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('Field "%1" links to category %2, which does not exist.', $name, $id));
        }
    }

    private function productName(string $name, string $sku): string
    {
        try {
            return (string)$this->productRepository->get($sku)->getName();
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__(
                'Field "%1" links to product SKU "%2", which does not exist.',
                $name,
                $sku
            ));
        }
    }
}
