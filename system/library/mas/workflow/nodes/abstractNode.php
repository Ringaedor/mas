<?php
/**
 * MAS - Marketing Automation Suite
 * AbstractNode
 *
 * Abstract base class for all workflow nodes (triggers, actions, delays, conditions).
 * Provides common functionality for configuration management, validation, serialization,
 * logging, and integration with the ServiceContainer.
 *
 * @copyright Copyright (c) 2025, Your Company
 * @license   Proprietary
 */

namespace Opencart\Library\Mas\Workflow\Node;

use Opencart\Library\Mas\Interfaces\NodeInterface;
use Opencart\Library\Mas\ServiceContainer;
use Opencart\Library\Mas\Exception\WorkflowException;
use Opencart\Library\Mas\Exception\ValidationException;
use Opencart\Library\Mas\Helper\ArrayHelper;
use Opencart\Library\Mas\Helper\DateHelper;
use Opencart\System\Engine\Registry;
use Opencart\System\Engine\Log;

abstract class AbstractNode implements NodeInterface
{
    /**
     * @var ServiceContainer
     */
    protected ServiceContainer $container;

    /**
     * @var Registry
     */
    protected Registry $registry;

    /**
     * @var Log
     */
    protected Log $log;

    /**
     * @var string Unique node identifier
     */
    protected string $id;

    /**
     * @var array Node configuration
     */
    protected array $config = [];

    /**
     * @var array Node execution context
     */
    protected array $context = [];

    /**
     * @var array Node execution result
     */
    protected array $result = [];

    /**
     * @var bool Whether node is currently executing
     */
    protected bool $executing = false;

    /**
     * @var int Node execution start time
     */
    protected int $executionStartTime = 0;

    /**
     * @var int Maximum execution time in seconds
     */
    protected int $maxExecutionTime = 60;

    /**
     * @var array Node metadata
     */
    protected array $metadata = [];

    /**
     * @var string Node version
     */
    protected string $version = '1.0.0';

    /**
     * @var array Validation errors
     */
    protected array $validationErrors = [];

    /**
     * Constructor.
     *
     * @param ServiceContainer $container
     * @param array $config Optional initial configuration
     */
    public function __construct(ServiceContainer $container, array $config = [])
    {
        $this->container = $container;
        $this->registry = $container->get('registry');
        $this->log = $container->get('log');

        $this->id = $this->generateNodeId();
        
        if (!empty($config)) {
            $this->setConfig($config);
        }

        $this->initialize();
    }

    /**
     * Returns the unique identifier of the node instance.
     *
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Sets the node ID.
     *
     * @param string $id
     * @return void
     */
    public function setId(string $id): void
    {
        $this->id = $id;
    }

    /**
     * Returns the node type. (MUST be implemented by subclasses)
     *
     * @return string
     */
    abstract public static function getType(): string;

    /**
     * Returns a short human-readable label for the node.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return static::getType();
    }

    /**
     * Returns a description of the node.
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Node of type: ' . static::getType();
    }

    /**
     * Returns the configuration array for this node.
     *
     * @return array
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Sets the configuration array for this node.
     *
     * @param array $config
     * @return void
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
        $this->onConfigurationChanged();
    }

    /**
     * Updates specific configuration values.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function updateConfig(string $key, $value): void
    {
        $this->config[$key] = $value;
        $this->onConfigurationChanged();
    }

    /**
     * Gets a configuration value.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getConfigValue(string $key, $default = null)
    {
        return ArrayHelper::get($this->config, $key, $default);
    }

    /**
     * Validates the node configuration.
     *
     * @return bool
     */
    public function validate(): bool
    {
        $this->validationErrors = [];
        
        try {
            // Validate against schema
            $this->validateAgainstSchema();
            
            // Custom validation
            $this->validateCustom();
            
            return empty($this->validationErrors);
            
        } catch (\Exception $e) {
            $this->validationErrors[] = $e->getMessage();
            return false;
        }
    }

