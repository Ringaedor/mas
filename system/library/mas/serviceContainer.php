<?php
/**
 * MAS - Marketing Automation Suite
 * Service Container for dependency injection and service management
 *
 * This class provides a simple dependency injection container for the MAS library.
 * It manages service registration, resolution, and lifecycle, supporting both
 * singleton and factory patterns for service instantiation.
 *
 * @copyright Copyright (c) 2025, Your Company
 * @license   Proprietary
 */

namespace Opencart\Library\Mas;

use Closure;
use Exception;
use Opencart\Library\Mas\Exception as MasException;

/**
 * Service Container for MAS dependency injection.
 */
class ServiceContainer
{
    /**
     * @var array<string, mixed> Registered services and their definitions
     */
    protected $services = [];

    /**
     * @var array<string, mixed> Singleton instances cache
     */
    protected $instances = [];

    /**
     * @var array<string, bool> Service singleton flags
     */
    protected $singletons = [];

    /**
     * @var array<string, string> Service aliases
     */
    protected $aliases = [];

    /**
     * @var array<string, array> Service tags for grouping
     */
    protected $tags = [];

    /**
     * Registers a service in the container.
     *
     * @param string $id Service identifier
     * @param mixed $definition Service definition (callable, object, or class name)
     * @param bool $singleton Whether to treat as singleton (default: true)
     * @param array $tags Optional tags for service grouping
     * @throws MasException If service ID is already registered
     */
    public function set(string $id, $definition, bool $singleton = true, array $tags = []): void
    {
        if (isset($this->services[$id])) {
            throw new MasException("Service '{$id}' is already registered");
        }

        $this->services[$id] = $definition;
        $this->singletons[$id] = $singleton;

        if (!empty($tags)) {
            $this->tags[$id] = $tags;
        }
    }

    /**
     * Retrieves a service from the container.
     *
     * @param string $id Service identifier
     * @return mixed The service instance
     * @throws MasException If service is not found
     */
    public function get(string $id)
    {
        // Check if it's an alias
        if (isset($this->aliases[$id])) {
            $id = $this->aliases[$id];
        }

        if (!isset($this->services[$id])) {
            throw new MasException("Service '{$id}' not found");
        }

        // Return singleton instance if already created
        if ($this->singletons[$id] && isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        $instance = $this->resolve($id);

        // Cache singleton instances
        if ($this->singletons[$id]) {
            $this->instances[$id] = $instance;
        }

        return $instance;
    }

    /**
     * Checks if a service is registered.
     *
     * @param string $id Service identifier
     * @return bool True if service exists, false otherwise
     */
    public function has(string $id): bool
    {
        return isset($this->services[$id]) || isset($this->aliases[$id]);
    }

    /**
     * Registers an alias for a service.
     *
     * @param string $alias Alias name
     * @param string $id Original service ID
     * @throws MasException If alias already exists or service not found
     */
    public function alias(string $alias, string $id): void
    {
        if (isset($this->aliases[$alias])) {
            throw new MasException("Alias '{$alias}' already exists");
        }

        if (!isset($this->services[$id])) {
            throw new MasException("Cannot create alias '{$alias}' for non-existent service '{$id}'");
        }

        $this->aliases[$alias] = $id;
    }

    /**
     * Registers a factory service (always creates new instances).
     *
     * @param string $id Service identifier
     * @param callable $factory Factory function
     * @param array $tags Optional tags for service grouping
     */
    public function factory(string $id, callable $factory, array $tags = []): void
    {
        $this->set($id, $factory, false, $tags);
    }

    /**
     * Registers a singleton service.
     *
     * @param string $id Service identifier
     * @param mixed $definition Service definition
     * @param array $tags Optional tags for service grouping
     */
    public function singleton(string $id, $definition, array $tags = []): void
    {
        $this->set($id, $definition, true, $tags);
    }

    /**
     * Gets all services with specific tags.
     *
     * @param string $tag Tag name
     * @return array<string, mixed> Services matching the tag
     */
    public function getByTag(string $tag): array
    {
        $services = [];
        
        foreach ($this->tags as $serviceId => $serviceTags) {
            if (in_array($tag, $serviceTags)) {
                $services[$serviceId] = $this->get($serviceId);
            }
        }

        return $services;
    }

    /**
     * Extends an existing service definition.
     *
     * @param string $id Service identifier
     * @param callable $extender Function to extend the service
     * @throws MasException If service not found
     */
    public function extend(string $id, callable $extender): void
    {
        if (!isset($this->services[$id])) {
            throw new MasException("Cannot extend non-existent service '{$id}'");
        }

        $originalDefinition = $this->services[$id];
        
        $this->services[$id] = function () use ($originalDefinition, $extender) {
            $service = $this->resolveDefinition($originalDefinition);
            return $extender($service, $this);
        };

        // Clear singleton instance if it exists
        if (isset($this->instances[$id])) {
            unset($this->instances[$id]);
        }
    }

    /**
     * Removes a service from the container.
     *
     * @param string $id Service identifier
     */
    public function remove(string $id): void
    {
        unset($this->services[$id]);
        unset($this->instances[$id]);
        unset($this->singletons[$id]);
        unset($this->tags[$id]);

        // Remove aliases pointing to this service
        foreach ($this->aliases as $alias => $serviceId) {
            if ($serviceId === $id) {
                unset($this->aliases[$alias]);
            }
        }
    }

    /**
     * Clears all services from the container.
     */
    public function clear(): void
    {
        $this->services = [];
        $this->instances = [];
        $this->singletons = [];
        $this->aliases = [];
        $this->tags = [];
    }

    /**
     * Gets all registered service IDs.
     *
     * @return array<string> Service IDs
     */
    public function getServiceIds(): array
    {
        return array_keys($this->services);
    }

    /**
     * Gets all registered aliases.
     *
     * @return array<string, string> Aliases mapped to service IDs
     */
    public function getAliases(): array
    {
        return $this->aliases;
    }

    /**
     * Resolves a service definition into an instance.
     *
     * @param string $id Service identifier
     * @return mixed Service instance
     * @throws MasException If service cannot be resolved
     */
    protected function resolve(string $id)
    {
        try {
            return $this->resolveDefinition($this->services[$id]);
        } catch (Exception $e) {
            throw new MasException("Error resolving service '{$id}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Resolves a service definition.
     *
     * @param mixed $definition Service definition
     * @return mixed Resolved service instance
     * @throws MasException If definition cannot be resolved
     */
    protected function resolveDefinition($definition)
    {
        if ($definition instanceof Closure) {
            return $definition($this);
        }

        if (is_callable($definition)) {
            return call_user_func($definition, $this);
        }

        if (is_object($definition)) {
            return $definition;
        }

        if (is_string($definition) && class_exists($definition)) {
            return new $definition($this);
        }

        if (is_array($definition)) {
            // Handle array-based definitions for future extensibility
            throw new MasException("Array-based service definitions are not yet supported");
        }

        throw new MasException("Invalid service definition");
    }

    /**
     * Magic method to access services as properties.
     *
     * @param string $id Service identifier
     * @return mixed Service instance
     */
    public function __get(string $id)
    {
        return $this->get($id);
    }

    /**
     * Magic method to check if service exists.
     *
     * @param string $id Service identifier
     * @return bool True if service exists
     */
    public function __isset(string $id): bool
    {
        return $this->has($id);
    }
}
