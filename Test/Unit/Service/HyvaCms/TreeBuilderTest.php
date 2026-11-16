<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\HyvaCms\Test\Unit\Service\HyvaCms;

use Hyva\CmsLiveviewEditor\Model\Component\Provider as ComponentProvider;
use Hyva\CmsLiveviewEditor\ViewModel\ContentTypeAvailabilityMap;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\LocalizedException;
use Magento\MediaGalleryApi\Api\GetAssetsByPathsInterface;
use MagoAssistant\HyvaCms\Service\HyvaCms\ComponentCatalog;
use MagoAssistant\HyvaCms\Service\HyvaCms\FieldValidator;
use MagoAssistant\HyvaCms\Service\HyvaCms\TreeBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TreeBuilderTest extends TestCase
{
    private const COMPONENTS = [
        'heading' => [
            'template' => 'Hyva_CmsBase::elements/heading.phtml',
            'content' => [
                'tag' => ['type' => 'select', 'label' => 'Tag', 'default_value' => 'h2', 'options' => [
                    ['value' => 'h1', 'label' => 'H1'], ['value' => 'h2', 'label' => 'H2'],
                ]],
                'text' => ['type' => 'textarea', 'label' => 'Text', 'attributes' => ['maxlength' => '20']],
            ],
            'design' => ['text_color' => ['type' => 'color', 'label' => 'Colour']],
            'advanced' => ['classes' => ['type' => 'text', 'label' => 'CSS Classes']],
        ],
        'banner' => [
            'template' => 'Hyva_CmsBase::elements/banner/default.phtml',
            'children' => ['config' => ['accepts' => ['button']]],
            'content' => [
                'variants' => ['type' => 'variant', 'default_value' => 'banner/default.phtml', 'options' => [
                    ['value' => 'banner/default.phtml'], ['value' => 'banner/split.phtml'],
                ]],
                'title' => ['type' => 'text', 'label' => 'Title', 'attributes' => ['required' => 'true']],
            ],
        ],
        'button' => ['template' => 'button.phtml', 'require_parent' => true, 'content' => [
            'link' => ['type' => 'link', 'label' => 'Link'],
        ]],
        'html' => ['template' => 'html.phtml', 'content' => ['html' => ['type' => 'html', 'label' => 'HTML']]],
        'instance/page-only' => ['template' => 'x.phtml', 'available_for' => ['page'], 'content' => []],
        'usp' => ['template' => false, 'content' => ['size' => ['type' => 'range', 'label' => 'Size']]],
    ];

    private TreeBuilder $builder;

    protected function setUp(): void
    {
        $provider = $this->createStub(ComponentProvider::class);
        $provider->method('getComponents')->willReturn(self::COMPONENTS);
        $catalog = new ComponentCatalog($provider, new ContentTypeAvailabilityMap(['cms_page' => 'page', 'cms_block' => 'block']));
        $validator = new FieldValidator(
            $catalog,
            $this->createStub(GetAssetsByPathsInterface::class),
            $this->createStub(PageRepositoryInterface::class),
            $this->createStub(CategoryRepositoryInterface::class),
            $this->createStub(ProductRepositoryInterface::class),
            $this->createStub(SearchCriteriaBuilder::class),
            $this->createStub(Escaper::class)
        );
        $this->builder = new TreeBuilder($catalog, $validator);
    }

    public function testNewComponentStartsLikeTheEditorsAddComponent(): void
    {
        $content = $this->builder->build([
            ['component' => 'banner', 'variant' => 'banner/split.phtml', 'fields' => ['title' => 'Sale']],
        ], []);
        $node = reset($content);

        $this->assertSame(key($content), $node['uid']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{14}$/', $node['uid']);
        $this->assertSame('banner/split.phtml', $node['componentPath']);
        $this->assertSame('Sale', $node['title']);
        $this->assertSame([], $node['children']);
    }

    public function testExistingComponentKeepsWhatTheChatDoesNotChange(): void
    {
        $current = ['a1' => [
            'uid' => 'a1', 'component' => 'heading', 'componentPath' => 'x', 'children' => [],
            'tag' => 'h1', 'text' => 'Old', 'classes' => 'mt-8 set-by-hand', 'block_id' => 'intro',
        ]];

        $node = $this->builder->build([['uid' => 'a1', 'fields' => ['text' => 'New']]], $current)['a1'];

        $this->assertSame('New', $node['text']);
        $this->assertSame('h1', $node['tag']);
        $this->assertSame('mt-8 set-by-hand', $node['classes']);
        $this->assertSame('intro', $node['block_id']);
    }

    public function testChildrenAreKeptWhenLeftOutAndRemovedWhenEmpty(): void
    {
        $current = ['b1' => [
            'uid' => 'b1', 'component' => 'banner', 'componentPath' => 'x', 'title' => 'T',
            'children' => [['uid' => 'c1', 'component' => 'button', 'componentPath' => 'y', 'children' => []]],
        ]];

        $kept = $this->builder->build([['uid' => 'b1']], $current);
        $emptied = $this->builder->build([['uid' => 'b1', 'children' => []]], $current, ['c1']);

        $this->assertSame('c1', $kept['b1']['children'][0]['uid']);
        $this->assertSame([], $emptied['b1']['children']);
    }

    public function testComponentsLeftOutAreRemovedAndOrderFollowsTheList(): void
    {
        $current = [
            'a1' => ['uid' => 'a1', 'component' => 'heading', 'children' => []],
            'a2' => ['uid' => 'a2', 'component' => 'heading', 'children' => []],
            'a3' => ['uid' => 'a3', 'component' => 'heading', 'children' => []],
        ];

        $content = $this->builder->build([['uid' => 'a3'], ['uid' => 'a1']], $current, ['a2']);

        $this->assertSame(['a3', 'a1'], array_keys($content));
    }

    public function testAComponentLeftOutWithoutRemoveIsRefusedNotDropped(): void
    {
        $current = [
            'a1' => ['uid' => 'a1', 'component' => 'heading', 'children' => []],
            'a2' => ['uid' => 'a2', 'component' => 'heading', 'children' => []],
        ];

        $this->expectExceptionMessage('a2 (heading)');

        // A rewrite without uids: both old headings would silently be replaced by a new one.
        $this->builder->build([['uid' => 'a1'], ['component' => 'heading', 'fields' => ['text' => 'New']]], $current);
    }

    public function testRemoveOnlyNamesComponentsThatAreReallyGone(): void
    {
        $current = ['a1' => ['uid' => 'a1', 'component' => 'heading', 'children' => []]];

        $this->expectExceptionMessage('"remove" names a1');

        $this->builder->build([['uid' => 'a1']], $current, ['a1']);
    }

    public static function refusals(): array
    {
        return [
            'excluded component' => [[['component' => 'html']], 'not available'],
            'advanced field' => [[['component' => 'heading', 'fields' => ['classes' => 'text-red-500']]], 'no field "classes"'],
            'option outside the list' => [[['component' => 'heading', 'fields' => ['tag' => 'h7']]], 'must be one of'],
            'maxlength' => [[['component' => 'heading', 'fields' => ['text' => str_repeat('x', 21)]]], 'at most 20'],
            'required field' => [[['component' => 'banner']], 'needs a value for field "title"'],
            'require_parent' => [[['component' => 'button']], 'must be placed inside'],
            'accepts' => [[['component' => 'banner', 'fields' => ['title' => 'T'], 'children' => [['component' => 'heading']]]], 'cannot contain heading'],
            'no children' => [[['component' => 'heading', 'children' => [['component' => 'heading']]]], 'cannot contain other components'],
            'unknown uid' => [[['uid' => 'zz']], 'no component with uid'],
            'unknown variant' => [[['component' => 'banner', 'variant' => 'evil.phtml', 'fields' => ['title' => 'T']]], 'no variant'],
            'javascript link' => [[['component' => 'banner', 'fields' => ['title' => 'T'], 'children' => [
                ['component' => 'button', 'fields' => ['link' => ['type' => 'custom_url', 'value' => 'javascript:alert(1)']]],
            ]]], 'must be a path on this store'],
            'control character' => [[['component' => 'heading', 'fields' => ['text' => "over \x7f50"]]], 'control character'],
            'infinite number' => [[['component' => 'usp', 'fields' => ['size' => '1e400']]], 'finite'],
            'negative range' => [[['component' => 'usp', 'fields' => ['size' => -5]]], 'at least 0'],
            'css in colour' => [[['component' => 'heading', 'fields' => ['text_color' => 'red;background:url(x)']]], 'CSS colour'],
        ];
    }

    #[DataProvider('refusals')]
    public function testRefuses(array $input, string $message): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage($message);

        $this->builder->build($input, []);
    }

    public function testAComponentLimitedToPagesIsNotOfferedInABlock(): void
    {
        $this->assertNotEmpty($this->builder->build([['component' => 'instance/page-only']], [], [], 'cms_page'));

        $this->expectExceptionMessage('not available');
        $this->builder->build([['component' => 'instance/page-only']], [], [], 'cms_block');
    }

    public function testATemplatelessComponentKeepsFalseLikeTheEditor(): void
    {
        $node = current($this->builder->build([['component' => 'usp']], []));

        $this->assertFalse($node['componentPath']);
    }

    public function testAMalformedUidIsRefusedNotACrash(): void
    {
        $this->expectException(LocalizedException::class);

        $this->builder->build([['uid' => ['x']]], []);
    }

    public function testAComponentCanBeInOnePlaceOnly(): void
    {
        $current = ['a1' => ['uid' => 'a1', 'component' => 'heading', 'children' => []]];

        $this->expectExceptionMessage('appears twice');

        $this->builder->build([['uid' => 'a1'], ['uid' => 'a1']], $current);
    }

    public function testAnExistingComponentCannotChangeType(): void
    {
        $current = ['a1' => ['uid' => 'a1', 'component' => 'heading', 'children' => []]];

        $this->expectExceptionMessage('cannot become a banner');

        $this->builder->build([['uid' => 'a1', 'component' => 'banner']], $current);
    }

    public function testAnExcludedComponentCanStayButNotChange(): void
    {
        $current = ['h1' => ['uid' => 'h1', 'component' => 'html', 'html' => '<script>x</script>', 'children' => []]];

        $this->assertSame($current, $this->builder->build([['uid' => 'h1']], $current));

        $this->expectExceptionMessage('cannot be changed from the chat');
        $this->builder->build([['uid' => 'h1', 'fields' => ['html' => '<p>x</p>']]], $current);
    }
}
