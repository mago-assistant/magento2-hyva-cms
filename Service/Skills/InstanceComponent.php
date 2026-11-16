<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\HyvaCms\Service\Skills;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use MagoAssistant\Mago\Api\Tool\AvailabilityAwareToolInterface;
use MagoAssistant\Mago\Service\Skills\AbstractSkill;

/**
 * Creates Hyvä CMS Instance Components from a description in the admin chat.
 */
class InstanceComponent extends AbstractSkill implements AvailabilityAwareToolInterface
{
    public const ACL_VIEW = 'Hyva_Cms::instance_component_view';
    public const ACL_SAVE = 'Hyva_Cms::instance_component_save';

    private const READ_ACTIONS = ['get_rules'];

    public function __construct(
        AuthorizationInterface $authorization,
        private readonly ModuleManager $moduleManager,
        array $actions = []
    ) {
        parent::__construct($authorization, $actions);
    }

    public function getName(): string
    {
        return 'hyva_cms_instance_component';
    }

    public function isAvailable(): bool
    {
        return $this->moduleManager->isEnabled('Hyva_CmsInstanceComponents');
    }

    /**
     * View for reading the rules; save for anything else, an unknown action included (fail closed)
     *
     * @param array $input
     * @return string
     */
    public function getMagentoAcl(array $input = []): string
    {
        return in_array($input['action'] ?? '', self::READ_ACTIONS, true) ? self::ACL_VIEW : self::ACL_SAVE;
    }

    protected function getBaseDescription(): string
    {
        return 'Create a reusable Hyvä CMS Instance Component (fields + HTML template) from a description. '
            . 'Call get_rules first. Does not place components on pages, and does not edit or delete existing ones.';
    }

    protected function getBaseInstructions(): string
    {
        return 'A new component is saved inactive, so it does not appear in the editor\'s component picker until '
            . 'an administrator reviews and activates it under Instance Components in the Hyvä CMS editor. Say so.';
    }
}