    /**
     * Returns the validation errors.
     *
     * @return array
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Returns the array schema for the node's configuration. (MUST be implemented by subclasses)
     *
     * @return array
     */
    abstract public static function getConfigSchema(): array;

    /**
     * Executes the node logic.
     *
     * @param array $context
     * @return array
     */
    public function execute(array $context): array
    {
        if ($this->executing) {
            throw new WorkflowException('Node is already executing: ' . $this->id);
        }

        $this->context = $context;
        $this->executing = true;
        $this->executionStartTime = time();

        try {
            // Pre-execution validation
            if (!$this->validate()) {
                throw new ValidationException('Node validation failed', $this->validationErrors);
            }

            // Log execution start
            $this->logExecution('start');

            // Execute the node-specific logic
            $this->result = $this->executeNode($context);

            // Post-execution processing
            $this->result = $this->processResult($this->result);

            // Log execution completion
            $this->logExecution('complete');

            return $this->result;

        } catch (\Exception $e) {
            $this->logExecution('error', $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'node_id' => $this->id,
                'node_type' => static::getType(),
                'execution_time' => $this->getExecutionTime(),
            ];
        } finally {
            $this->executing = false;
        }
    }

    /**
     * Executes the node-specific logic. (MUST be implemented by subclasses)
     *
     * @param array $context
     * @return array
     */
    abstract protected function executeNode(array $context): array;

    /**
     * Serializes this node to array for storage.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => static::getType(),
            'label' => $this->getLabel(),
            'description' => $this->getDescription(),
            'config' => $this->config,
            'metadata' => $this->metadata,
            'version' => $this->version,
            'created_at' => $this->metadata['created_at'] ?? DateHelper::now(),
            'updated_at' => DateHelper::now(),
        ];
    }

    /**
     * Creates a node instance from array data.
     *
     * @param array $data
     * @return static
     */
    public static function fromArray(array $data): self
    {
        $serviceContainer = static::getServiceContainer();
        $instance = new static($serviceContainer);
        
        $instance->setId($data['id'] ?? '');
        $instance->setConfig($data['config'] ?? []);
        $instance->setMetadata($data['metadata'] ?? []);
        $instance->setVersion($data['version'] ?? '1.0.0');
        
        return $instance;
    }

    /**
     * Gets the service container instance.
     *
     * @return ServiceContainer
     */
    protected static function getServiceContainer(): ServiceContainer
    {
        // This would typically be injected or retrieved from a registry
        // For now, we'll assume it's available globally
        global $mas_container;
        return $mas_container;
    }

    /**
     * Returns the node version.
     *
     * @return string
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Sets the node version.
     *
     * @param string $version
     * @return void
     */
    public function setVersion(string $version): void
    {
        $this->version = $version;
    }

    /**
     * Returns the node metadata.
     *
     * @return array
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Sets the node metadata.
     *
     * @param array $metadata
     * @return void
     */
    public function setMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    /**
     * Updates metadata.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function updateMetadata(string $key, $value): void
    {
        $this->metadata[$key] = $value;
    }

    /**
     * Returns the execution context.
     *
     * @return array
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Returns the execution result.
     *
     * @return array
     */
    public function getResult(): array
    {
        return $this->result;
    }

    /**
     * Returns whether the node is currently executing.
     *
     * @return bool
     */
    public function isExecuting(): bool
    {
        return $this->executing;
    }

    /**
     * Returns the execution time in seconds.
     *
     * @return int
     */
    public function getExecutionTime(): int
    {
        return $this->executionStartTime > 0 ? time() - $this->executionStartTime : 0;
    }

    /**
     * Checks if execution has timed out.
     *
     * @return bool
     */
    public function hasTimedOut(): bool
    {
        return $this->getExecutionTime() > $this->maxExecutionTime;
    }

    /**
     * Sets the maximum execution time.
     *
     * @param int $seconds
     * @return void
     */
    public function setMaxExecutionTime(int $seconds): void
    {
        $this->maxExecutionTime = $seconds;
    }

