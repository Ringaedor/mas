<?php
namespace Opencart\System\Library\Mas\Core;

use Opencart\System\Engine\Registry;

/**
 * BaseTrigger - Abstract base class for automation triggers.
 *
 * Provides common functionality for all automation triggers, including configuration,
 * state management, logging, and validation.
 */
abstract class BaseTrigger {
    /** @var Registry $registry */
    protected $registry;

    /** @var string $id */
    protected $id;

    /** @var string $name */
    protected $name;

    /** @var array $config */
    protected $config;

    /** @var string|null $lastTriggered */
    protected $lastTriggered;

    /** @var array $logs */
    protected $logs = [];

    /**
     * Constructor.
     *
     * @param Registry $registry
     * @param string $id
     * @param string $name
     * @param array $config
     */
    public function __construct(Registry $registry, string $id, string $name, array $config = []) {
        $this->registry = $registry;
        $this->id = $id;
        $this->name = $name;
        $this->config = $config;
    }

    /**
     * Gets the trigger unique identifier.
     *
     * @return string
     */
    public function getId(): string {
        return $this->id;
    }

    /**
     * Gets the trigger display name.
     *
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * Gets the trigger configuration.
     *
     * @return array
     */
    public function getConfig(): array {
        return $this->config;
    }

    /**
     * Sets the trigger configuration.
     *
     * @param array $config
     * @return void
     */
    public function setConfig(array $config): void {
        $this->config = $config;
    }

    /**
     * Gets the timestamp of the last time the trigger was activated.
     *
     * @return string|null
     */
    public function getLastTriggered(): ?string {
        return $this->lastTriggered;
    }

    /**
     * Sets the timestamp of the last time the trigger was activated.
     *
     * @param string $timestamp
     * @return void
     */
    public function setLastTriggered(string $timestamp): void {
        $this->lastTriggered = $timestamp;
    }

    /**
     * Adds a log entry for this trigger.
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public function log(string $message, array $context = []): void {
        $this->logs[] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'message'   => $message,
            'context'   => $context
        ];
    }

    /**
     * Gets the log entries for this trigger.
     *
     * @return array
     */
    public function getLogs(): array {
        return $this->logs;
    }

    /**
     * Validates the trigger configuration.
     *
     * @return bool True if configuration is valid, false otherwise
     */
    public function validateConfig(): bool {
        // Example: you can implement specific validation logic in child classes
        // For now, just return true as a placeholder
        return true;
    }

    /**
     * Checks if the trigger should be activated.
     *
     * @param array $context The context data to evaluate
     * @return bool True if the trigger is activated, false otherwise
     */
    abstract public function check(array $context): bool;

    /**
     * Synchronizes trigger state with OpenCart.
     * This method ensures that trigger data is always up-to-date with the main database.
     *
     * @return bool
     */
    public function syncWithOpenCart(): bool {
        // Example: you would synchronize the trigger configuration and state with OpenCart's database here
        // For now, just keep the logic as a placeholder
        return true;
    }
}