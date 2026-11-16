<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\HyvaCms\Service\HyvaCms;

use Hyva\CmsLiveviewEditor\Model\Provider;
use Hyva\CmsLiveviewEditor\Model\Security\ContentDocumentGuard;
use Hyva\CmsLiveviewEditor\Model\Tailwind\JitCssRepository;
use Hyva\CmsMagento\Api\BlockRepositoryInterface as HyvaBlockRepositoryInterface;
use Hyva\CmsMagento\Api\PageRepositoryInterface as HyvaPageRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\User\Model\UserFactory;

/**
 * Reads and writes the component tree of a Hyvä CMS page or block, the way the editor and the scheduler do
 *
 * Writes go through the editor's own Provider (saveContent + saveVersionHistoryItem), so a draft shows
 * up in the editor and version history, and a publish cleans the full-page cache of the page or block.
 *
 * Hyvä has no concurrency check of its own (two editors: last save wins). The revision here is a hash
 * of the stored draft and published content: the chat reads it with get and must send it back, and a
 * write is refused when the content changed in between, so hand edits in the editor are never overwritten.
 *
 * Tailwind CSS is compiled in the editor's browser; a server-side save has none of its own. A draft
 * save leaves the stored CSS alone (an empty set would delete it). A publish copies the editor's draft
 * CSS to the live edition when there is any, as the editor does on its own publish.
 */
class ContentStore
{
    private const VERSION_DEFAULTS = [
        'restored_to' => [], 'restored_from' => null, 'name' => '', 'published_by_schedule' => null,
        'schedule_item_id' => null, 'schedule_item_name' => null, 'release_name' => null,
        'release_id' => null, 'release_scheduled_time' => null,
    ];

    public function __construct(
        private readonly HyvaPageRepositoryInterface $hyvaPageRepository,
        private readonly HyvaBlockRepositoryInterface $hyvaBlockRepository,
        private readonly Provider $provider,
        private readonly ContentDocumentGuard $contentDocumentGuard,
        private readonly JitCssRepository $jitCssRepository,
        private readonly Json $json,
        private readonly UserFactory $userFactory
    ) {
    }

    /**
     * The stored state, null when the page or block has no Hyvä CMS content
     *
     * Read straight from the repository, not through the Provider, which caches per request. A row
     * without draft or live content counts as none: Hyvä creates one for a classic page each time it is
     * saved in the admin form, and that page is not built with Hyvä CMS.
     *
     * @param string $type CmsEntities::PAGE or BLOCK
     * @param int $id
     * @return array|null Keys: draft, published (decoded documents or null), revision,
     *                    has_unpublished_changes, is_liveview_enabled
     */
    public function load(string $type, int $id): ?array
    {
        $row = $type === CmsEntities::BLOCK
            ? $this->hyvaBlockRepository->getByCmsBlockId($id)
            : $this->hyvaPageRepository->getByCmsPageId($id);
        if ($row === null) {
            return null;
        }
        $draft = (string)$row->getDraftContent();
        $published = (string)$row->getPublishedContent();
        if ($this->decode($draft) === null && $this->decode($published) === null) {
            return null;
        }

        return [
            'draft' => $this->decode($draft),
            'published' => $this->decode($published),
            'revision' => substr(hash('sha256', $draft . "\0" . $published), 0, 16),
            'has_unpublished_changes' => $this->contentOf($draft) !== $this->contentOf($published),
            'is_liveview_enabled' => (bool)$row->isLiveviewEnabled(),
        ];
    }

    /**
     * The tree the next draft builds on: the draft, else the live version
     *
     * @param array|null $state What load() returned
     * @return array
     */
    public function workingDocument(?array $state): array
    {
        return $state['draft'] ?? $state['published'] ?? [];
    }

    /**
     * Refuse a write when the content changed since the chat read it
     *
     * @param string $type
     * @param int $id
     * @param mixed $revision What get or save_draft returned
     * @return array|null The current state
     * @throws LocalizedException
     */
    public function assertRevision(string $type, int $id, mixed $revision): ?array
    {
        $state = $this->load($type, $id);
        $current = $state['revision'] ?? null;
        if ($current !== null && $revision !== $current) {
            throw new LocalizedException(__(
                'This content was changed since it was read (for example in the Hyvä CMS editor). Nothing was saved, '
                . 'so no manual edits are lost: call get again and apply the change to the current content.'
            ));
        }

        return $state;
    }

    /**
     * The document a draft save would store, checked by Hyvä's guard; nothing is written
     *
     * Called before the confirmation card too, so a document Hyvä would refuse (too large, an unknown
     * field) is refused before a new page or block is created for it.
     *
     * @param string $type
     * @param int $id 0 for new content
     * @param array $document The current document, empty for new content
     * @param array $content The new tree, keyed by uid
     * @return array
     * @throws LocalizedException
     */
    public function draftDocument(string $type, int $id, array $document, array $content): array
    {
        $document = $document ?: $this->newDocument($type, $id);
        $document['content'] = $content;
        $document['contentId'] = (string)$id;
        $this->encode($document);

        return $document;
    }

