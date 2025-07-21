<?php
/**
 * MAS - Marketing Automation Suite
 * Base Exception class for all MAS related exceptions
 *
 * This file defines the base exception class for the MAS library and all
 * specialized exception classes used throughout the system. It provides
 * enhanced error handling capabilities with context support and proper
 * error categorization for different MAS components.
 *
 * @copyright Copyright (c) 2025, Your Company
 * @license   Proprietary
 */

namespace Opencart\Library\Mas;

use Exception;
use Throwable;

/**
 * Base exception class for all MAS exceptions.
 */
class Exception extends \Exception
{
    /**
     * @var array Additional context data for the exception
     */
    protected $context = [];

    /**
     * @var string Error category for grouping related exceptions
     */
    protected $category = 'general';

    /**
     * @var bool Whether this exception should be logged
     */
    protected $shouldLog = true;

    /**
     * @var bool Whether this exception should trigger notifications
     */
    protected $shouldNotify = false;

    /**
     * Constructor.
     *
     * @param string $message Exception message
     * @param int $code Exception code
     * @param array $context Additional context data
     * @param Throwable|null $previous Previous exception
     */
    public function __construct(string $message = '', int $code = 0, array $context = [], ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /**
     * Gets the exception context.
     *
     * @return array Context data
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Sets the exception context.
     *
     * @param array $context Context data
     * @return self
     */
    public function setContext(array $context): self
    {
        $this->context = $context;
        return $this;
    }

    /**
     * Adds context data to the exception.
     *
     * @param string $key Context key
     * @param mixed $value Context value
     * @return self
     */
    public function addContext(string $key, $value): self
    {
        $this->context[$key] = $value;
        return $this;
    }

    /**
     * Gets the exception category.
     *
     * @return string Category name
     */
    public function getCategory(): string
    {
        return $this->category;
    }

    /**
     * Sets the exception category.
     *
     * @param string $category Category name
     * @return self
     */
    public function setCategory(string $category): self
    {
        $this->category = $category;
        return $this;
    }

    /**
     * Checks if this exception should be logged.
     *
     * @return bool True if should be logged
     */
    public function shouldLog(): bool
    {
        return $this->shouldLog;
    }

    /**
     * Sets whether this exception should be logged.
     *
     * @param bool $shouldLog Log flag
     * @return self
     */
    public function setShouldLog(bool $shouldLog): self
    {
        $this->shouldLog = $shouldLog;
        return $this;
    }

    /**
     * Checks if this exception should trigger notifications.
     *
     * @return bool True if should notify
     */
    public function shouldNotify(): bool
    {
        return $this->shouldNotify;
    }

    /**
     * Sets whether this exception should trigger notifications.
     *
     * @param bool $shouldNotify Notify flag
     * @return self
     */
    public function setShouldNotify(bool $shouldNotify): self
    {
        $this->shouldNotify = $shouldNotify;
        return $this;
    }

    /**
     * Converts the exception to an array for logging/debugging.
     *
     * @return array Exception data
     */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'category' => $this->getCategory(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'context' => $this->getContext(),
            'trace' => $this->getTraceAsString(),
            'previous' => $this->getPrevious() ? $this->getPrevious()->getMessage() : null,
        ];
    }

    /**
     * Creates a formatted error message with context.
     *
     * @return string Formatted message
     */
    public function getFormattedMessage(): string
    {
        $message = $this->getMessage();
        
        if (!empty($this->context)) {
            $contextStr = json_encode($this->context, JSON_PRETTY_PRINT);
            $message .= "\nContext: " . $contextStr;
        }

        return $message;
    }
}

/**
 * Exception for provider-related errors.
 */
class ProviderException extends Exception
{
    protected $category = 'provider';
    protected $shouldLog = true;
    protected $shouldNotify = true;

    /**
     * @var string Provider type that caused the error
     */
    protected $providerType;

    /**
     * @var string Provider name that caused the error
     */
    protected $providerName;

    /**
     * Constructor.
     *
     * @param string $message Exception message
     * @param string $providerType Provider type
     * @param string $providerName Provider name
     * @param int $code Exception code
     * @param array $context Additional context
     * @param Throwable|null $previous Previous exception
     */
    public function __construct(string $message, string $providerType = '', string $providerName = '', int $code = 0, array $context = [], ?Throwable $previous = null)
    {
        $this->providerType = $providerType;
        $this->providerName = $providerName;
        
        parent::__construct($message, $code, $context, $previous);
    }

    /**
     * Gets the provider type.
     *
     * @return string Provider type
     */
    public function getProviderType(): string
    {
        return $this->providerType;
    }

    /**
     * Gets the provider name.
     *
     * @return string Provider name
     */
    public function getProviderName(): string
    {
        return $this->providerName;
    }
}

/**
 * Exception for workflow-related errors.
 */
class WorkflowException extends Exception
{
    protected $category = 'workflow';
    protected $shouldLog = true;

    /**
     * @var int Workflow ID that caused the error
     */
    protected $workflowId;

    /**
     * Constructor.
     *
     * @param string $message Exception message
     * @param int $workflowId Workflow ID
     * @param int $code Exception code
     * @param array $context Additional context
     * @param Throwable|null $previous Previous exception
     */
    public function __construct(string $message, int $workflowId = 0, int $code = 0, array $context = [], ?Throwable $previous = null)
    {
        $this->workflowId = $workflowId;
        parent::__construct($message, $code, $context, $previous);
    }

    /**
     * Gets the workflow ID.
     *
     * @return int Workflow ID
     */
    public function getWorkflowId(): int
    {
        return $this->workflowId;
    }
}

