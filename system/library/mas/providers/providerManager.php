<?php
/**
 * MAS - Marketing Automation Suite
 * ProviderManager
 *
 * Handles auto-discovery, registration, configuration and lifecycle of all provider classes
 * (email, sms, push, ai, ecc.) used in MAS. Supports recursive scan of providers/,
 * dynamic loading, runtime instantiation, configuration persistence, and capability querying.
 *
 * @copyright Copyright (c) 2025, Your Company
 * @license   Proprietary
 */

namespace Opencart\Library\Mas\Provider;

use Opencart\Library\Mas\ServiceContainer;
use Opencart\Library\Mas\Interfaces\ChannelProviderInterface;
use Opencart\Library\Mas\Exception\ProviderException;
use DirectoryIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

class ProviderManager
{
    /**
     * @var ServiceContainer
     */
    protected ServiceContainer $container;

    /**
     * @var string[] Provider root search paths
     */
    protected array $providerPaths = [];

    /**
     * @var array<string, string> FQCN of discovered providers [unique_code => class]
     */
    protected array $providers = [];

    /**
     * @var array<string, array> Instantiated provider instances [unique_code => object]
     */
    protected array $instances = [];

    /**
     * @var array<string, array> Configurations loaded from db/settings
     */
    protected array $configs = [];

    /**
     * @var bool Auto-discovery done
     */
    protected bool $discovered = false;

    /**
     * @var int Last discovery timestamp
     */
    protected int $lastDiscovery = 0;

    /**
     * @var int Discovery cache minutes
     */
    protected int $discoveryCacheMinutes = 10;

    /**
     * ProviderManager constructor.
     *
     * @param ServiceContainer $container
     */
    public function __construct(ServiceContainer $container)
    {
        $this->container = $container;

        $this->providerPaths = $this->getConfiguredProviderPaths();
        $this->discoveryCacheMinutes = $this->getDiscoveryCacheMinutes();
    }

    /**
     * Triggers provider auto-discovery (recursive scan of providers/*).
     *
     * @return void
     */
    public function discover(): void
    {
        // Use cache if already discovered recently
        if ($this->discovered && (time() - $this->lastDiscovery) < ($this->discoveryCacheMinutes * 60)) {
            return;
        }

        $providers = [];
        foreach ($this->providerPaths as $path) {
            if (is_dir($path)) {
                $files = $this->findPhpFilesRecursively($path);

                foreach ($files as $file) {
                    $fqcn = $this->classFromFile($file, $path);
                    if ($fqcn && $this->isProviderClass($fqcn)) {
                        $code = $this->providerCodeFromClass($fqcn);
                        $providers[$code] = $fqcn;
                    }
                }
            }
        }

        $this->providers = $providers;
        $this->discovered = true;
        $this->lastDiscovery = time();
    }

    /**
     * Returns all discovered provider codes => classes.
     *
     * @return array<string, string>
     */
    public function providers(): array
    {
        $this->discover();
        return $this->providers;
    }

    /**
     * Returns an array of provider setup schemas.
     * Keys: provider code, Values: setup schema
     *
     * @return array
     */
    public function getProviderSchemas(): array
    {
        $this->discover();
        $schemas = [];
        foreach ($this->providers as $code => $fqcn) {
            if (method_exists($fqcn, 'getSetupSchema')) {
                $schemas[$code] = $fqcn::getSetupSchema();
            }
        }
        return $schemas;
    }

    /**
     * Instantiates and returns a provider by code.
     *
     * @param string $code
     * @param array|null $config Optional custom configuration
     * @return ChannelProviderInterface
     * @throws ProviderException
     */
    public function get(string $code, ?array $config = null): ChannelProviderInterface
    {
        $this->discover();

        if (!isset($this->providers[$code])) {
            throw new ProviderException("Provider code {$code} not found", 'unknown', $code);
        }

        // Return already-instantiated instance if config is compatible
        if (isset($this->instances[$code]) && $config === null) {
            return $this->instances[$code];
        }

        $fqcn = $this->providers[$code];
        $provider = new $fqcn($config ?? $this->getConfig($code));
        $this->instances[$code] = $provider;
        return $provider;
    }

    /**
     * Registers a runtime configuration for a provider by code.
     *
     * @param string $code
     * @param array $config
     * @return void
     */
    public function setConfig(string $code, array $config): void
    {
        $this->configs[$code] = $config;
        // Reset instance if exists
        if (isset($this->instances[$code])) {
            unset($this->instances[$code]);
        }
        // TODO: Persist config changes to persistent store
    }

    /**
     * Returns the configuration for a given provider code.
     *
     * @param string $code
     * @return array
     */
    public function getConfig(string $code): array
    {
        return $this->configs[$code] ?? [];
    }

