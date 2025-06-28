<?php
namespace Opencart\System\Library\Mas\Core;

use Opencart\System\Engine\Registry;

/**
 * EventDispatcher - Centralized event management for the marketing automation suite.
 *
 * Handles registration, dispatching, and logging of events for triggers, actions, and workflows.
 */
class EventDispatcher {
    /** @var Registry $registry */
    protected $registry;

    /** @var array $listeners */
    protected $listeners = [];

    /** @var array $eventHistory */
    protected $eventHistory = [];

    /**
     * Constructor.
     *
     * @param Registry $registry
     */
    public function __construct(Registry $registry) {
        $this->registry = $registry;
    }

    /**
     * Registers a listener for a specific event.
     *
     * @param string $eventName
     * @param callable $listener
     * @return void
     */
    public function addListener(string $eventName, callable $listener): void {
        if (!isset($this->listeners[$eventName])) {
            $this->listeners[$eventName] = [];
        }
        $this->listeners[$eventName][] = $listener;
    }

    /**
     * Removes a listener from a specific event.
     *
     * @param string $eventName
     * @param callable $listener
     * @return bool True if the listener was removed, false otherwise
     */
    public function removeListener(string $eventName, callable $listener): bool {
        if (!isset($this->listeners[$eventName])) {
            return false;
        }
        $index = array_search($listener, $this->listeners[$eventName], true);
        if ($index === false) {
            return false;
        }
        array_splice($this->listeners[$eventName], $index, 1);
        return true;
    }

    /**
     * Dispatches an event to all registered listeners.
     *
     * @param string $eventName
     * @param array $eventData
     * @return void
     */
    public function dispatch(string $eventName, array $eventData = []): void {
        $this->logEvent($eventName, $eventData);

        if (!isset($this->listeners[$eventName])) {
            return;
        }

        foreach ($this->listeners[$eventName] as $listener) {
            call_user_func($listener, $eventData, $this->registry);
        }
    }

    /**
     * Logs an event in the history for auditing and troubleshooting.
     *
     * @param string $eventName
     * @param array $eventData
     * @return void
     */
    protected function logEvent(string $eventName, array $eventData): void {
        $this->eventHistory[] = [
            'timestamp'  => date('Y-m-d H:i:s'),
            'event'      => $eventName,
            'data'       => $eventData,
            'dispatched' => true
        ];
    }

    /**
     * Gets all listeners for a specific event.
     *
     * @param string $eventName
     * @return array
     */
    public function getListeners(string $eventName): array {
        return $this->listeners[$eventName] ?? [];
    }

    /**
     * Gets the event history for auditing and troubleshooting.
     *
     * @param string|null $eventName If specified, returns history for that event only
     * @return array
     */
    public function getEventHistory(?string $eventName = null): array {
        if ($eventName === null) {
            return $this->eventHistory;
        }
        return array_filter($this->eventHistory, function($entry) use ($eventName) {
            return $entry['event'] === $eventName;
        });
    }

    /**
     * Synchronizes event dispatcher state with OpenCart.
     * This method ensures that event data is always up-to-date with the main database.
     *
     * @return bool
     */
    public function syncWithOpenCart(): bool {
        // Example: you would synchronize the event dispatcher state with OpenCart's database here
        // For now, just keep the logic as a placeholder
        return true;
    }

    /**
     * Clears all listeners for a specific event.
     *
     * @param string $eventName
     * @return void
     */
    public function clearListeners(string $eventName): void {
        $this->listeners[$eventName] = [];
    }
}