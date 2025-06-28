<?php
namespace Opencart\System\Library\Mas\Core;

use Opencart\System\Engine\Registry;

/**
 * AutomationAction - Manages automation actions for the marketing automation suite.
 *
 * Handles registration, execution, and management of automation actions.
 */
class AutomationAction {
    /** @var Registry $registry */
    protected $registry;

    /** @var array $actions */
    protected $actions = [];

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
     * Registers a new automation action.
     *
     * @param string $id
     * @param string $name
     * @param callable $callback
     * @param array $config
     * @return void
     */
    public function registerAction(string $id, string $name, callable $callback, array $config = []) {
        $this->actions[$id] = [
            'id'       => $id,
            'name'     => $name,
            'callback' => $callback,
            'config'   => $config
        ];
    }

    /**
     * Removes an action by its ID.
     *
     * @param string $id
     * @return bool
     */
    public function removeAction(string $id) {
        if (!isset($this->actions[$id])) {
            return false;
        }
        unset($this->actions[$id]);
        return true;
    }

    /**
     * Returns an action by its ID.
     *
     * @param string $id
     * @return array|null
     */
    public function getAction(string $id) {
        return $this->actions[$id] ?? null;
    }

    /**
     * Returns all registered actions.
     *
     * @return array
     */
    public function getAllActions() {
        return $this->actions;
    }

    /**
     * Executes an action with the given context.
     *
     * @param string $id
     * @param array $context
     * @return mixed
     */
    public function executeAction(string $id, array $context) {
        if (!isset($this->actions[$id])) {
            return null;
        }
        $result = call_user_func($this->actions[$id]['callback'], $context, $this->registry);
        $this->addToHistory($id, $result, $context);
        return $result;
    }

    /**
     * Executes all actions with the given context and returns results.
     *
     * @param array $context
     * @return array
     */
    public function executeAllActions(array $context) {
        $results = [];
        foreach ($this->actions as $id => $action) {
            $result = call_user_func($action['callback'], $context, $this->registry);
            $results[$id] = $result;
            $this->addToHistory($id, $result, $context);
        }
        return $results;
    }

    /**
     * Adds an entry to the action history.
     *
     * @param string $id
     * @param mixed $result
     * @param array $context
     * @return void
     */
    protected function addToHistory(string $id, $result, array $context) {
        $this->history[$id][] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'result'    => $result,
            'context'   => $context
        ];
    }

    /**
     * Returns the history of an action.
     *
     * @param string $id
     * @return array
     */
    public function getActionHistory(string $id) {
        return $this->history[$id] ?? [];
    }

    /**
     * Synchronizes action definitions with OpenCart.
     * This method ensures that action configurations are always up-to-date with the main database.
     *
     * @return bool
     */
    public function syncWithOpenCart() {
        // Example: you would synchronize action definitions with OpenCart's database here
        // For now, just keep the logic as a placeholder
        return true;
    }
}