/**
 * Exception for segmentation-related errors.
 */
class SegmentException extends Exception
{
    protected $category = 'segment';
    protected $shouldLog = true;

    /**
     * @var int Segment ID that caused the error
     */
    protected $segmentId;

    /**
     * Constructor.
     *
     * @param string $message Exception message
     * @param int $segmentId Segment ID
     * @param int $code Exception code
     * @param array $context Additional context
     * @param Throwable|null $previous Previous exception
     */
    public function __construct(string $message, int $segmentId = 0, int $code = 0, array $context = [], ?Throwable $previous = null)
    {
        $this->segmentId = $segmentId;
        parent::__construct($message, $code, $context, $previous);
    }

    /**
     * Gets the segment ID.
     *
     * @return int Segment ID
     */
    public function getSegmentId(): int
    {
        return $this->segmentId;
    }
}

/**
 * Exception for AI service-related errors.
 */
class AIException extends Exception
{
    protected $category = 'ai';
    protected $shouldLog = true;

    /**
     * @var string AI service name that caused the error
     */
    protected $serviceName;

    /**
     * Constructor.
     *
     * @param string $message Exception message
     * @param string $serviceName AI service name
     * @param int $code Exception code
     * @param array $context Additional context
     * @param Throwable|null $previous Previous exception
     */
    public function __construct(string $message, string $serviceName = '', int $code = 0, array $context = [], ?Throwable $previous = null)
    {
        $this->serviceName = $serviceName;
        parent::__construct($message, $code, $context, $previous);
    }

    /**
     * Gets the AI service name.
     *
     * @return string Service name
     */
    public function getServiceName(): string
    {
        return $this->serviceName;
    }
}

/**
 * Exception for campaign-related errors.
 */
class CampaignException extends Exception
{
    protected $category = 'campaign';
    protected $shouldLog = true;

    /**
     * @var int Campaign ID that caused the error
     */
    protected $campaignId;

    /**
     * Constructor.
     *
     * @param string $message Exception message
     * @param int $campaignId Campaign ID
     * @param int $code Exception code
     * @param array $context Additional context
     * @param Throwable|null $previous Previous exception
     */
    public function __construct(string $message, int $campaignId = 0, int $code = 0, array $context = [], ?Throwable $previous = null)
    {
        $this->campaignId = $campaignId;
        parent::__construct($message, $code, $context, $previous);
    }

    /**
     * Gets the campaign ID.
     *
     * @return int Campaign ID
     */
    public function getCampaignId(): int
    {
        return $this->campaignId;
    }
}

/**
 * Exception for consent management errors.
 */
class ConsentException extends Exception
{
    protected $category = 'consent';
    protected $shouldLog = true;
    protected $shouldNotify = true;

    /**
     * @var int Customer ID related to the consent error
     */
    protected $customerId;

    /**
     * @var string Channel related to the consent error
     */
    protected $channel;

    /**
     * Constructor.
     *
     * @param string $message Exception message
     * @param int $customerId Customer ID
     * @param string $channel Channel name
     * @param int $code Exception code
     * @param array $context Additional context
     * @param Throwable|null $previous Previous exception
     */
    public function __construct(string $message, int $customerId = 0, string $channel = '', int $code = 0, array $context = [], ?Throwable $previous = null)
    {
        $this->customerId = $customerId;
        $this->channel = $channel;
        parent::__construct($message, $code, $context, $previous);
    }

    /**
     * Gets the customer ID.
     *
     * @return int Customer ID
     */
    public function getCustomerId(): int
    {
        return $this->customerId;
    }

    /**
     * Gets the channel.
     *
     * @return string Channel name
     */
    public function getChannel(): string
    {
        return $this->channel;
    }
}

/**
 * Exception for configuration-related errors.
 */
class ConfigException extends Exception
{
    protected $category = 'config';
    protected $shouldLog = true;
    protected $shouldNotify = true;

    /**
     * @var string Configuration key that caused the error
     */
    protected $configKey;

    /**
     * Constructor.
     *
     * @param string $message Exception message
     * @param string $configKey Configuration key
     * @param int $code Exception code
     * @param array $context Additional context
     * @param Throwable|null $previous Previous exception
     */
    public function __construct(string $message, string $configKey = '', int $code = 0, array $context = [], ?Throwable $previous = null)
    {
        $this->configKey = $configKey;
        parent::__construct($message, $code, $context, $previous);
    }

    /**
     * Gets the configuration key.
     *
     * @return string Configuration key
     */
    public function getConfigKey(): string
    {
        return $this->configKey;
    }
}

/**
 * Exception for validation-related errors.
 */
class ValidationException extends Exception
{
    protected $category = 'validation';
    protected $shouldLog = false;

    /**
     * @var array Validation errors
     */
    protected $errors = [];

    /**
     * Constructor.
     *
     * @param string $message Exception message
     * @param array $errors Validation errors
     * @param int $code Exception code
     * @param array $context Additional context
     * @param Throwable|null $previous Previous exception
     */
    public function __construct(string $message, array $errors = [], int $code = 0, array $context = [], ?Throwable $previous = null)
    {
        $this->errors = $errors;
        parent::__construct($message, $code, $context, $previous);
    }

    /**
     * Gets the validation errors.
     *
     * @return array Validation errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Adds a validation error.
     *
     * @param string $field Field name
     * @param string $message Error message
     * @return self
     */
    public function addError(string $field, string $message): self
    {
        $this->errors[$field] = $message;
        return $this;
    }

    /**
     * Checks if there are validation errors.
     *
     * @return bool True if errors exist
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }
}
