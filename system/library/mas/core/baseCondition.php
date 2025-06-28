<?php
namespace Opencart\System\Library\Mas\Core;

use Opencart\System\Engine\Registry;

/**
 * BaseCondition - Abstract base class for automation conditions.
 *
 * Provides common functionality for all automation conditions, including configuration,
 * state management, logging, and validation.
 */
abstract class BaseCondition {
    /** @var Registry $registry */
    protected $registry;

    /** @var string $id */
    protected $id;

    /** @var string $name */
    protected $name;

    /** @var array $config */
    protected $config;

    /** @var string|null $lastEvaluated */
    protected $lastEvaluated;

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
     * Gets the condition unique identifier.
     *
     * @return string
     */
    public function getId(): string {
        return $this->id;
    }

    /**
     * Gets the condition display name.
     *
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * Gets the condition configuration.
     *
     * @return array
     */
    public function getConfig(): array {
        return $this->config;
    }

    /**
     * Sets the condition configuration.
     *
     * @param array $config
     * @return void
     */
    public function setConfig(array $config): void {
        $this->config = $config;
    }

    /**
     * Gets the timestamp of the last time the condition was evaluated.
     *
     * @return string|null
     */
    public function getLastEvaluated(): ?string {
        return $this->lastEvaluated;
    }

    /**
     * Sets the timestamp of the last time the condition was evaluated.
     *
     * @param string $timestamp
     * @return void
     */
    public function setLastEvaluated(string $timestamp): void {
        $this->lastEvaluated = $timestamp;
    }

    /**
     * Adds a log entry for this condition.
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
     * Gets the log entries for this condition.
     *
     * @return array
     */
    public function getLogs(): array {
        return $this->logs;
    }

    /**
     * Validates the condition configuration.
     *
     * @return bool True if configuration is valid, false otherwise
     */
    public function validateConfig(): bool {
        // Example: implement specific validation logic in child classes
        // For now, just return true as a placeholder
        return true;
    }

    /**
     * Evaluates the condition.
     *
     * @param array $context The context data to evaluate
     * @return bool True if the condition is satisfied, false otherwise
     */
    abstract public function evaluate(array $context): bool;

    /**
     * Synchronizes condition state with OpenCart.
     * This method ensures that condition data is always up-to-date with the main database.
     *
     * @return bool
     */
    public function syncWithOpenCart(): bool {
        // Example: you would synchronize the condition configuration and state with OpenCart's database here
        // For now, just keep the logic as a placeholder
        return true;
    }
}