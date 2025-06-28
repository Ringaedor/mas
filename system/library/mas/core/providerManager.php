<?php
namespace Opencart\System\Library\Mas\Core;

use Opencart\System\Engine\Registry;

/**
 * ProviderManager - Marketing automation suite provider manager.
 *
 * Handles registration, management and initialization of all providers.
 */
class ProviderManager {
    /** @var Registry $registry */
    protected $registry;
    
    /** @var array $providers */
    protected $providers = [];
    
    /**
     * Constructor.
     *
     * @param Registry $registry
     */
    public function __construct(Registry $registry) {
        $this->registry = $registry;
    }
    
    /**
     * Initializes all registered providers.
     *
     * @return void
     */
    public function init() {
        foreach ($this->providers as $type => $providers) {
            foreach ($providers as $name => $provider) {
                if (method_exists($provider, 'init')) {
                    $provider->init($this->registry);
                }
            }
        }
    }
    
    /**
     * Registers a new provider.
     *
     * @param string $type
     * @param string $name
     * @param object $provider
     * @return void
     */
    public function registerProvider(string $type, string $name, object $provider) {
        if (!isset($this->providers[$type])) {
            $this->providers[$type] = [];
        }
        $this->providers[$type][$name] = $provider;
    }
    
    /**
     * Returns a provider by type and name.
     *
     * @param string $type
     * @param string $name
     * @return object|null
     */
    public function getProvider(string $type, string $name) {
        return $this->providers[$type][$name] ?? null;
    }
    
    /**
     * Returns all providers of a given type.
     *
     * @param string $type
     * @return array
     */
    public function getProvidersByType(string $type) {
        return $this->providers[$type] ?? [];
    }
    
    /**
     * Returns all registered providers.
     *
     * @return array
     */
    public function getAllProviders() {
        return $this->providers;
    }
    
    /**
     * Loads providers from the provider directory.
     * Scans each provider type folder and registers all found provider classes.
     *
     * @return void
     */
    public function loadProviders() {
        // Path to the provider directory
        $providerDir = DIR_SYSTEM . 'library/mas/provider/';
        
        if (!is_dir($providerDir)) {
            return;
        }
        
        // Scan each provider type directory
        foreach (new \DirectoryIterator($providerDir) as $typeDir) {
            if ($typeDir->isDot() || !$typeDir->isDir()) {
                continue;
            }
            
            $type = $typeDir->getFilename();
            $typePath = $providerDir . $type . '/';
            
            // Scan each provider file in the type directory
            foreach (new \DirectoryIterator($typePath) as $file) {
                if ($file->isDot() || $file->isDir() || $file->getExtension() !== 'php') {
                    continue;
                }
                
                $providerName = $file->getBasename('.php');
                $providerClass = 'Opencart\\System\\Library\\Mas\\Provider\\' . $type . '\\' . $providerName;
                
                if (!class_exists($providerClass)) {
                    include_once($typePath . $file->getFilename());
                }
                
                if (class_exists($providerClass)) {
                    $provider = new $providerClass($this->registry);
                    $this->registerProvider($type, $providerName, $provider);
                }
            }
        }
    }
}