    /**
     * Clones the node with a new ID.
     *
     * @return static
     */
    public function cloneNode(): self
    {
        $clone = clone $this;
        $clone->setId($this->generateNodeId());
        $clone->executing = false;
        $clone->executionStartTime = 0;
        $clone->result = [];
        $clone->context = [];
        $clone->validationErrors = [];
        
        return $clone;
    }

    /**
     * Resets the node to its initial state.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->executing = false;
        $this->executionStartTime = 0;
        $this->result = [];
        $this->context = [];
        $this->validationErrors = [];
    }

    /**
     * Initializes the node. Called after construction.
     *
     * @return void
     */
    protected function initialize(): void
    {
        $this->metadata['created_at'] = DateHelper::now();
        $this->metadata['node_class'] = static::class;
    }

    /**
     * Called when configuration changes.
     *
     * @return void
     */
    protected function onConfigurationChanged(): void
    {
        $this->metadata['updated_at'] = DateHelper::now();
        $this->validationErrors = [];
    }

    /**
     * Validates configuration against schema.
     *
     * @return void
     * @throws ValidationException
     */
    protected function validateAgainstSchema(): void
    {
        $schema = static::getConfigSchema();
        
        foreach ($schema as $field => $rules) {
            $value = $this->getConfigValue($field);
            
            // Check required fields
            if (($rules['required'] ?? false) && ($value === null || $value === '')) {
                $this->validationErrors[] = "Field '{$field}' is required";
                continue;
            }
            
            // Skip validation if field is not set and not required
            if ($value === null || $value === '') {
                continue;
            }
            
            // Type validation
            if (isset($rules['type'])) {
                $this->validateFieldType($field, $value, $rules['type']);
            }
            
            // Range validation
            if (isset($rules['min']) && is_numeric($value) && $value < $rules['min']) {
                $this->validationErrors[] = "Field '{$field}' must be at least {$rules['min']}";
            }
            
            if (isset($rules['max']) && is_numeric($value) && $value > $rules['max']) {
                $this->validationErrors[] = "Field '{$field}' must be at most {$rules['max']}";
            }
            
            // String length validation
            if (isset($rules['min_length']) && is_string($value) && strlen($value) < $rules['min_length']) {
                $this->validationErrors[] = "Field '{$field}' must be at least {$rules['min_length']} characters";
            }
            
            if (isset($rules['max_length']) && is_string($value) && strlen($value) > $rules['max_length']) {
                $this->validationErrors[] = "Field '{$field}' must be at most {$rules['max_length']} characters";
            }
            
            // Pattern validation
            if (isset($rules['pattern']) && is_string($value) && !preg_match($rules['pattern'], $value)) {
                $this->validationErrors[] = "Field '{$field}' format is invalid";
            }
            
            // Options validation
            if (isset($rules['options']) && !in_array($value, $rules['options'])) {
                $this->validationErrors[] = "Field '{$field}' must be one of: " . implode(', ', $rules['options']);
            }
        }
    }