    /**
     * Returns all available provider codes.
     *
     * @return string[]
     */
    public function getCodes(): array
    {
        $this->discover();
        return array_keys($this->providers);
    }

    /**
     * Returns all providers for a given type (e.g., 'email', 'sms', 'ai')
     *
     * @param string $type
     * @return array<string, string> [code => fqcn]
     */
    public function getProvidersByType(string $type): array
    {
        $this->discover();
        $result = [];
        foreach ($this->providers as $code => $fqcn) {
            if (method_exists($fqcn, 'getType') && $fqcn::getType() === $type) {
                $result[$code] = $fqcn;
            }
        }
        return $result;
    }

    /**
     * Returns array of all supported provider types.
     *
     * @return string[]
     */
    public function getTypes(): array
    {
        $this->discover();
        $types = [];
        foreach ($this->providers as $fqcn) {
            if (method_exists($fqcn, 'getType')) {
                $types[] = $fqcn::getType();
            }
        }
        return array_unique($types);
    }

    /**
     * Checks if a provider code is available.
     *
     * @param string $code
     * @return bool
     */
    public function exists(string $code): bool
    {
        $this->discover();
        return isset($this->providers[$code]);
    }

    /**
     * Returns the canonical provider code from fully qualified class name.
     *
     * @param string $fqcn
     * @return string
     */
    protected function providerCodeFromClass(string $fqcn): string
    {
        // Convention: use the lowercase short class name, e.g. OpenAIProvider -> openai
        $reflect = new ReflectionClass($fqcn);
        $base = $reflect->getShortName();
        $base = preg_replace('/Provider$/i', '', $base);
        return strtolower($base);
    }

    /**
     * Checks if a class is a valid provider (implements ChannelProviderInterface and not abstract).
     *
     * @param string $fqcn
     * @return bool
     */
    protected function isProviderClass(string $fqcn): bool
    {
        return
            class_exists($fqcn)
            && is_subclass_of($fqcn, ChannelProviderInterface::class)
            && !(new ReflectionClass($fqcn))->isAbstract();
    }

    /**
     * Recursively finds all PHP files under a directory.
     *
     * @param string $path
     * @return array
     */
    protected function findPhpFilesRecursively(string $path): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getRealPath();
            }
        }
        return $files;
    }

    /**
     * Attempts to get the FQCN from PHP file path and base directory.
     *
     * @param string $file
     * @param string $basePath
     * @return string|false
     */
    protected function classFromFile(string $file, string $basePath)
    {
        // Build namespace from directory
        $relativePath = ltrim(str_replace($basePath, '', $file), DIRECTORY_SEPARATOR);
        $classPath = preg_replace('/\.php$/', '', $relativePath);
        $parts = explode(DIRECTORY_SEPARATOR, $classPath);

        // Compose FQCN (example: ai/OpenAIProvider.php -> Provider\Ai\OpenAIProvider)
        $namespaceParts = array_map(
            fn($p) => preg_replace('/[^A-Za-z0-9_]/', '', ucfirst($p)),
            $parts
        );

        // Base MAS namespace + "Provider"
        array_unshift($namespaceParts, 'Provider');
        array_unshift($namespaceParts, 'Mas');
        array_unshift($namespaceParts, 'Library');
        array_unshift($namespaceParts, 'Opencart');

        return implode('\\', $namespaceParts);
    }

    /**
     * Returns provider search paths from config.
     *
     * @return array
     */
    protected function getConfiguredProviderPaths(): array
    {
        $config = $this->container->get('config');
        $masConfig = $config->get('mas_config') ?: [];
        return $masConfig['provider_paths'] ?? [
            DIR_SYSTEM . 'library/mas/providers/',
        ];
    }

    /**
     * Returns provider auto-discovery cache minutes from config.
     *
     * @return int
     */
    protected function getDiscoveryCacheMinutes(): int
    {
        $config = $this->container->get('config');
        $masConfig = $config->get('mas_config') ?: [];
        return $masConfig['provider_cache_minutes'] ?? 10;
    }

    /**
     * Returns all provider instances (trigger lazy instantiation).
     *
     * @return array<string, ChannelProviderInterface>
     */
    public function all(): array
    {
        $this->discover();
        $out = [];
        foreach ($this->providers as $code => $fqcn) {
            $out[$code] = $this->get($code);
        }
        return $out;
    }

    /**
     * Resets the discovery and loaded instances (for testing or reload).
     *
     * @return void
     */
    public function reset(): void
    {
        $this->discovered = false;
        $this->instances = [];
        $this->providers = [];
        $this->lastDiscovery = 0;
    }
}
