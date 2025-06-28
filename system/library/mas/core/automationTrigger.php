<?php
namespace Opencart\System\Library\Mas\Core;

use Opencart\System\Engine\Registry;

/**
 * AutomationTrigger - Manages automation triggers for the marketing automation suite.
 *
 * Handles registration, evaluation, and management of automation triggers.
 */
class AutomationTrigger {
    /** @var Registry $registry */
    protected $registry;

    /** @var array $triggers */
    protected $triggers = [];

    /**
     * Constructor.
     *
     * @param Registry $registry
     */
    public function __construct(Registry $registry) {
        $this->registry = $registry;
    }

    /**
     * Registers a new automation trigger.
     *
     * @param string $id
     * @param string $name
     * @param callable $callback
     * @param array $config
     * @return void
     */
    public function registerTrigger(string $id, string $name, callable $callback, array $config = []) {
        $this->triggers[$id] = [
            'id'       => $id,
            'name'     => $name,
            'callback' => $callback,
            'config'   => $config
        ];
    }

    /**
     * Removes a trigger by its ID.
     *
     * @param string $id
     * @return bool
     */
    public function removeTrigger(string $id) {
        if (!isset($this->triggers[$id])) {
            return false;
        }
        unset($this->triggers[$id]);
        return true;
    }

    /**
     * Returns a trigger by its ID.
     *
     * @param string $id
     * @return array|null
     */
    public function getTrigger(string $id) {
        return $this->triggers[$id] ?? null;
    }

    /**
     * Returns all registered triggers.
     *
     * @return array
     */
    public function getAllTriggers() {
        return $this->triggers;
    }

    /**
     * Evaluates a trigger with the given context.
     *
     * @param string $id
     * @param array $context
     * @return mixed
     */
    public function evaluateTrigger(string $id, array $context) {
        if (!isset($this->triggers[$id])) {
            return null;
        }
        return call_user_func($this->triggers[$id]['callback'], $context, $this->registry);
    }

    /**
     * Evaluates all triggers with the given context and returns matching results.
     *
     * @param array $context
     * @return array
     */
    public function evaluateAllTriggers(array $context) {
        $results = [];
        foreach ($this->triggers as $id => $trigger) {
            $result = call_user_func($trigger['callback'], $context, $this->registry);
            if ($result !== null) {
                $results[$id] = $result;
            }
        }
        return $results;
    }

    /**
     * Synchronizes trigger definitions with OpenCart.
     * This method ensures that trigger configurations are always up-to-date with the main database.
     *
     * @return bool
     */
    public function syncWithOpenCart() {
        // Example: you would synchronize trigger definitions with OpenCart's database here
        // For now, just keep the logic as a placeholder
        return true;
    }
}