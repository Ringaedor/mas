<?php
/**
 * MAS - Marketing Automation Suite
 * AbstractProvider
 *
 * Abstract base class for all channel providers (email, sms, push, ai, etc.)
 * Supports provider auto-discovery, schema definition, capability declaration,
 * configuration management, connection handling, and core action execution.
 *
 * Providers extending this class must implement ChannelProviderInterface
 * and define static methods for metadata and configuration schema.
 *
 * @copyright Copyright (c) 2025, Your Company
 * @license   Proprietary
 */

namespace Opencart\Library\Mas\Provider;

use Opencart\Library\Mas\Interfaces\ChannelProviderInterface;
use Opencart\Library\Mas\Exception\ProviderException;

abstract class AbstractProvider implements ChannelProviderInterface
{
    /**
     * @var array The provider's runtime configuration
     */
    protected array $config = [];

    /**
     * @var bool Whether the provider is authenticated
     */
    protected bool $authenticated = false;

    /**
     * @var string|null Last error message encountered
     */
    protected ?string $lastError = null;

    /**
     * Constructor.
     *
     * @param array $config Optional runtime configuration
     */
    public function __construct(array $config = [])
    {
        if (!empty($config)) {
            $this->setConfig($config);
        }
    }

    /**
     * Sends a message or executes the provider’s primary action.
     *
     * @param array $payload
     * @return array
     * @throws ProviderException
     */
    abstract public function send(array $payload): array;

    /**
     * Authenticates or initialises the provider with given credentials/config.
     *
     * @param array $config
     * @return bool
     */
    public function authenticate(array $config): bool
    {
        // Default: simply check config fields as required by schema
        $schema = static::getSetupSchema();
        if (isset($schema['schema']) && is_array($schema['schema'])) {
            foreach ($schema['schema'] as $field => $def) {
                if (($def['required'] ?? false) && empty($config[$field])) {
                    $this->lastError = "Missing required field: {$field}";
                    $this->authenticated = false;
                    return false;
                }
            }
        }
        $this->authenticated = true;
        $this->setConfig($config);
        $this->lastError = null;
        return true;
    }

    /**
     * Tests connectivity and credentials without sending a real message.
     * Override if needed for connection-specific checks.
     *
     * @return bool
     */
    public function testConnection(): bool
    {
        // Default: authenticate with current config, success if all required fields are set
        if (empty($this->config)) {
            $this->lastError = "Provider is not configured";
            return false;
        }
        return $this->authenticate($this->config);
    }

    /**
     * Returns the unique provider name. (MUST override)
     *
     * @return string
     */
    public static function getName(): string
    {
        return static::class;
    }

    /**
     * Returns a short human-readable description. (MUST override)
     *
     * @return string
     */
    public static function getDescription(): string
    {
        return '';
    }

    /**
     * Returns the provider type ('email','sms','push','ai', etc.). (MUST override)
     *
     * @return string
     */
    public static function getType(): string
    {
        return '';
    }

    /**
     * Returns provider semantic version.
     *
     * @return string
     */
    public static function getVersion(): string
    {
        return defined('static::VERSION') ? static::VERSION : '1.0.0';
    }

    /**
     * Returns array describing provider capabilities.
     * Override to specify ['bulk_send','tracking','template', etc.]
     *
     * @return string[]
     */
    public static function getCapabilities(): array
    {
        return [];
    }

    /**
     * Returns the full setup schema definition: name, type, version, setup fields, capabilities, etc.
     * MUST be overridden in each concrete provider.
     *
     * @return array
     */
    public static function getSetupSchema(): array
    {
        return [
            'provider' => [
                'name' => static::getName(),
                'type' => static::getType(),
                'version' => static::getVersion(),
            ],
            'schema' => [],      // Setup fields (override in subclass)
            'capabilities' => static::getCapabilities(),
        ];
    }

    /**
     * Sets or updates runtime configuration after authentication.
     *
     * @param array $config
     * @return void
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    /**
     * Returns current runtime configuration (sensitive values may be removed).
     *
     * @return array
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Gets the last error message, if any.
     *
     * @return string|null
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Checks if the provider is currently authenticated.
     *
     * @return bool
     */
    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    /**
     * Returns a sanitised configuration array (removing sensitive fields).
     *
     * @param array|null $fieldsToMask Optional array of keys to mask/remove
     * @return array
     */
    public function getSanitisedConfig(array $fieldsToMask = null): array
    {
        $config = $this->config;
        $mask = $fieldsToMask ?: ['api_key', 'password', 'token', 'secret', 'smtp_pass'];
        foreach ($mask as $field) {
            if (array_key_exists($field, $config)) {
                $config[$field] = str_repeat('*', 8);
            }
        }
        return $config;
    }

    /**
     * Returns true if the provider supports a given capability.
     *
     * @param string $capability
     * @return bool
     */
    public static function supports(string $capability): bool
    {
        return in_array($capability, static::getCapabilities(), true);
    }

    /**
     * Returns the provider's display label (fallback to name if no label).
     *
     * @return string
     */
    public static function getLabel(): string
    {
        return static::getName();
    }
}
