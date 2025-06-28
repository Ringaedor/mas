<?php
namespace Opencart\System\Library\Mas\Core;

use Opencart\System\Engine\Registry;

/**
 * BaseAction - Abstract base class for automation actions.
 *
 * Provides common functionality for all automation actions, including configuration,
 * state management, logging, and validation.
 */
abstract class BaseAction {
    /** @var Registry $registry */
    protected $registry;

    /** @var string $id */
    protected $id;

    /** @var string $name */
    protected $name;

    /** @var array $config */
    protected $config;

    /** @var string|null $lastExecuted */
    protected $lastExecuted;

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
     * Gets the action unique identifier.
     *
     * @return string
     */
    public function getId(): string {
        return $this->id;
    }

    /**
     * Gets the action display name.
     *
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * Gets the action configuration.
     *
     * @return array
     */
    public function getConfig(): array {
        return $this->config;
    }

    /**
     * Sets the action configuration.
     *
     * @param array $config
     * @return void
     */
    public function setConfig(array $config): void {
        $this->config = $config;
    }

    /**
     * Gets the timestamp of the last time the action was executed.
     *
     * @return string|null
     */
    public function getLastExecuted(): ?string {
        return $this->lastExecuted;
    }

    /**
     * Sets the timestamp of the last time the action was executed.
     *
     * @param string $timestamp
     * @return void
     */
    public function setLastExecuted(string $timestamp): void {
        $this->lastExecuted = $timestamp;
    }

    /**
     * Adds a log entry for this action.
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
     * Gets the log entries for this action.
     *
     * @return array
     */
    public function getLogs(): array {
        return $this->logs;
    }

    /**
     * Validates the action configuration.
     *
     * @return bool True if configuration is valid, false otherwise
     */
    public function validateConfig(): bool {
        // Example: you can implement specific validation logic in child classes
        // For now, just return true as a placeholder
        return true;
    }

    /**
     * Executes the action.
     *
     * @param array $context The context data to use
     * @return mixed The result of the action
     */
    abstract public function execute(array $context);

    /**
     * Synchronizes action state with OpenCart.
     * This method ensures that action data is always up-to-date with the main database.
     *
     * @return bool
     */
    public function syncWithOpenCart(): bool {
        // Example: you would synchronize the action configuration and state with OpenCart's database here
        // For now, just keep the logic as a placeholder
        return true;
    }
}