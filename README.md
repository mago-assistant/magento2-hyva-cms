# MagoAssistant_HyvaCms

Mago Assistant add-on for Hyvä Commerce. Adds the `hyva_cms_instance_component` skill: describe a
component in the admin chat and Mago creates it as a Hyvä CMS Instance Component.

- `get_rules` (read): Hyvä's own generation rules (`AiSafeDefinitionValidatorInterface::getGenerationRules()`)
  and the identifiers already in use. The model calls it before proposing a component.
- `create` (write): saves the proposed fields and template as a new, **inactive** Instance Component
  after the admin confirms it in the chat panel. Review and activate it under Instance Components in
  the Hyvä CMS editor.

Not in scope: editing or deleting existing components, and placing components on a page.

## Example questions

- "Make a USP bar component with three short texts and an optional icon."
- "Create a testimonial component with a quote, the customer's name and a star rating select."

## Guarantees

- The definition goes through Hyvä's `AiSafeDefinitionValidator` before the confirmation card and
  again right before saving. That validator rejects scripts, `<style>`, iframes, Alpine/Magewire
  attributes (`x-*`, `@*`, `:*`, `wire:*`), event handlers and unsafe URLs, so a generated template
  stays CSP-safe on `Hyva/default-csp`. `<img>` is not an allowed tag, so there are no hotlinked
  images; image fields get no default value.
- An identifier that already exists is refused, never overwritten or suffixed.
- Every field key must equal what the Hyvä editor derives from the label (`"USP 1 Text"` -> `usp_1_text`).
  The editor rebuilds keys from labels whenever a component is saved there, which is also how it is
  activated; any other key would be renamed while the template kept the old name.
- The `hyva_cms` cache type is cleaned after a save.
- The skill is hidden when `Hyva_CmsInstanceComponents` is disabled.

## Access

| Action | Magento ACL |
| --- | --- |
| `get_rules` | `Hyva_Cms::instance_component_view` |
| `create` | `Hyva_Cms::instance_component_save` |

Plus the assistant's own skill permission (Stores > Admin Assistant > Skills & Permissions).

## Requirements

- `mago-assistant/magento2-mago` ^1.1
- `hyva-themes/commerce-module-cms` ^1.3 (the AI-safe validator and the `Hyva_Cms::instance_component_*`
  ACL resources arrived in 1.3)

## Install

```bash
composer require mago-assistant/magento2-hyva-cms
bin/magento module:enable MagoAssistant_HyvaCms
bin/magento setup:upgrade
```

## After a Hyvä CMS upgrade

The skill relies on these Hyvä APIs; check that they still exist and behave the same:

- `Hyva\CmsInstanceComponents\Api\AiSafeDefinitionValidatorInterface` (`validate()` return keys
  `identifier`, `label`, `category`, `template`, `declaration`; `getGenerationRules()`)
- `Hyva\CmsInstanceComponents\Api\InstanceComponentRepositoryInterface` (`getByIdentifier()`
  throwing `NoSuchEntityException`, `save()`, `getList()`)
- `normalizeFieldKey()` in `instance-components/view/adminhtml/templates/instance-components/js/handler.phtml`
  (lowercase, whitespace to `_`, drop `[^a-z0-9_]`), mirrored in `CreateAction::assertKeysMatchLabels()`
- cache type `hyva_cms`
- ACL resources `Hyva_Cms::instance_component_view` / `_save`

`Test/Unit` runs against Hyvä's real validator, so a changed rule shows up there.

## Returned fields

| Action | Field | Privacy class |
| --- | --- | --- |
| `get_rules` | `rules`, `existing_identifiers` (all keys) | public |
| `create` | `success`, `message`, `id`, `identifier`, `label`, `is_active` | public |
| all | `error` | always sent |
