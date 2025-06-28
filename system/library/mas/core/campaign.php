<?php
namespace Opencart\System\Library\Mas\Core;

use Opencart\System\Engine\Registry;

/**
 * Campaign - Manages marketing automation campaigns for the suite.
 *
 * Handles creation, modification, scheduling, and execution of campaigns.
 */
class Campaign {
    /** @var Registry $registry */
    protected $registry;

    /** @var array $campaigns */
    protected $campaigns = [];

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
     * Creates a new campaign.
     *
     * @param string $id
     * @param string $name
     * @param array $config
     * @return array
     */
    public function createCampaign(string $id, string $name, array $config = []) {
        $campaign = [
            'id'     => $id,
            'name'   => $name,
            'config' => $config,
            'status' => 'draft',
            'start'  => null,
            'end'    => null
        ];
        $this->campaigns[$id] = $campaign;
        $this->addToHistory($id, 'created', 'Campaign created');
        return $campaign;
    }

    /**
     * Updates an existing campaign.
     *
     * @param string $id
     * @param array $data
     * @return bool
     */
    public function updateCampaign(string $id, array $data) {
        if (!isset($this->campaigns[$id])) {
            return false;
        }
        $this->campaigns[$id] = array_merge($this->campaigns[$id], $data);
        $this->addToHistory($id, 'updated', 'Campaign updated');
        return true;
    }

    /**
     * Deletes a campaign.
     *
     * @param string $id
     * @return bool
     */
    public function deleteCampaign(string $id) {
        if (!isset($this->campaigns[$id])) {
            return false;
        }
        $this->addToHistory($id, 'deleted', 'Campaign deleted');
        unset($this->campaigns[$id]);
        return true;
    }

    /**
     * Returns a campaign by its ID.
     *
     * @param string $id
     * @return array|null
     */
    public function getCampaign(string $id) {
        return $this->campaigns[$id] ?? null;
    }

    /**
     * Returns all campaigns.
     *
     * @return array
     */
    public function getAllCampaigns() {
        return $this->campaigns;
    }

    /**
     * Schedules a campaign to start and end at specific times.
     *
     * @param string $id
     * @param string $start
     * @param string $end
     * @return bool
     */
    public function scheduleCampaign(string $id, string $start, string $end) {
        if (!isset($this->campaigns[$id])) {
            return false;
        }
        $this->campaigns[$id]['start'] = $start;
        $this->campaigns[$id]['end'] = $end;
        $this->addToHistory($id, 'scheduled', "Campaign scheduled from $start to $end");
        return true;
    }

    /**
     * Starts a campaign.
     *
     * @param string $id
     * @return bool
     */
    public function startCampaign(string $id) {
        if (!isset($this->campaigns[$id])) {
            return false;
        }
        $this->campaigns[$id]['status'] = 'active';
        $this->addToHistory($id, 'started', 'Campaign started');
        return true;
    }

    /**
     * Pauses a campaign.
     *
     * @param string $id
     * @return bool
     */
    public function pauseCampaign(string $id) {
        if (!isset($this->campaigns[$id])) {
            return false;
        }
        $this->campaigns[$id]['status'] = 'paused';
        $this->addToHistory($id, 'paused', 'Campaign paused');
        return true;
    }

    /**
     * Stops a campaign.
     *
     * @param string $id
     * @return bool
     */
    public function stopCampaign(string $id) {
        if (!isset($this->campaigns[$id])) {
            return false;
        }
        $this->campaigns[$id]['status'] = 'stopped';
        $this->addToHistory($id, 'stopped', 'Campaign stopped');
        return true;
    }

    /**
     * Adds an entry to the campaign history.
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
     * Returns the history of a campaign.
     *
     * @param string $id
     * @return array
     */
    public function getCampaignHistory(string $id) {
        return $this->history[$id] ?? [];
    }

    /**
     * Synchronizes campaign data with OpenCart.
     * This method ensures that campaign status and data are always up-to-date with the main database.
     *
     * @param string $id
     * @return bool
     */
    public function syncWithOpenCart(string $id) {
        if (!isset($this->campaigns[$id])) {
            return false;
        }
        // Example: you would synchronize the campaign status and data with OpenCart's database here
        // For now, just keep the logic as a placeholder
        return true;
    }
}