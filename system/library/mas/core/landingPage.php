<?php
namespace Opencart\System\Library\Mas\Core;

use Opencart\System\Engine\Registry;

/**
 * LandingPage - Manages landing pages for the marketing automation suite.
 *
 * Handles creation, editing, publishing, and management of landing pages as dynamic content.
 */
class LandingPage {
    /** @var Registry $registry */
    protected $registry;

    /** @var array $landingPages */
    protected $landingPages = [];

    /** @var array $history */
    protected $history = [];

    /**
     * Constructor.
     *
     * @param Registry $registry
     */
    public function __construct(Registry $registry) {
        $this->registry = $registry;
    }

    /**
     * Creates a new landing page.
     *
     * @param string $id
     * @param string $title
     * @param string $content
     * @param array $config
     * @return array
     */
    public function createLandingPage(string $id, string $title, string $content, array $config = []) {
        $landingPage = [
            'id'      => $id,
            'title'   => $title,
            'content' => $content,
            'config'  => $config,
            'status'  => 'draft',
            'url'     => null
        ];
        $this->landingPages[$id] = $landingPage;
        $this->addToHistory($id, 'created', 'Landing page created');
        return $landingPage;
    }

    /**
     * Updates an existing landing page.
     *
     * @param string $id
     * @param array $data
     * @return bool
     */
    public function updateLandingPage(string $id, array $data) {
        if (!isset($this->landingPages[$id])) {
            return false;
        }
        $this->landingPages[$id] = array_merge($this->landingPages[$id], $data);
        $this->addToHistory($id, 'updated', 'Landing page updated');
        return true;
    }

    /**
     * Deletes a landing page.
     *
     * @param string $id
     * @return bool
     */
    public function deleteLandingPage(string $id) {
        if (!isset($this->landingPages[$id])) {
            return false;
        }
        $this->addToHistory($id, 'deleted', 'Landing page deleted');
        unset($this->landingPages[$id]);
        return true;
    }

    /**
     * Returns a landing page by its ID.
     *
     * @param string $id
     * @return array|null
     */
    public function getLandingPage(string $id) {
        return $this->landingPages[$id] ?? null;
    }

    /**
     * Returns all landing pages.
     *
     * @return array
     */
    public function getAllLandingPages() {
        return $this->landingPages;
    }

    /**
     * Publishes a landing page, generating a URL and setting it as active.
     *
     * @param string $id
     * @param string $url
     * @return bool
     */
    public function publishLandingPage(string $id, string $url) {
        if (!isset($this->landingPages[$id])) {
            return false;
        }
        $this->landingPages[$id]['status'] = 'published';
        $this->landingPages[$id]['url'] = $url;
        $this->addToHistory($id, 'published', "Landing page published at $url");
        return true;
    }

    /**
     * Unpublishes a landing page, setting it as draft and removing the URL.
     *
     * @param string $id
     * @return bool
     */
    public function unpublishLandingPage(string $id) {
        if (!isset($this->landingPages[$id])) {
            return false;
        }
        $this->landingPages[$id]['status'] = 'draft';
        $this->landingPages[$id]['url'] = null;
        $this->addToHistory($id, 'unpublished', 'Landing page unpublished');
        return true;
    }

    /**
     * Adds an entry to the landing page history.
     *
     * @param string $id
     * @param string $action
     * @param string $message
     * @return void
     */
    protected function addToHistory(string $id, string $action, string $message) {
        $this->history[$id][] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'action'    => $action,
            'message'   => $message
        ];
    }

    /**
     * Returns the history of a landing page.
     *
     * @param string $id
     * @return array
     */
    public function getLandingPageHistory(string $id) {
        return $this->history[$id] ?? [];
    }

    /**
     * Synchronizes landing page data with OpenCart.
     * This method ensures that landing page content and status are always up-to-date with the main database.
     *
     * @param string $id
     * @return bool
     */
    public function syncWithOpenCart(string $id) {
        if (!isset($this->landingPages[$id])) {
            return false;
        }
        // Example: you would synchronize the landing page content and status with OpenCart's database here
        // For now, just keep the logic as a placeholder
        return true;
    }
}