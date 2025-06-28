<?php
namespace Opencart\System\Library\Mas\Core;

use Opencart\System\Engine\Registry;

/**
 * Segment - Manages the creation, editing and management of user segments of the marketing automation suite.
 *
 * Allows you to define groups of users based on dynamic criteria and synchronize them with OpenCart data.
 */
class Segment {
    /** @var Registry $registry */
    protected $registry;

    /** @var array $segments */
    protected $segments = [];

    /**
     * Constructor.
     *
     * @param Registry $registry
     */
    public function __construct(Registry $registry) {
        $this->registry = $registry;
    }

    /**
     * Creates a new segment.
     *
     * @param string $id
     * @param string $name
     * @param array $criteria
     * @return array
     */
    public function createSegment(string $id, string $name, array $criteria = []) {
        $this->segments[$id] = [
            'id'       => $id,
            'name'     => $name,
            'criteria' => $criteria,
            'users'    => []
        ];
        return $this->segments[$id];
    }

    /**
     * Updates an existing segment.
     *
     * @param string $id
     * @param array $data
     * @return bool
     */
    public function updateSegment(string $id, array $data) {
        if (!isset($this->segments[$id])) {
            return false;
        }
        $this->segments[$id] = array_merge($this->segments[$id], $data);
        return true;
    }

    /**
     * Deletes a segment.
     *
     * @param string $id
     * @return bool
     */
    public function deleteSegment(string $id) {
        if (!isset($this->segments[$id])) {
            return false;
        }
        unset($this->segments[$id]);
        return true;
    }

    /**
     * Returns a segment by its ID.
     *
     * @param string $id
     * @return array|null
     */
    public function getSegment(string $id) {
        return $this->segments[$id] ?? null;
    }

    /**
     * Returns all segments.
     *
     * @return array
     */
    public function getAllSegments() {
        return $this->segments;
    }

    /**
     * Adds users to a segment.
     *
     * @param string $segmentId
     * @param array $userIds
     * @return bool
     */
    public function addUsersToSegment(string $segmentId, array $userIds) {
        if (!isset($this->segments[$segmentId])) {
            return false;
        }
        foreach ($userIds as $userId) {
            if (!in_array($userId, $this->segments[$segmentId]['users'])) {
                $this->segments[$segmentId]['users'][] = $userId;
            }
        }
        return true;
    }

    /**
     * Removes users from a segment.
     *
     * @param string $segmentId
     * @param array $userIds
     * @return bool
     */
    public function removeUsersFromSegment(string $segmentId, array $userIds) {
        if (!isset($this->segments[$segmentId])) {
            return false;
        }
        foreach ($userIds as $userId) {
            $index = array_search($userId, $this->segments[$segmentId]['users']);
            if ($index !== false) {
                array_splice($this->segments[$segmentId]['users'], $index, 1);
            }
        }
        return true;
    }

    /**
     * Returns the users in a segment.
     *
     * @param string $segmentId
     * @return array
     */
    public function getUsersInSegment(string $segmentId) {
        return $this->segments[$segmentId]['users'] ?? [];
    }

    /**
     * Evaluates a segment based on its criteria and returns matching user IDs.
     * This method is a placeholder for dynamic evaluation logic.
     *
     * @param string $segmentId
     * @return array
     */
    public function evaluateSegment(string $segmentId) {
        if (!isset($this->segments[$segmentId])) {
            return [];
        }

        // Example: you would query the database here based on the segment criteria
        // For now, just return the current users for demonstration
        return $this->segments[$segmentId]['users'];
    }

    /**
     * Synchronizes segment users with OpenCart user data.
     * This method ensures that the segment users are always up-to-date with the main user database.
     *
     * @param string $segmentId
     * @return bool
     */
    public function syncWithOpenCart(string $segmentId) {
        if (!isset($this->segments[$segmentId])) {
            return false;
        }

        // Example: you would fetch user IDs from OpenCart here based on the segment criteria
        // For now, just keep the logic as a placeholder
        // $this->segments[$segmentId]['users'] = $this->fetchUserIdsFromOpenCart($this->segments[$segmentId]['criteria']);
        return true;
    }
}