<?php
namespace Opencart\System\Library\Mas\Core;

use Opencart\System\Engine\Registry;

/**
 * MAS - Marketing Automation Suite Core
 *
 * Main class of the suite, handles initialization, provider management, configuration, and communication with OpenCart.
 */
class MAS {
    /** @var Registry $registry */
    protected $registry;
    
    /** @var ProviderManager $providerManager */
    protected $providerManager;
    
    /** @var array $providers */
    protected $providers = [];
    
    /** @var array $config */
    protected $config = [];
    
    /**
     * Constructor.
     *
     * @param Registry $registry
     */
    public function __construct(Registry $registry) {
        $this->registry = $registry;
        $this->providerManager = new ProviderManager($registry);
    }
    
    /**
     * Initializes the suite.
     *
     * @return void
     */
    public function init() {
        // Load the suite configuration
        $this->loadConfig();
        
        // Initialize providers
        $this->providerManager->init();
    }
    
    /**
     * Loads the suite configuration.
     *
     * @return void
     */
    protected function loadConfig() {
        // Example: load from database or config file
        $this->config = $this->registry->get('config')->get('mas_config') ?? [];
    }
    
    /**
     * Returns the provider manager.
     *
     * @return ProviderManager
     */
    public function getProviderManager() {
        return $this->providerManager;
    }
    
    /**
     * Returns the suite configuration.
     *
     * @return array
     */
    public function getConfig() {
        return $this->config;
    }
    
    /**
     * Sets the suite configuration.
     *
     * @param array $config
     * @return void
     */
    public function setConfig(array $config) {
        $this->config = $config;
    }
    
    /**
     * Dispatches an event to the suite.
     *
     * @param string $event
     * @param array $data
     * @return void
     */
    public function dispatchEvent(string $event, array $data = []) {
        // Example: use OpenCart's event dispatcher or a custom one
        // $this->registry->get('event')->trigger('mas/' . $event, $data);
        // Note: if you want a custom dispatcher, you can implement it here or in a dedicated class
    }
    
    /**
     * Registers a provider.
     *
     * @param string $type
     * @param string $name
     * @param object $provider
     * @return void
     */
    public function registerProvider(string $type, string $name, object $provider) {
        $this->providerManager->registerProvider($type, $name, $provider);
    }
    
    /**
     * Returns a provider.
     *
     * @param string $type
     * @param string $name
     * @return object|null
     */
    public function getProvider(string $type, string $name) {
        return $this->providerManager->getProvider($type, $name);
    }
    
    /**
     * Returns all providers of a given type.
     *
     * @param string $type
     * @return array
     */
    public function getProvidersByType(string $type) {
        return $this->providerManager->getProvidersByType($type);
    }
}