    /**
     * Save a new draft; the live content does not change
     *
     * @param string $type
     * @param int $id
     * @param array $document What draftDocument() returned
     * @param string|null $revision The revision the chat confirmed against, null for new content
     * @param int $adminUserId The admin the version history names as author
     * @return string The new revision
     * @throws LocalizedException
     */
    public function saveDraft(string $type, int $id, array $document, ?string $revision, int $adminUserId): string
    {
        $document['contentId'] = (string)$id;
        $json = $this->encode($this->withVersion($type, $document, 'draft', $this->author($adminUserId)));
        if ($revision !== null) {
            // ponytail: re-read right before the write; Hyvä has no row lock to make this atomic.
            $this->assertRevision($type, $id, $revision);
        }

        $this->provider->saveContent($type, $id, $json, false);
        $this->provider->saveVersionHistoryItem($type, $id, $json, false);

        return (string)$this->load($type, $id)['revision'];
    }

    /**
     * Publish the stored draft as it is
     *
     * @param string $type
     * @param int $id
     * @param array $draft
     * @param string $revision The revision the chat confirmed against
     * @param int $adminUserId
     * @return void
     * @throws LocalizedException
     */
    public function publish(string $type, int $id, array $draft, string $revision, int $adminUserId): void
    {
        $json = $this->encode($this->withVersion($type, $draft, 'published', $this->author($adminUserId)));
        $this->assertRevision($type, $id, $revision);

        // Also enables Hyvä CMS rendering and cleans the full-page cache (clean_cache_by_tags).
        $this->provider->saveContent($type, $id, $json, true);
        $this->provider->saveVersionHistoryItem($type, $id, $json, true);

        // Classes from hand edits in the editor were compiled into the draft edition; without this copy
        // they have no CSS on the live page. Never an empty set: that would delete the live CSS.
        $draftStyles = array_filter($this->jitCssRepository->getAllThemeStyles($type, $id, 'draft'));
        if ($draftStyles !== [] && $this->provider->isTailwindJitEnabled($type, $id)) {
            $this->jitCssRepository->saveStyles($type, $id, $draftStyles, 'published');
        }
    }

    /**
     * Signed storefront link that renders the current draft, for the first store view it is in
     *
     * @param string $type
     * @param int $id
     * @return string|null
     */
    public function previewUrl(string $type, int $id): ?string
    {
        foreach ($this->provider->getStoreContentData($type, $id) as $store) {
            if (!empty($store['is_assigned']) && !empty($store['preview_url'])) {
                return (string)$store['preview_url'];
            }
        }

        return null;
    }

    /**
     * The JSON to store, after Hyvä's own server-side guard
     *
     * Hyvä's repository runs the same guard on save, but without the field check; running it here with
     * fields on refuses a component or field Hyvä does not know before anything is written.
     *
     * @param array $document
     * @return string
     * @throws LocalizedException Also SecurityViolationException, the guard's refusal
     */
    public function encode(array $document): string
    {
        try {
            $json = $this->json->serialize($document);
        } catch (\InvalidArgumentException $e) {
            throw new LocalizedException(__('The content cannot be stored: %1', $e->getMessage()));
        }
        $this->contentDocumentGuard->assertValidJson($json, 'Mago chat content', true);

        return $json;
    }

    private function author(int $adminUserId): string
    {
        $username = (string)$this->userFactory->create()->load($adminUserId)->getUserName();

        return $username !== '' ? $username . ' (Mago)' : 'Mago';
    }

    private function newDocument(string $type, int $id): array
    {
        return [
            'componentPath' => 'elements/ROOT.phtml',
            'contentType' => $type,
            'contentId' => (string)$id,
            // Kept for the life of the content: the editor's preview hash is derived from it.
            'uid' => bin2hex(random_bytes(7)),
            'content' => [],
            'version' => [],
            'requestHash' => '',
        ];
    }

    private function withVersion(string $type, array $document, string $state, string $author): array
    {
        $now = date('Y-m-d\TH:i:sP');
        // Defaults after the previous version, as the editor's resetVersioningData() does: a chat draft is
        // not "restored from" or "published by schedule" because the version before it was.
        $version = array_merge((array)($document['version'] ?? []), self::VERSION_DEFAULTS, [
            'uid' => bin2hex(random_bytes(7)),
            'author' => $author,
            'version_state' => $state,
            'content_type' => $type,
            'timestamp' => $now,
            'last_edit_timestamp' => $now,
            'last_save_timestamp' => $now,
        ]);
        if ($state === 'published') {
            $version['last_publish_timestamp'] = $now;
        }
        $document['version'] = $version;

        return $document;
    }

    private function decode(string $json): ?array
    {
        if ($json === '' || $json === '[]') {
            return null;
        }
        $document = json_decode($json, true);

        return is_array($document) ? $document : null;
    }

    private function contentOf(string $json): string
    {
        return (string)json_encode($this->decode($json)['content'] ?? []);
    }
}
