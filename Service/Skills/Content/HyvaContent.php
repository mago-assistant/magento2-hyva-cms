<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\HyvaCms\Service\Skills\Content;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use MagoAssistant\HyvaCms\Service\HyvaCms\CmsEntities;
use MagoAssistant\Mago\Api\Tool\AvailabilityAwareToolInterface;
use MagoAssistant\Mago\Api\Tool\PresentableToolInterface;
use MagoAssistant\Mago\Service\Skills\AbstractSkill;

/**
 * Builds and edits Hyvä CMS pages and blocks from the admin chat: draft first, publish on a second confirmation
 */
class HyvaContent extends AbstractSkill implements AvailabilityAwareToolInterface, PresentableToolInterface
{
    /** What each action does to the content type, for its Hyvä CMS ACL resource */
    private const VERBS = [
        'list_components' => CmsEntities::VIEW,
        'get' => CmsEntities::VIEW,
        'save_draft' => CmsEntities::SAVE,
        'publish' => CmsEntities::PUBLISH,
    ];
    private const ACL_FIND_IMAGES = 'Magento_Cms::media_gallery';

    private const STATUS_MESSAGES = [
        'list_components' => 'Reading the Hyvä CMS components...',
        'get' => 'Reading the content...',
        'find_images' => 'Searching the media gallery...',
        'save_draft' => 'Saving the draft...',
        'publish' => 'Publishing...',
    ];

    public function __construct(
        AuthorizationInterface $authorization,
        private readonly ModuleManager $moduleManager,
        private readonly CmsEntities $entities,
        array $actions = []
    ) {
        parent::__construct($authorization, $actions);
    }

    public function getName(): string
    {
        return 'hyva_cms_content';
    }

    public function getDisplayName(): string
    {
        return 'Hyvä CMS';
    }

    public function getStatusMessage(string $action, array $input): ?string
    {
        return self::STATUS_MESSAGES[$action] ?? null;
    }

    public function isAvailable(): bool
    {
        return $this->moduleManager->isEnabled('Hyva_CmsLiveviewEditor')
            && $this->moduleManager->isEnabled('Hyva_CmsMagento');
    }

    /**
     * Hyvä CMS's own resource for the action and content type in $input
     *
     * Stores > Permissions > Hyvä CMS > Pages / Blocks. An unknown action, or none, resolves to publish,
     * the strictest.
     *
     * @param array $input
     * @return string
     */
    public function getMagentoAcl(array $input = []): string
    {
        $action = $input['action'] ?? '';
        if ($action === 'find_images') {
            return self::ACL_FIND_IMAGES;
        }
        $type = in_array($input['type'] ?? null, CmsEntities::TYPES, true) ? $input['type'] : CmsEntities::PAGE;

        return $this->entities->acl($type, self::VERBS[$action] ?? CmsEntities::PUBLISH);
    }

    protected function getBaseDescription(): string
    {
        return 'Build or edit a Hyvä CMS page or CMS block from components: saves a draft, publishes on request. '
            . 'Does not delete content or create new component types.';
    }

    protected function getBaseInstructions(): string
    {
        return '"type" is cms_page (default) or cms_block. Work in this order: list_components (then again with '
            . 'the names you need, for their fields); for existing content get; find_images for any image; '
            . 'propose with save_draft; publish only when the user asks after seeing the draft. Use only the '
            . 'components and fields listed, write real content (no lorem ipsum), give every image an alt text that '
            . 'describes it, and keep headings in order (one h1 per page; a block usually has none). Styling comes '
            . 'from the components\' own options; there are no free CSS classes. '
            . 'Structure: put each section of a page in a row component (it gives width and spacing) and separate '
            . 'sections with spacers, as the store\'s existing pages do; get on an existing Hyvä page shows that '
            . 'structure. A text field\'s default is placeholder text that shows on the page ("USP List '
            . 'Label"): set it, or set it to "" when the section needs none. Give links a label.';
    }
}
