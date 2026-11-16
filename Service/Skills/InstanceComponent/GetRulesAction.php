<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\HyvaCms\Service\Skills\InstanceComponent;

use Hyva\CmsInstanceComponents\Api\AiSafeDefinitionValidatorInterface;
use Hyva\CmsInstanceComponents\Api\InstanceComponentRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

/**
 * Hands the model Hyvä's own allow-list before it writes a definition, plus the identifiers in use.
 */
class GetRulesAction implements ActionInterface
{
    public function __construct(
        private readonly AiSafeDefinitionValidatorInterface $validator,
        private readonly InstanceComponentRepositoryInterface $repository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    public function getName(): string
    {
        return 'get_rules';
    }

    public function getDescription(): string
    {
        return 'Read the rules a new component must follow and the identifiers already taken';
    }

    public function getParameterSchema(): array
    {
        return [];
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
        // The rules are Hyvä's validator allow-list, whose keys follow the installed Hyvä version.
        return [PiiClass::ANY => [PiiClass::PUBLIC]];
    }

    public function getInstructions(): string
    {
        return 'Write the component strictly within these rules; the same validator checks it again before the '
            . 'confirmation and before saving. Pick an identifier that is not in existing_identifiers. '
            . 'Use semantic HTML and keep it accessible (headings in order, alt-like text via aria-label where an '
            . 'element has no visible text). Prefer the theme\'s existing Tailwind utility classes over arbitrary '
            . 'values and hard-coded colours, so the component follows the store\'s design. The validator already '
            . 'rejects scripts and Alpine/JavaScript attributes; never try to work around that.';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $identifiers = [];
        foreach ($this->repository->getList($this->searchCriteriaBuilder->create())->getItems() as $component) {
            $identifiers[] = (string)$component->getIdentifier();
        }

        return [
            'rules' => $this->validator->getGenerationRules(),
            'existing_identifiers' => $identifiers,
        ];
    }
}
