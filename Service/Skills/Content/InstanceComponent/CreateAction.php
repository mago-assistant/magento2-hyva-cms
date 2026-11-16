<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\HyvaCms\Service\Skills\Content\InstanceComponent;

use Hyva\CmsInstanceComponents\Api\AiSafeDefinitionValidatorInterface;
use Hyva\CmsInstanceComponents\Api\Data\InstanceComponentInterfaceFactory;
use Hyva\CmsInstanceComponents\Api\InstanceComponentRepositoryInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Api\Skill\ValidatingActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

/**
 * Saves a model-written component as a new, inactive Instance Component.
 *
 * Hyvä's AiSafeDefinitionValidator decides what is allowed (field types, HTML tags, attributes,
 * classes, template syntax), both before the confirmation card and again right before saving.
 * The same path as Hyvä's own AjaxPromote, except that the component starts inactive and an
 * identifier that is taken is refused instead of suffixed.
 */
class CreateAction implements ActionInterface, ValidatingActionInterface
{
    private const CANDIDATE_KEYS = ['label', 'identifier', 'category', 'description', 'fields', 'template'];
    private const CACHE_TYPE = 'hyva_cms';

    public function __construct(
        private readonly AiSafeDefinitionValidatorInterface $validator,
        private readonly InstanceComponentRepositoryInterface $repository,
        private readonly InstanceComponentInterfaceFactory $componentFactory,
        private readonly TypeListInterface $cacheTypeList,
        private readonly Json $json
    ) {
    }

    public function getName(): string
    {
        return 'create';
    }

    public function getDescription(): string
    {
        return 'Save a new component, inactive, after the user confirms';
    }

    public function getParameterSchema(): array
    {
        return [
            'identifier' => [
                'type' => 'string',
                'description' => 'Unique component identifier: lowercase letters, numbers and hyphens, starting with '
                    . 'a letter, 3-64 characters (e.g. "usp-bar") (required)',
            ],
            'label' => [
                'type' => 'string',
                'description' => 'Name shown in the editor\'s component picker (required)',
            ],
            'category' => [
                'type' => 'string',
                'description' => 'Picker category (default "Custom")',
            ],
            'description' => [
                'type' => 'string',
                'description' => 'One-line description shown in the picker',
            ],
            'fields' => [
                'type' => 'array',
                'description' => 'Editable fields, in the order the editor shows them (required)',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'key' => ['type' => 'string', 'description' => 'Lowercase letters, numbers, underscores'],
                        'type' => ['type' => 'string', 'description' => 'A field type from get_rules'],
                        'label' => ['type' => 'string'],
                        'options' => [
                            'type' => 'array',
                            'description' => 'Only for select fields',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'value' => ['type' => 'string'],
                                    'label' => ['type' => 'string'],
                                ],
                                'required' => ['value', 'label'],
                            ],
                        ],
                        'default_value' => [
                            'type' => ['string', 'boolean'],
                            'description' => 'Only for text, textarea, richtext (string), boolean (bool) and select '
                                . '(an option value)',
                        ],
                        'classes' => [
                            'type' => 'string',
                            'description' => 'Only for image and link fields: Tailwind classes for the element',
                        ],
                    ],
                    'required' => ['key', 'type', 'label'],
                ],
            ],
            'template' => [
                'type' => 'string',
                'description' => 'HTML template with one root element, using {field}, {#if field}...{#else}...{/if} '
                    . '(required)',
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
            'id' => [PiiClass::PUBLIC],
            'identifier' => [PiiClass::PUBLIC],
            'label' => [PiiClass::PUBLIC],
            'is_active' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return 'If the component was refused, fix exactly what the message names and propose it again.';
    }

    public function findRefusal(array $params): ?array
    {
        try {
            $this->validate($params);
        } catch (LocalizedException $e) {
            return ['error' => $e->getMessage()];
        }

        return null;
    }

    public function execute(array $params, int $adminUserId): array
    {
        try {
            $validated = $this->validate($params);
        } catch (LocalizedException $e) {
            return ['error' => $e->getMessage()];
        }

        $component = $this->componentFactory->create();
        $component->setIdentifier((string)$validated['identifier']);
        $component->setLabel((string)$validated['label']);
        $component->setCategory((string)$validated['category']);
        $component->setDeclaration($this->json->serialize($validated['declaration']));
        $component->setTemplate((string)$validated['template']);
        $component->setIsActive(false);

        try {
            // The unique key on identifier still refuses a component created since validate() ran.
            $saved = $this->repository->save($component);
        } catch (LocalizedException) {
            // The repository's message carries the raw SQL error; never hand that to the model.
            return $this->findRefusal($params) ?? ['error' => 'The component could not be saved.'];
        }
        $this->cacheTypeList->cleanType(self::CACHE_TYPE);

        return [
            'success' => true,
            'message' => 'Component "' . $validated['label'] . '" created inactive. Review and activate it under '
                . 'Instance Components in the Hyvä CMS editor.',
            'id' => (int)$saved->getId(),
            'identifier' => $validated['identifier'],
            'label' => $validated['label'],
            'is_active' => false,
        ];
    }

    /**
     * Run Hyvä's validator on the definition and make sure the identifier is still free
     *
     * @param array $params The tool input
     * @return array<string, mixed> The validator's normalised definition
     * @throws LocalizedException
     */
    private function validate(array $params): array
    {
        $isSet = static fn ($value) => $value !== null && $value !== '' && $value !== [];
        $candidate = array_filter(array_intersect_key($params, array_flip(self::CANDIDATE_KEYS)), $isSet);
        if (is_array($candidate['fields'] ?? null)) {
            // Some providers fill in every schema property, so a text field arrives with "options": []
            // or "classes": "". Hyvä refuses those keys outside select/image/link; empty means absent.
            $candidate['fields'] = array_map(
                static fn ($field) => is_array($field) ? array_filter($field, $isSet) : $field,
                $candidate['fields']
            );
        }
        $validated = $this->validator->validate($candidate);
        $this->assertKeysMatchLabels($validated['fields']);

        try {
            $this->repository->getByIdentifier((string)$validated['identifier']);
        } catch (NoSuchEntityException) {
            return $validated;
        }

        throw new LocalizedException(__(
            'An Instance Component with identifier "%1" already exists. Existing components are never '
            . 'overwritten; choose another identifier.',
            $validated['identifier']
        ));
    }

    /**
     * Refuse a field whose key is not what the Hyvä editor derives from its label
     *
     * The Instance Components editor rebuilds every key from the label when an admin saves the
     * component (normalizeFieldKey() in instance-components/js/handler.phtml), and activating a
     * component means saving it there. A key that differs is renamed on activation while the
     * template keeps the old name, so that field silently stops rendering.
     *
     * @param array $fields The validator's normalised fields
     * @return void
     * @throws LocalizedException
     */
    private function assertKeysMatchLabels(array $fields): void
    {
        foreach ($fields as $field) {
            $expected = (string)preg_replace(
                '/[^a-z0-9_]/',
                '',
                (string)preg_replace('/\s+/u', '_', mb_strtolower((string)$field['label']))
            );
            if ($field['key'] !== $expected) {
                throw new LocalizedException(__(
                    'Field "%1" must use the key "%2": the Hyvä editor derives each key from its label (lowercase, '
                    . 'spaces become underscores, other characters dropped) and renames any other key when the '
                    . 'component is saved there. Use "%2" as key and in the template, or change the label.',
                    $field['key'],
                    $expected
                ));
            }
        }
    }
}