    /**
     * Validates field type.
     *
     * @param string $field
     * @param mixed $value
     * @param string $expectedType
     * @return void
     */
    protected function validateFieldType(string $field, $value, string $expectedType): void
    {
        switch ($expectedType) {
            case 'string':
                if (!is_string($value)) {
                    $this->validationErrors[] = "Field '{$field}' must be a string";
                }
                break;
                
            case 'integer':
            case 'int':
                if (!is_int($value) && !ctype_digit((string)$value)) {
                    $this->validationErrors[] = "Field '{$field}' must be an integer";
                }
                break;
                
            case 'float':
            case 'double':
                if (!is_numeric($value)) {
                    $this->validationErrors[] = "Field '{$field}' must be a number";
                }
                break;
                
            case 'boolean':
            case 'bool':
                if (!is_bool($value) && !in_array($value, ['0', '1', 0, 1, true, false], true)) {
                    $this->validationErrors[] = "Field '{$field}' must be a boolean";
                }
                break;
                
            case 'array':
                if (!is_array($value)) {
                    $this->validationErrors[] = "Field '{$field}' must be an array";
                }
                break;
                
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->validationErrors[] = "Field '{$field}' must be a valid email address";
                }
                break;
                
            case 'url':
                if (!filter_var($value, FILTER_VALIDATE_URL)) {
                    $this->validationErrors[] = "Field '{$field}' must be a valid URL";
                }
                break;
                
            case 'date':
                if (!DateHelper::isValid($value)) {
                    $this->validationErrors[] = "Field '{$field}' must be a valid date";
                }
                break;
        }
    }

    /**
     * Custom validation logic. Override in subclasses.
     *
     * @return void
     */
    protected function validateCustom(): void
    {
        // Override in subclasses for custom validation
    }

    /**
     * Processes the execution result.
     *
     * @param array $result
     * @return array
     */
    protected function processResult(array $result): array
    {
        // Ensure result has required fields
        $processedResult = [
            'success' => $result['success'] ?? false,
            'node_id' => $this->id,
            'node_type' => static::getType(),
            'execution_time' => $this->getExecutionTime(),
            'timestamp' => DateHelper::now(),
        ];

        // Add optional fields
        if (isset($result['output'])) {
            $processedResult['output'] = $result['output'];
        }

        if (isset($result['error'])) {
            $processedResult['error'] = $result['error'];
        }

        if (isset($result['meta'])) {
            $processedResult['meta'] = $result['meta'];
        }

        return $processedResult;
    }

    /**
     * Logs node execution.
     *
     * @param string $event
     * @param string|null $message
     * @return void
     */
    protected function logExecution(string $event, ?string $message = null): void
    {
        $logMessage = "MAS Node [{$this->id}] {$event}";
        
        if ($message) {
            $logMessage .= ": {$message}";
        }
        
        $logMessage .= " (Type: " . static::getType() . ")";
        
        if ($event === 'complete') {
            $logMessage .= " (Execution time: {$this->getExecutionTime()}s)";
        }
        
        $this->log->write($logMessage);
    }

    /**
     * Generates a unique node ID.
     *
     * @return string
     */
    protected function generateNodeId(): string
    {
        return 'node_' . static::getType() . '_' . uniqid() . '_' . time();
    }

    /**
     * Gets a helper service from the container.
     *
     * @param string $service
     * @return mixed
     */
    protected function getHelper(string $service)
    {
        return $this->container->get($service);
    }

    /**
     * Gets a provider from the container.
     *
     * @param string $providerCode
     * @return mixed
     */
    protected function getProvider(string $providerCode)
    {
        $providerManager = $this->container->get('mas.provider_manager');
        return $providerManager->get($providerCode);
    }

    /**
     * Checks if execution should continue.
     *
     * @return bool
     */
    protected function shouldContinue(): bool
    {
        return !$this->hasTimedOut() && $this->executing;
    }

    /**
     * Adds a validation error.
     *
     * @param string $error
     * @return void
     */
    protected function addValidationError(string $error): void
    {
        $this->validationErrors[] = $error;
    }

    /**
     * Clears validation errors.
     *
     * @return void
     */
    protected function clearValidationErrors(): void
    {
        $this->validationErrors = [];
    }

    /**
     * Magic method to access configuration values.
     *
     * @param string $name
     * @return mixed
     */
    public function __get(string $name)
    {
        return $this->getConfigValue($name);
    }

    /**
     * Magic method to set configuration values.
     *
     * @param string $name
     * @param mixed $value
     * @return void
     */
    public function __set(string $name, $value): void
    {
        $this->updateConfig($name, $value);
    }

    /**
     * Magic method to check if configuration value exists.
     *
     * @param string $name
     * @return bool
     */
    public function __isset(string $name): bool
    {
        return isset($this->config[$name]);
    }

    /**
     * String representation of the node.
     *
     * @return string
     */
    public function __toString(): string
    {
        return static::getType() . ' [' . $this->id . ']';
    }
}
