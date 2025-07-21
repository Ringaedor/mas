<?php
/**
 * MAS - Marketing Automation Suite
 * EventDispatcher
 *
 * Central event dispatcher supporting listener registration, event firing,
 * priority management, wildcard listeners, and asynchronous queue integration.
 *
 * Path: system/library/mas/events/EventDispatcher.php
 */

namespace Opencart\Library\Mas\Events;

class EventDispatcher
{
    /**
     * @var array Registered event listeners [event => [priority => [callable, ...]]]
     */
    protected array $listeners = [];
    
    /**
     * Registers a listener for an event.
     *
     * @param string $event
     * @param callable $listener
     * @param int $priority
     */
    public function addListener(string $event, callable $listener, int $priority = 0): void
    {
        $this->listeners[$event][$priority][] = $listener;
        // Ensure priorities are sorted
        krsort($this->listeners[$event], SORT_NUMERIC);
    }
    
    /**
     * Dispatches an event to all relevant listeners.
     *
     * @param string $event
     * @param mixed $payload
     * @return array Array of listener return values
     */
    public function dispatch(string $event, $payload = null): array
    {
        $responses = [];
        
        foreach ($this->getListenersForEvent($event) as $listener) {
            $responses[] = $listener($payload, $event, $this);
        }
        
        return $responses;
    }
    
    /**
     * Gets all listeners for a given event (including wildcards).
     *
     * @param string $event
     * @return array
     */
    protected function getListenersForEvent(string $event): array
    {
        $matched = [];
        
        // Direct listeners
        if (isset($this->listeners[$event])) {
            foreach ($this->listeners[$event] as $priorityListeners) {
                foreach ($priorityListeners as $listener) {
                    $matched[] = $listener;
                }
            }
        }
        
        // Wildcard listeners (e.g. 'order.*')
        foreach ($this->listeners as $registeredEvent => $priorityGroups) {
            if (strpos($registeredEvent, '*') !== false) {
                $pattern = '/^' . str_replace('\*', '.*', preg_quote($registeredEvent, '/')) . '$/';
                if (preg_match($pattern, $event)) {
                    foreach ($priorityGroups as $priorityListeners) {
                        foreach ($priorityListeners as $listener) {
                            $matched[] = $listener;
                        }
                    }
                }
            }
        }
        
        return $matched;
    }
    
    /**
     * Removes a listener.
     *
     * @param string $event
     * @param callable $listener
     * @return bool
     */
    public function removeListener(string $event, callable $listener): bool
    {
        if (!isset($this->listeners[$event])) {
            return false;
        }
        
        foreach ($this->listeners[$event] as $priority => &$priorityListeners) {
            foreach ($priorityListeners as $i => $existing) {
                if ($existing === $listener) {
                    unset($priorityListeners[$i]);
                    if (empty($priorityListeners)) {
                        unset($this->listeners[$event][$priority]);
                    }
                    return true;
                }
            }
        }
        return false;
    }
    
    /**
     * Clears all listeners for an event (or for all if none given).
     *
     * @param string|null $event
     * @return void
     */
    public function clearListeners(?string $event = null): void
    {
        if ($event) {
            unset($this->listeners[$event]);
        } else {
            $this->listeners = [];
        }
    }
}
