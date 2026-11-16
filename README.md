# MagoAssistant_HyvaCms

Mago Assistant add-on for Hyvä Commerce. Two skills for the admin chat:

- `hyva_cms_instance_component`: describe a component and Mago creates it as a Hyvä CMS Instance Component.
- `hyva_cms_content`: describe a page or block and Mago builds or edits it with Hyvä CMS, as a draft
  first (see [Pages and blocks](#pages-and-blocks-hyva_cms_content) below).

## Instance components: hyva_cms_instance_component

- `get_rules` (read): Hyvä's own generation rules (`AiSafeDefinitionValidatorInterface::getGenerationRules()`)
  and the identifiers already in use. The model calls it before proposing a component.
- `create` (write): saves the proposed fields and template as a new, **inactive** Instance Component
  after the admin confirms it in the chat panel. Review and activate it under Instance Components in
  the Hyvä CMS editor.

Not in scope: editing or deleting existing components.

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

## Pages and blocks: hyva_cms_content

Builds a new Hyvä CMS page or CMS block, or edits an existing one, from the chat. Every action takes
`type`: `cms_page` (default) or `cms_block`. Nothing goes live without two confirmations: the draft,
then the publish.

| Action | Kind | Magento ACL (page / block) |
| --- | --- | --- |
| `list_components` | read: the components the type allows; with `names`, their fields and variants | `Hyva_Cms::page_view` / `block_view` |
| `get` | read: the component tree in save_draft's shape, plus its `revision` | `Hyva_Cms::page_view` / `block_view` |
| `find_images` | read: images in the media gallery (all of them when nothing matches) | `Magento_Cms::media_gallery` |
| `save_draft` | write: new content (created disabled), or a draft of existing Hyvä CMS content | `Hyva_Cms::page_save` / `block_save`, plus `Magento_Cms::save` / `Magento_Cms::block` to create |
| `publish` | write, with cautions: puts the saved draft live | `Hyva_Cms::page_publish` / `block_publish` |

### Example questions

- "Build a page 'Summer at Koti' with a banner, a heading and three USPs about our materials."
- "Make a CMS block 'summer-promo' with a heading and one line about free delivery."
- "On the summer page, give the banner button the label 'Our story' and move the USPs into a row."

### Guarantees

- **Only existing, active components and their declared fields.** Raw HTML, embeds and widgets are not
  offered (they can carry scripts on a CSP-strict theme), and neither are the free CSS classes of the
  "advanced" section. An instance component whose `available_for` leaves out the type is not offered
  there, like in the editor. Field types the chat cannot check (product, category and entity pickers,
  custom types) keep whatever value they have.
- **Every value is validated on the server** before the confirmation card and again before saving,
  because the editor itself checks most of this only in the browser: option values, finite numbers
  and limits (a range starts at 0), length limits, HTML `pattern` attributes, link targets that exist
  (CMS page, category, product, or a same-site / http(s) URL, never `javascript:`), and which
  children a component accepts (`accepts`, `excludes`, `root_only`, `require_parent`). Richtext is
  checked as Hyvä renders it (its escaper and tag list), so no script, event handler or `{{ }}`
  directive gets through, also not split by a comment or empty tag; the editor's own `hyva_link` and
  `hyva_image` stay allowed. Hyvä's own `ContentDocumentGuard` runs on top, with the field check on,
  also before the confirmation, so nothing is created that cannot be saved.
- **Images only from the media gallery**, with an alt text; no URLs, so nothing is hotlinked.
- **Nothing made by hand is overwritten.** `get` returns a revision (hash of the stored draft and live
  content); a write with a stale revision is refused, checked again right before the write. A
  component that stays keeps its uid and every value the chat does not name; a link that keeps its
  target keeps its prefix and suffix, an image keeps its classes. A component left out of the new
  content is refused unless its uid is listed in `remove`.
- **Never takes over an identifier.** A new page or block whose identifier exists anywhere is
  refused. If the first draft cannot be saved, the new page or block is removed again.
- **Classic content is left alone.** Only content that already has Hyvä CMS content can be edited; the
  empty Hyvä row the admin form creates for a classic page does not count.
- **Publish** goes through Hyvä's Provider like the editor and the scheduler: it enables Hyvä CMS
  rendering and cleans the full-page cache (`clean_cache_by_tags`). The card warns when this switches
  classic content to Hyvä CMS, that a block shows wherever it is used, and when the page or block
  itself is disabled; its enabled flag is not touched.

### Tailwind

Hyvä CMS compiles CSS in the editor's browser; a server-side save has none. The chat therefore only
uses the components' own options, whose classes are in the component templates and the theme CSS. A
draft save never writes CSS, so it never wipes it either. A publish copies the editor's draft CSS (from
hand edits) to the live edition when there is any, as the editor's own publish does.

### After a Hyvä CMS upgrade

- `Hyva\CmsLiveviewEditor\Model\Provider::saveContent()` / `saveVersionHistoryItem()` /
  `getStoreContentData()` / `isTailwindJitEnabled()`, and `getByCmsPageId()` / `getByCmsBlockId()` on the
  Hyvä page and block repositories
- `Hyva\CmsLiveviewEditor\Model\Component\Provider::getComponents()`, `ViewModel\ContentTypeAvailabilityMap`
  and the declaration format (sections, `children.config`, `root_only`, `require_parent`, `variants`,
  `available_for`, field `attributes`)
- `Model\Security\ContentDocumentGuard::assertValidJson()`, `Model\Tailwind\JitCssRepository`
- the richtext tag list in `Block\Element::renderRichText()`, mirrored in `FieldValidator::RICHTEXT_TAGS`
- the tree format (root envelope, nodes keyed by uid, `version` block) and the link value format
  (`Block\Element::getLinkData()`)
- ACL resources `Hyva_Cms::page_*` and `Hyva_Cms::block_*`

`Test/Unit/Service/HyvaCms/TreeBuilderTest.php` covers the tree rules against Hyvä-shaped declarations.

### Not in scope

Scheduled publishing, importing images from a URL, compiling Tailwind, snippets, deleting content.
