<?php
/**
 * MAS - Marketing Automation Suite
 * AiGateway
 *
 * Centralized gateway for AI-related requests (chat, completion, embeddings,
 * images, custom tasks). Auto-discovers MAS AI providers, adds uniform caching,
 * logging, fallback logic and transparent error handling.
 *
 * Features:
 * - Automatic provider discovery and lazy loading
 * - Intelligent fallback chains for different AI capabilities
 * - Response caching with configurable TTL
 * - Request/response logging and performance metrics
 * - Provider health monitoring and circuit breaker pattern
 * - Rate limiting and quota management
 * - Retry logic with exponential backoff
 *
 * @copyright Copyright (c) 2025, Your Company
 * @license   Proprietary
 */

namespace Opencart\Library\Mas\Services\Ai;

use Opencart\Library\Mas\ServiceContainer;
use Opencart\Library\Mas\Exception\ProviderException;
use Opencart\Library\Mas\Exception\AiGatewayException;
use Opencart\Library\Mas\Helper\ArrayHelper;
use Opencart\Library\Mas\Helper\DateHelper;
use Opencart\System\Engine\Registry;
use Opencart\System\Engine\Log;
use Opencart\System\Library\Cache;
use Opencart\System\Library\DB;

class AiGateway
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
     * @var Cache
     */
    protected Cache $cache;
    
    /**
     * @var DB
     */
    protected DB $db;
    
    /**
     * @var array<string,array> Runtime provider metadata
     */
    protected array $providers = [];
    
    /**
     * @var array<string,object> Cached provider instances
     */
    protected array $providerInstances = [];
    
    /**
     * @var string Default provider when none specified
     */
    protected string $defaultProvider = 'openai';
    
    /**
     * @var bool Enable/disable response caching
     */
    protected bool $enableCache = true;
    
    /**
     * @var int Cache TTL in seconds
     */
    protected int $cacheTtl = 3600;
    
    /**
     * @var array Fallback chain per feature type
     */
    protected array $fallbackOrder = [
        'chat' => ['openai', 'anthropic', 'gemini', 'local_ml'],
        'completion' => ['openai', 'anthropic', 'gemini', 'local_ml'],
        'embedding' => ['openai', 'gemini', 'local_ml'],
        'image' => ['openai', 'stable_diffusion', 'midjourney'],
        'analysis' => ['openai', 'anthropic', 'local_ml'],
        'prediction' => ['local_ml', 'openai', 'anthropic'],
        'clustering' => ['local_ml', 'openai'],
    ];
    
    /**
     * @var array Provider health status
     */
    protected array $providerHealth = [];
    
    /**
     * @var array Rate limiting counters
     */
    protected array $rateLimitCounters = [];
    
    /**
     * @var array Performance metrics
     */
    protected array $performanceMetrics = [];
    
    /**
     * @var array Configuration settings
     */
    protected array $config = [];
    
    /**
     * @var string AI providers directory path
     */
    protected string $aiProvidersPath = '';
    
    /**
     * @var int Maximum retry attempts
     */
    protected int $maxRetries = 3;
    
    /**
     * @var float Retry backoff multiplier
     */
    protected float $retryBackoffMultiplier = 2.0;
    
    /**
     * @var int Circuit breaker failure threshold
     */
    protected int $circuitBreakerThreshold = 5;
    
    /**
     * @var int Circuit breaker timeout in seconds
     */
    protected int $circuitBreakerTimeout = 300;
    
    /**
     * Constructor.
     *
     * @param ServiceContainer $container
     * @param array $config
     */
    public function __construct(ServiceContainer $container, array $config = [])
    {
        $this->container = $container;
        $this->registry = $container->get('registry');
        $this->log = $container->get('log');
        $this->cache = $container->get('cache');
        $this->db = $this->registry->get('db');
        
        $this->config = $config;
        $this->enableCache = $config['enable_cache'] ?? true;
        $this->cacheTtl = $config['cache_ttl'] ?? 3600;
        $this->defaultProvider = $config['default_provider'] ?? 'openai';
        $this->maxRetries = $config['max_retries'] ?? 3;
        $this->retryBackoffMultiplier = $config['retry_backoff_multiplier'] ?? 2.0;
        $this->circuitBreakerThreshold = $config['circuit_breaker_threshold'] ?? 5;
        $this->circuitBreakerTimeout = $config['circuit_breaker_timeout'] ?? 300;
        
        $this->aiProvidersPath = $config['ai_providers_path'] ?? DIR_SYSTEM . 'library/mas/ai/';
        
        $this->discoverProviders();
        $this->loadProviderHealth();
    }
    
    /**
     * Sends a chat request (LLM conversational interface).
     *
     * @param string $prompt
     * @param array $options
     * @return array
     */
    public function chat(string $prompt, array $options = []): array
    {
        return $this->dispatch('chat', ['prompt' => $prompt] + $options);
    }
    
    /**
     * Sends a completion request (classic text completion).
     *
     * @param string $prompt
     * @param array $options
     * @return array
     */
    public function completion(string $prompt, array $options = []): array
    {
        return $this->dispatch('completion', ['prompt' => $prompt] + $options);
    }
    
    /**
     * Generates embeddings from input text(s).
     *
     * @param string|array $input
     * @param array $options
     * @return array
     */
    public function embedding(string|array $input, array $options = []): array
    {
        return $this->dispatch('embedding', ['input' => $input] + $options);
    }
    
    /**
     * Generates or edits an image.
     *
     * @param string $prompt
     * @param array $options
     * @return array
     */
    public function image(string $prompt, array $options = []): array
    {
        return $this->dispatch('image', ['prompt' => $prompt] + $options);
    }
    
    /**
     * Performs AI-powered analysis.
     *
     * @param array $data
     * @param array $options
     * @return array
     */
    public function analysis(array $data, array $options = []): array
    {
        return $this->dispatch('analysis', ['data' => $data] + $options);
    }
    
    /**
     * Performs AI-powered prediction.
     *
     * @param array $features
     * @param array $options
     * @return array
     */
    public function prediction(array $features, array $options = []): array
    {
        return $this->dispatch('prediction', ['features' => $features] + $options);
    }
    
    /**
     * Performs clustering analysis.
     *
     * @param array $data
     * @param array $options
     * @return array
     */
    public function clustering(array $data, array $options = []): array
    {
        return $this->dispatch('clustering', ['data' => $data] + $options);
    }
    
    /**
     * Generic dispatch method for any AI request type.
     *
     * @param string $type
     * @param array $payload
     * @param string|null $providerCode
     * @return array
     * @throws AiGatewayException
     */
    public function dispatch(string $type, array $payload, ?string $providerCode = null): array
    {
        $startTime = microtime(true);
        $requestId = $this->generateRequestId();
        
        try {
            // Select provider
            $providerCode = $providerCode ?: $this->selectProviderFor($type);
            
            // Check rate limits
            $this->checkRateLimit($providerCode);
            
            // Check circuit breaker
            $this->checkCircuitBreaker($providerCode);
            
            // Try cache first
            $cacheKey = $this->buildCacheKey($providerCode, $type, $payload);
            if ($this->enableCache && $result = $this->cache->get($cacheKey)) {
                $this->logRequest($requestId, $type, $providerCode, $payload, $result, 'cache', microtime(true) - $startTime);
                return $result;
            }
            
            // Execute request with retry logic
            $result = $this->executeWithRetry($providerCode, $type, $payload, $requestId);
            
            // Cache successful results
            if ($this->enableCache && $result['success']) {
                $this->cache->set($cacheKey, $result, $this->cacheTtl);
            }
            
            // Update performance metrics
            $this->updatePerformanceMetrics($providerCode, $type, microtime(true) - $startTime, $result['success']);
            
            // Log request
            $this->logRequest($requestId, $type, $providerCode, $payload, $result, 'api', microtime(true) - $startTime);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->handleError($providerCode ?? 'unknown', $type, $e);
            
            // Try fallback providers
            if ($providerCode) {
                $fallbackResult = $this->tryFallbackProviders($type, $payload, $providerCode, $requestId);
                if ($fallbackResult) {
                    return $fallbackResult;
                }
            }
            
            throw new AiGatewayException('AI Gateway request failed: ' . $e->getMessage(), 0, [], $e);
        }
    }
    
    /**
     * Executes request with retry logic.
     *
     * @param string $providerCode
     * @param string $type
     * @param array $payload
     * @param string $requestId
     * @return array
     */
    protected function executeWithRetry(string $providerCode, string $type, array $payload, string $requestId): array
    {
        $attempt = 0;
        $lastException = null;
        
        while ($attempt < $this->maxRetries) {
            $attempt++;
            
            try {
                $provider = $this->getProvider($providerCode);
                
                $result = $provider->send([
                    'type' => $type,
                    'request_id' => $requestId,
                    'attempt' => $attempt,
                ] + $payload);
                
                // Mark provider as healthy
                $this->markProviderHealthy($providerCode);
                
                return [
                    'success' => true,
                    'provider' => $providerCode,
                    'attempt' => $attempt,
                    'request_id' => $requestId,
                    'output' => $result['output'] ?? null,
                    'meta' => $result['meta'] ?? [],
                ];
                
            } catch (ProviderException $e) {
                $lastException = $e;
                
                // Check if we should retry
                if (!$this->shouldRetry($e, $attempt)) {
                    break;
                }
                
                // Wait before retry with exponential backoff
                $waitTime = pow($this->retryBackoffMultiplier, $attempt - 1);
                usleep($waitTime * 1000000); // Convert to microseconds
                
                $this->log->write('MAS AiGateway: Retrying request ' . $requestId . ' (attempt ' . $attempt . ')');
            }
        }
        
        // Mark provider as unhealthy
        $this->markProviderUnhealthy($providerCode);
        
        throw $lastException ?: new ProviderException('Max retries exceeded');
    }
    
    /**
     * Tries fallback providers for failed requests.
     *
     * @param string $type
     * @param array $payload
     * @param string $failedProvider
     * @param string $requestId
     * @return array|null
     */
    protected function tryFallbackProviders(string $type, array $payload, string $failedProvider, string $requestId): ?array
    {
        $fallbackProviders = $this->fallbackOrder[$type] ?? [];
        
        foreach ($fallbackProviders as $fallbackProvider) {
            if ($fallbackProvider === $failedProvider) {
                continue;
            }
            
            if (!$this->isProviderHealthy($fallbackProvider)) {
                continue;
            }
            
            try {
                $this->log->write('MAS AiGateway: Trying fallback provider ' . $fallbackProvider . ' for request ' . $requestId);
                
                $result = $this->executeWithRetry($fallbackProvider, $type, $payload, $requestId);
                $result['fallback_from'] = $failedProvider;
                
                return $result;
                
            } catch (\Exception $e) {
                $this->log->write('MAS AiGateway: Fallback provider ' . $fallbackProvider . ' failed: ' . $e->getMessage());
                continue;
            }
        }
        
        return null;
    }
    
    /**
     * Discovers available AI providers.
     *
     * @return void
     */
    protected function discoverProviders(): void
    {
        $this->providers = [];
        
        if (!is_dir($this->aiProvidersPath)) {
            $this->log->write('MAS AiGateway: AI providers directory not found: ' . $this->aiProvidersPath);
            return;
        }
        
        try {
            $providerFiles = $this->scanProvidersDirectory($this->aiProvidersPath);
            
            foreach ($providerFiles as $file) {
                $providerInfo = $this->analyzeProviderFile($file);
                if ($providerInfo) {
                    $this->providers[$providerInfo['code']] = $providerInfo;
                }
            }
            
            $this->log->write('MAS AiGateway: Discovered ' . count($this->providers) . ' AI providers');
            
        } catch (\Exception $e) {
            $this->log->write('MAS AiGateway: Error discovering providers - ' . $e->getMessage());
        }
    }
    
    /**
     * Scans providers directory for PHP files.
     *
     * @param string $directory
     * @return array
     */
    protected function scanProvidersDirectory(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
        
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        
        return $files;
    }
    
    /**
     * Analyzes provider file to extract metadata.
     *
     * @param string $filePath
     * @return array|null
     */
    protected function analyzeProviderFile(string $filePath): ?array
    {
        try {
            $content = file_get_contents($filePath);
            if (!$content) {
                return null;
            }
            
            // Extract basic information
            $namespace = $this->extractNamespace($content);
            $className = $this->extractClassName($content);
            
            if (!$namespace || !$className) {
                return null;
            }
            
            // Check if it's a provider class
            if (!$this->isProviderClass($content)) {
                return null;
            }
            
            $providerCode = $this->extractProviderCode($content);
            if (!$providerCode) {
                $providerCode = $this->generateProviderCodeFromFilename($filePath);
            }
            
            return [
                'code' => $providerCode,
                'class' => $namespace . '\\' . $className,
                'file' => $filePath,
                'namespace' => $namespace,
                'className' => $className,
                'capabilities' => $this->extractCapabilities($content),
                'priority' => $this->extractPriority($content),
                'discovered_at' => DateHelper::now(),
            ];
            
        } catch (\Exception $e) {
            $this->log->write('MAS AiGateway: Error analyzing provider file ' . $filePath . ' - ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Extracts namespace from PHP content.
     *
     * @param string $content
     * @return string|null
     */
    protected function extractNamespace(string $content): ?string
    {
        if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }
    
    /**
     * Extracts class name from PHP content.
     *
     * @param string $content
     * @return string|null
     */
    protected function extractClassName(string $content): ?string
    {
        if (preg_match('/class\s+(\w+)/', $content, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }
    
    /**
     * Checks if the class is a provider class.
     *
     * @param string $content
     * @return bool
     */
    protected function isProviderClass(string $content): bool
    {
        return strpos($content, 'implements') !== false &&
        (strpos($content, 'ProviderInterface') !== false ||
            strpos($content, 'AbstractProvider') !== false ||
            strpos($content, 'extends AbstractProvider') !== false);
    }
    
    /**
     * Extracts provider code from content.
     *
     * @param string $content
     * @return string|null
     */
    protected function extractProviderCode(string $content): ?string
    {
        // Look for getCode() method
        if (preg_match('/public\s+static\s+function\s+getCode\(\)\s*:\s*string\s*\{[^}]*return\s+[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
            return trim($matches[1]);
        }
        
        // Look for getType() method
        if (preg_match('/public\s+static\s+function\s+getType\(\)\s*:\s*string\s*\{[^}]*return\s+[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
            return trim($matches[1]);
        }
        
        return null;
    }
    
    /**
     * Generates provider code from filename.
     *
     * @param string $filePath
     * @return string
     */
    protected function generateProviderCodeFromFilename(string $filePath): string
    {
        $filename = pathinfo($filePath, PATHINFO_FILENAME);
        return strtolower(str_replace(['Provider', 'provider'], '', $filename));
    }
    
    /**
     * Extracts capabilities from content.
     *
     * @param string $content
     * @return array
     */
    protected function extractCapabilities(string $content): array
    {
        $capabilities = [];
        
        // Look for capability indicators
        $capabilityMap = [
            'chat' => ['chat', 'conversation', 'dialog'],
            'completion' => ['completion', 'complete', 'generate'],
            'embedding' => ['embedding', 'embed', 'vector'],
            'image' => ['image', 'picture', 'visual'],
            'analysis' => ['analysis', 'analyze', 'insight'],
            'prediction' => ['prediction', 'predict', 'forecast'],
            'clustering' => ['clustering', 'cluster', 'group'],
        ];
        
        foreach ($capabilityMap as $capability => $keywords) {
            foreach ($keywords as $keyword) {
                if (stripos($content, $keyword) !== false) {
                    $capabilities[] = $capability;
                    break;
                }
            }
        }
        
        return array_unique($capabilities);
    }
    
    /**
     * Extracts priority from content.
     *
     * @param string $content
     * @return int
     */
    protected function extractPriority(string $content): int
    {
        if (preg_match('/priority\s*=\s*(\d+)/', $content, $matches)) {
            return (int)$matches[1];
        }
        return 0;
    }
    
    /**
     * Gets provider instance.
     *
     * @param string $providerCode
     * @return object
     * @throws AiGatewayException
     */
    protected function getProvider(string $providerCode): object
    {
        if (isset($this->providerInstances[$providerCode])) {
            return $this->providerInstances[$providerCode];
        }
        
        if (!isset($this->providers[$providerCode])) {
            throw new AiGatewayException("Unknown AI provider: {$providerCode}");
        }
        
        try {
            $provider = $this->container->get('mas.provider_manager')->get($providerCode);
            $this->providerInstances[$providerCode] = $provider;
            return $provider;
            
        } catch (\Exception $e) {
            throw new AiGatewayException("Failed to load AI provider {$providerCode}: " . $e->getMessage());
        }
    }
    
    /**
     * Selects the best provider for a given type.
     *
     * @param string $type
     * @return string
     */
    protected function selectProviderFor(string $type): string
    {
        $candidates = $this->fallbackOrder[$type] ?? [];
        
        foreach ($candidates as $providerCode) {
            if (isset($this->providers[$providerCode]) && $this->isProviderHealthy($providerCode)) {
                return $providerCode;
            }
        }
        
        // Fallback to default provider
        if (isset($this->providers[$this->defaultProvider]) && $this->isProviderHealthy($this->defaultProvider)) {
            return $this->defaultProvider;
        }
        
        // Return first available provider
        foreach ($this->providers as $providerCode => $provider) {
            if ($this->isProviderHealthy($providerCode)) {
                return $providerCode;
            }
        }
        
        throw new AiGatewayException("No healthy AI providers available for type: {$type}");
    }
    
    /**
     * Builds cache key for request.
     *
     * @param string $providerCode
     * @param string $type
     * @param array $payload
     * @return string
     */
    protected function buildCacheKey(string $providerCode, string $type, array $payload): string
    {
        $cacheData = [
            'provider' => $providerCode,
            'type' => $type,
            'payload' => $payload,
        ];
        
        return 'mas_ai_' . md5(json_encode($cacheData));
    }
    
    /**
     * Checks rate limit for provider.
     *
     * @param string $providerCode
     * @return void
     * @throws AiGatewayException
     */
    protected function checkRateLimit(string $providerCode): void
    {
        $now = time();
        $windowSize = 60; // 1 minute window
        $maxRequests = 100; // Default limit
        
        $key = 'rate_limit_' . $providerCode;
        
        if (!isset($this->rateLimitCounters[$key])) {
            $this->rateLimitCounters[$key] = ['count' => 0, 'window_start' => $now];
        }
        
        $counter = &$this->rateLimitCounters[$key];
        
        // Reset counter if window has passed
        if ($now - $counter['window_start'] >= $windowSize) {
            $counter['count'] = 0;
            $counter['window_start'] = $now;
        }
        
        if ($counter['count'] >= $maxRequests) {
            throw new AiGatewayException("Rate limit exceeded for provider: {$providerCode}");
        }
        
        $counter['count']++;
    }
    
    /**
     * Checks circuit breaker status.
     *
     * @param string $providerCode
     * @return void
     * @throws AiGatewayException
     */
    protected function checkCircuitBreaker(string $providerCode): void
    {
        $health = $this->providerHealth[$providerCode] ?? null;
        
        if ($health && $health['status'] === 'circuit_open') {
            $timeSinceOpen = time() - $health['circuit_opened_at'];
            
            if ($timeSinceOpen < $this->circuitBreakerTimeout) {
                throw new AiGatewayException("Circuit breaker open for provider: {$providerCode}");
            }
            
            // Reset to half-open state
            $this->providerHealth[$providerCode]['status'] = 'half_open';
        }
    }
    
    /**
     * Checks if provider is healthy.
     *
     * @param string $providerCode
     * @return bool
     */
    protected function isProviderHealthy(string $providerCode): bool
    {
        $health = $this->providerHealth[$providerCode] ?? null;
        return !$health || $health['status'] === 'healthy' || $health['status'] === 'half_open';
    }
    
    /**
     * Marks provider as healthy.
     *
     * @param string $providerCode
     * @return void
     */
    protected function markProviderHealthy(string $providerCode): void
    {
        $this->providerHealth[$providerCode] = [
            'status' => 'healthy',
            'failure_count' => 0,
            'last_success' => time(),
        ];
        
        $this->saveProviderHealth();
    }
    
    /**
     * Marks provider as unhealthy.
     *
     * @param string $providerCode
     * @return void
     */
    protected function markProviderUnhealthy(string $providerCode): void
    {
        if (!isset($this->providerHealth[$providerCode])) {
            $this->providerHealth[$providerCode] = ['failure_count' => 0];
        }
        
        $this->providerHealth[$providerCode]['failure_count']++;
        $this->providerHealth[$providerCode]['last_failure'] = time();
        
        if ($this->providerHealth[$providerCode]['failure_count'] >= $this->circuitBreakerThreshold) {
            $this->providerHealth[$providerCode]['status'] = 'circuit_open';
            $this->providerHealth[$providerCode]['circuit_opened_at'] = time();
        }
        
        $this->saveProviderHealth();
    }
    
    /**
     * Loads provider health from storage.
     *
     * @return void
     */
    protected function loadProviderHealth(): void
    {
        $cached = $this->cache->get('mas_ai_provider_health');
        if ($cached) {
            $this->providerHealth = $cached;
        }
    }
    
    /**
     * Saves provider health to storage.
     *
     * @return void
     */
    protected function saveProviderHealth(): void
    {
        $this->cache->set('mas_ai_provider_health', $this->providerHealth, 3600);
    }
    
    /**
     * Determines if request should be retried.
     *
     * @param \Exception $exception
     * @param int $attempt
     * @return bool
     */
    protected function shouldRetry(\Exception $exception, int $attempt): bool
    {
        if ($attempt >= $this->maxRetries) {
            return false;
        }
        
        // Don't retry on authentication errors
        if (strpos($exception->getMessage(), 'auth') !== false) {
            return false;
        }
        
        // Don't retry on quota exceeded errors
        if (strpos($exception->getMessage(), 'quota') !== false) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Handles errors and updates metrics.
     *
     * @param string $providerCode
     * @param string $type
     * @param \Exception $exception
     * @return void
     */
    protected function handleError(string $providerCode, string $type, \Exception $exception): void
    {
        $this->log->write('MAS AiGateway: Error with provider ' . $providerCode . ' for type ' . $type . ': ' . $exception->getMessage());
        
        // Update performance metrics
        $this->updatePerformanceMetrics($providerCode, $type, 0, false);
    }
    
    /**
     * Updates performance metrics.
     *
     * @param string $providerCode
     * @param string $type
     * @param float $responseTime
     * @param bool $success
     * @return void
     */
    protected function updatePerformanceMetrics(string $providerCode, string $type, float $responseTime, bool $success): void
    {
        $key = $providerCode . '_' . $type;
        
        if (!isset($this->performanceMetrics[$key])) {
            $this->performanceMetrics[$key] = [
                'total_requests' => 0,
                'successful_requests' => 0,
                'failed_requests' => 0,
                'total_response_time' => 0,
                'last_updated' => time(),
            ];
        }
        
        $metrics = &$this->performanceMetrics[$key];
        $metrics['total_requests']++;
        $metrics['total_response_time'] += $responseTime;
        
        if ($success) {
            $metrics['successful_requests']++;
        } else {
            $metrics['failed_requests']++;
        }
        
        $metrics['last_updated'] = time();
        $metrics['avg_response_time'] = $metrics['total_response_time'] / $metrics['total_requests'];
        $metrics['success_rate'] = $metrics['successful_requests'] / $metrics['total_requests'];
    }
    
    /**
     * Logs AI request.
     *
     * @param string $requestId
     * @param string $type
     * @param string $providerCode
     * @param array $payload
     * @param array $result
     * @param string $source
     * @param float $responseTime
     * @return void
     */
    protected function logRequest(string $requestId, string $type, string $providerCode, array $payload, array $result, string $source, float $responseTime): void
    {
        $logData = [
            'request_id' => $requestId,
            'type' => $type,
            'provider' => $providerCode,
            'source' => $source,
            'success' => $result['success'] ?? false,
            'response_time' => $responseTime,
            'timestamp' => DateHelper::now(),
        ];
        
        $this->log->write('MAS AiGateway: ' . json_encode($logData));
    }
    
    /**
     * Generates unique request ID.
     *
     * @return string
     */
    protected function generateRequestId(): string
    {
        return 'ai_' . uniqid() . '_' . time();
    }
    
    /**
     * Gets available providers.
     *
     * @return array
     */
    public function getAvailableProviders(): array
    {
        return $this->providers;
    }
    
    /**
     * Gets provider health status.
     *
     * @return array
     */
    public function getProviderHealth(): array
    {
        return $this->providerHealth;
    }
    
    /**
     * Gets performance metrics.
     *
     * @return array
     */
    public function getPerformanceMetrics(): array
    {
        return $this->performanceMetrics;
    }
    
    /**
     * Sets default provider.
     *
     * @param string $providerCode
     * @return void
     */
    public function setDefaultProvider(string $providerCode): void
    {
        $this->defaultProvider = $providerCode;
    }
    
    /**
     * Sets fallback order for a type.
     *
     * @param string $type
     * @param array $providers
     * @return void
     */
    public function setFallbackOrder(string $type, array $providers): void
    {
        $this->fallbackOrder[$type] = $providers;
    }
    
    /**
     * Enables/disables caching.
     *
     * @param bool $enabled
     * @return void
     */
    public function setCacheEnabled(bool $enabled): void
    {
        $this->enableCache = $enabled;
    }
    
    /**
     * Sets cache TTL.
     *
     * @param int $ttl
     * @return void
     */
    public function setCacheTtl(int $ttl): void
    {
        $this->cacheTtl = $ttl;
    }
    
    /**
     * Clears cache.
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->cache->delete('mas_ai_*');
    }
    
    /**
     * Reloads providers.
     *
     * @return void
     */
    public function reloadProviders(): void
    {
        $this->providers = [];
        $this->providerInstances = [];
        $this->discoverProviders();
    }
    
    /**
     * Gets gateway statistics.
     *
     * @return array
     */
    public function getStatistics(): array
    {
        return [
            'total_providers' => count($this->providers),
            'healthy_providers' => count(array_filter($this->providerHealth, function($health) {
            return $health['status'] === 'healthy';
            })),
            'total_requests' => array_sum(array_column($this->performanceMetrics, 'total_requests')),
            'successful_requests' => array_sum(array_column($this->performanceMetrics, 'successful_requests')),
            'failed_requests' => array_sum(array_column($this->performanceMetrics, 'failed_requests')),
            'avg_response_time' => $this->calculateOverallAverageResponseTime(),
            'overall_success_rate' => $this->calculateOverallSuccessRate(),
            ];
    }
    
    /**
     * Calculates overall average response time.
     *
     * @return float
     */
    protected function calculateOverallAverageResponseTime(): float
    {
        $totalTime = array_sum(array_column($this->performanceMetrics, 'total_response_time'));
        $totalRequests = array_sum(array_column($this->performanceMetrics, 'total_requests'));
        
        return $totalRequests > 0 ? $totalTime / $totalRequests : 0;
    }
    
    /**
     * Calculates overall success rate.
     *
     * @return float
     */
    protected function calculateOverallSuccessRate(): float
    {
        $totalSuccessful = array_sum(array_column($this->performanceMetrics, 'successful_requests'));
        $totalRequests = array_sum(array_column($this->performanceMetrics, 'total_requests'));
        
        return $totalRequests > 0 ? $totalSuccessful / $totalRequests : 0;
    }
}
