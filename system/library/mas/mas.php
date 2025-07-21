<?php
/**
 * MAS - Marketing Automation Suite
 * Core bootstrap and service locator for OpenCart 4.x
 *
 * This file is the main entry point for the MAS library.
 * It initializes the dependency injection container, registers core services,
 * and provides a unified access point for all MAS features.
 *
 * @copyright Copyright (c) 2025, Your Company
 * @license   Proprietary
 */

namespace Opencart\Library\Mas;

use Opencart\System\Engine\Registry;
use Opencart\System\Engine\Loader;
use Opencart\System\Engine\Config;
use Opencart\System\Engine\Log;
use Opencart\System\Library\Cache;
use Opencart\Library\Mas\ServiceContainer;
use Opencart\Library\Mas\Exception;

/**
 * Main MAS class responsible for bootstrapping the Marketing Automation Suite.
 */
class Mas
{
    /**
     * @var Registry OpenCart registry instance
     */
    protected $registry;
    
    /**
     * @var Loader OpenCart loader instance
     */
    protected $loader;
    
    /**
     * @var Config OpenCart config instance
     */
    protected $config;
    
    /**
     * @var Log OpenCart log instance
     */
    protected $log;
    
    /**
     * @var Cache OpenCart cache instance
     */
    protected $cache;
    
    /**
     * @var ServiceContainer Dependency injection container for MAS services
     */
    protected $container;
    
    /**
     * Constructor.
     *
     * @param Registry $registry OpenCart registry instance
     */
    public function __construct(Registry $registry)
    {
        $this->registry = $registry;
        $this->loader = $registry->get('load');
        $this->config = $registry->get('config');
        $this->log = $registry->get('log');
        $this->cache = $registry->get('cache');
        
        $this->boot();
    }
    
    /**
     * Bootstraps the MAS library.
     */
    protected function boot(): void
    {
        // Load MAS configuration
        $this->loader->config('mas/config');
        
        // Initialize the service container
        $this->container = new ServiceContainer();
        
        // Register core OpenCart services
        $this->container->set('registry', $this->registry);
        $this->container->set('loader', $this->loader);
        $this->container->set('config', $this->config);
        $this->container->set('log', $this->log);
        $this->container->set('cache', $this->cache);
        
        // Register MAS core managers and services
        $this->registerProviderManager();
        $this->registerWorkflowManager();
        $this->registerSegmentManager();
        $this->registerAIGateway();
        $this->registerEventDispatcher();
        $this->registerConsentManager();
        $this->registerDashboardService();
        $this->registerAuditLogger();
    }
    
    /**
     * Registers the ProviderManager service.
     */
    protected function registerProviderManager(): void
    {
        $this->container->set('mas.provider_manager', function () {
            return new \Opencart\Library\Mas\Provider\ProviderManager($this->container);
        });
    }
    
    /**
     * Registers the WorkflowManager service.
     */
    protected function registerWorkflowManager(): void
    {
        $this->container->set('mas.workflow_manager', function () {
            return new \Opencart\Library\Mas\Workflow\WorkflowManager($this->container);
        });
    }
    
    /**
     * Registers the SegmentManager service.
     */
    protected function registerSegmentManager(): void
    {
        $this->container->set('mas.segment_manager', function () {
            return new \Opencart\Library\Mas\Segmentation\SegmentManager($this->container);
        });
    }
    
    /**
     * Registers the AIGateway service.
     */
    protected function registerAIGateway(): void
    {
        $this->container->set('mas.ai_gateway', function () {
            return new \Opencart\Library\Mas\Service\Ai\AIGateway($this->container);
        });
    }
    
    /**
     * Registers the EventDispatcher service.
     */
    protected function registerEventDispatcher(): void
    {
        $this->container->set('mas.event_dispatcher', function () {
            return new \Opencart\Library\Mas\Event\EventDispatcher($this->container);
        });
    }
    
    /**
     * Registers the ConsentManager service.
     */
    protected function registerConsentManager(): void
    {
        $this->container->set('mas.consent_manager', function () {
            return new \Opencart\Library\Mas\Consent\ConsentManager($this->container);
        });
    }
    
    /**
     * Registers the DashboardService service.
     */
    protected function registerDashboardService(): void
    {
        $this->container->set('mas.dashboard_service', function () {
            return new \Opencart\Library\Mas\Reporting\DashboardService($this->container);
        });
    }
    
    /**
     * Registers the AuditLogger service.
     */
    protected function registerAuditLogger(): void
    {
        $this->container->set('mas.audit_logger', function () {
            return new \Opencart\Library\Mas\Audit\AuditLogger($this->container);
        });
    }
    
    /**
     * Returns the service container instance.
     *
     * @return ServiceContainer
     */
    public function getContainer(): ServiceContainer
    {
        return $this->container;
    }
    
    /**
     * Magic method to get services from the container.
     *
     * @param string $key Service identifier
     * @return mixed
     * @throws Exception If the service is not found
     */
    public function __get(string $key)
    {
        return $this->container->get($key);
    }
}