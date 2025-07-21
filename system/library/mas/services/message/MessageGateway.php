<?php
/**
 * MAS - Marketing Automation Suite
 * MessageGateway
 *
 * Centralized gateway for message-related requests (email, SMS, push notifications,
 * WhatsApp, etc.). Auto-discovers MAS message providers, adds uniform caching,
 * logging, fallback logic and transparent error handling.
 *
 * Features:
 * - Automatic provider discovery and lazy loading
 * - Intelligent fallback chains for different message types
 * - Template rendering and personalization
 * - Delivery tracking and analytics
 * - Queue management and batch sending
 * - Rate limiting and quota management
 * - Retry logic with exponential backoff
 * - Provider health monitoring
 *
 * @copyright Copyright (c) 2025, Your Company
 * @license   Proprietary
 */

namespace Opencart\Library\Mas\Services\Message;

use Opencart\Library\Mas\ServiceContainer;
use Opencart\Library\Mas\Exception\ProviderException;
use Opencart\Library\Mas\Exception\MessageGatewayException;
use Opencart\Library\Mas\Helper\ArrayHelper;
use Opencart\Library\Mas\Helper\DateHelper;
use Opencart\System\Engine\Registry;
use Opencart\System\Engine\Log;
use Opencart\System\Library\Cache;
use Opencart\System\Library\DB;

class MessageGateway
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
    protected string $defaultProvider = 'smtp';

    /**
     * @var bool Enable/disable response caching
     */
    protected bool $enableCache = true;

    /**
     * @var int Cache TTL in seconds
     */
    protected int $cacheTtl = 1800;

    /**
     * @var array Fallback chain per message type
     */
    protected array $fallbackOrder = [
        'email' => ['sendgrid', 'mailgun', 'smtp', 'mailhog'],
        'sms' => ['twilio', 'nexmo', 'messagebird'],
        'push' => ['onesignal', 'pusher', 'firebase'],
        'whatsapp' => ['twilio', 'whatsapp_business'],
        'slack' => ['slack', 'webhook'],
        'webhook' => ['http', 'guzzle'],
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
     * @var array Message queue
     */
    protected array $messageQueue = [];

    /**
     * @var array Configuration settings
     */
    protected array $config = [];

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
     * @var int Default batch size for bulk operations
     */
    protected int $defaultBatchSize = 100;

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
        $this->cacheTtl = $config['cache_ttl'] ?? 1800;
        $this->defaultProvider = $config['default_provider'] ?? 'smtp';
        $this->maxRetries = $config['max_retries'] ?? 3;
        $this->retryBackoffMultiplier = $config['retry_backoff_multiplier'] ?? 2.0;
        $this->circuitBreakerThreshold = $config['circuit_breaker_threshold'] ?? 5;
        $this->circuitBreakerTimeout = $config['circuit_breaker_timeout'] ?? 300;
        $this->defaultBatchSize = $config['default_batch_size'] ?? 100;

        $this->discoverProviders();
        $this->loadProviderHealth();
    }

    /**
     * Sends an email message.
     *
     * @param array $message
     * @param array $options
     * @return array
     */
    public function sendEmail(array $message, array $options = []): array
    {
        $payload = $this->buildEmailPayload($message, $options);
        return $this->dispatch('email', $payload, $options['provider'] ?? null);
    }

    /**
     * Sends an SMS message.
     *
     * @param array $message
     * @param array $options
     * @return array
     */
    public function sendSms(array $message, array $options = []): array
    {
        $payload = $this->buildSmsPayload($message, $options);
        return $this->dispatch('sms', $payload, $options['provider'] ?? null);
    }

    /**
     * Sends a push notification.
     *
     * @param array $message
     * @param array $options
     * @return array
     */
    public function sendPush(array $message, array $options = []): array
    {
        $payload = $this->buildPushPayload($message, $options);
        return $this->dispatch('push', $payload, $options['provider'] ?? null);
    }

    /**
     * Sends a WhatsApp message.
     *
     * @param array $message
     * @param array $options
     * @return array
     */
    public function sendWhatsApp(array $message, array $options = []): array
    {
        $payload = $this->buildWhatsAppPayload($message, $options);
        return $this->dispatch('whatsapp', $payload, $options['provider'] ?? null);
    }

    /**
     * Sends a Slack message.
     *
     * @param array $message
     * @param array $options
     * @return array
     */
    public function sendSlack(array $message, array $options = []): array
    {
        $payload = $this->buildSlackPayload($message, $options);
        return $this->dispatch('slack', $payload, $options['provider'] ?? null);
    }

    /**
     * Sends a webhook message.
     *
     * @param array $message
     * @param array $options
     * @return array
     */
    public function sendWebhook(array $message, array $options = []): array
    {
        $payload = $this->buildWebhookPayload($message, $options);
        return $this->dispatch('webhook', $payload, $options['provider'] ?? null);
    }

    /**
     * Queues multiple messages for batch sending.
     *
     * @param array $messages
     * @param string $type
     * @param array $options
     * @return array
     */
    public function queueMessages(array $messages, string $type, array $options = []): array
    {
        $queuedCount = 0;
        $failedCount = 0;
        $queueId = $this->generateQueueId();

        foreach ($messages as $message) {
            try {
                $payload = $this->buildPayloadForType($type, $message, $options);
                $this->addToQueue($queueId, $type, $payload, $options);
                $queuedCount++;
            } catch (\Exception $e) {
                $this->log->write('MAS MessageGateway: Failed to queue message - ' . $e->getMessage());
                $failedCount++;
            }
        }

        return [
            'success' => true,
            'queue_id' => $queueId,
            'queued_count' => $queuedCount,
            'failed_count' => $failedCount,
            'total_count' => count($messages),
        ];
    }

    /**
     * Processes queued messages.
     *
     * @param string $queueId
     * @param int $batchSize
     * @return array
     */
    public function processQueue(string $queueId, int $batchSize = null): array
    {
        $batchSize = $batchSize ?: $this->defaultBatchSize;
        
        $query = $this->db->query("
            SELECT * FROM `mas_message_queue`
            WHERE `queue_id` = '" . $this->db->escape($queueId) . "'
            AND `status` = 'pending'
            ORDER BY `priority` DESC, `created_at` ASC
            LIMIT {$batchSize}
        ");

        $processed = [];
        $successCount = 0;
        $failedCount = 0;

        foreach ($query->rows as $row) {
            try {
                $payload = json_decode($row['payload'], true);
                $options = json_decode($row['options'], true);

                $result = $this->dispatch($row['type'], $payload, $options['provider'] ?? null);

                $this->updateQueuedMessage($row['id'], 'sent', $result);
                $processed[] = $result;
                $successCount++;

            } catch (\Exception $e) {
                $this->updateQueuedMessage($row['id'], 'failed', ['error' => $e->getMessage()]);
                $failedCount++;
            }
        }

        return [
            'success' => true,
            'processed_count' => count($processed),
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'results' => $processed,
        ];
    }

    /**
     * Generic dispatch method for any message type.
     *
     * @param string $type
     * @param array $payload
     * @param string|null $providerCode
     * @return array
     * @throws MessageGatewayException
     */
    public function dispatch(string $type, array $payload, ?string $providerCode = null): array
    {
        $startTime = microtime(true);
        $messageId = $this->generateMessageId();
        
        try {
            // Select provider
            $providerCode = $providerCode ?: $this->selectProviderFor($type);
            
            // Check rate limits
            $this->checkRateLimit($providerCode);
            
            // Check circuit breaker
            $this->checkCircuitBreaker($providerCode);
            
            // Try cache first (for template rendering)
            $cacheKey = $this->buildCacheKey($providerCode, $type, $payload);
            if ($this->enableCache && $this->isCacheable($type, $payload)) {
                $result = $this->cache->get($cacheKey);
                if ($result) {
                    $this->logMessage($messageId, $type, $providerCode, $payload, $result, 'cache', microtime(true) - $startTime);
                    return $result;
                }
            }
            
            // Execute request with retry logic
            $result = $this->executeWithRetry($providerCode, $type, $payload, $messageId);
            
            // Cache successful results (if applicable)
            if ($this->enableCache && $result['success'] && $this->isCacheable($type, $payload)) {
                $this->cache->set($cacheKey, $result, $this->cacheTtl);
            }
            
            // Update performance metrics
            $this->updatePerformanceMetrics($providerCode, $type, microtime(true) - $startTime, $result['success']);
            
            // Log message
            $this->logMessage($messageId, $type, $providerCode, $payload, $result, 'api', microtime(true) - $startTime);
            
            // Track delivery
            $this->trackDelivery($messageId, $type, $providerCode, $payload, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->handleError($providerCode ?? 'unknown', $type, $e);
            
            // Try fallback providers
            if ($providerCode) {
                $fallbackResult = $this->tryFallbackProviders($type, $payload, $providerCode, $messageId);
                if ($fallbackResult) {
                    return $fallbackResult;
                }
            }
            
            throw new MessageGatewayException('Message Gateway request failed: ' . $e->getMessage(), 0, [], $e);
        }
    }

    /**
     * Executes request with retry logic.
     *
     * @param string $providerCode
     * @param string $type
     * @param array $payload
     * @param string $messageId
     * @return array
     */
    protected function executeWithRetry(string $providerCode, string $type, array $payload, string $messageId): array
    {
        $attempt = 0;
        $lastException = null;
        
        while ($attempt < $this->maxRetries) {
            $attempt++;
            
            try {
                $provider = $this->getProvider($providerCode);
                
                $result = $provider->send([
                    'type' => $type,
                    'message_id' => $messageId,
                    'attempt' => $attempt,
                ] + $payload);
                
                // Mark provider as healthy
                $this->markProviderHealthy($providerCode);
                
                return [
                    'success' => true,
                    'provider' => $providerCode,
                    'attempt' => $attempt,
                    'message_id' => $messageId,
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
                
                $this->log->write('MAS MessageGateway: Retrying message ' . $messageId . ' (attempt ' . $attempt . ')');
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
     * @param string $messageId
     * @return array|null
     */
    protected function tryFallbackProviders(string $type, array $payload, string $failedProvider, string $messageId): ?array
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
                $this->log->write('MAS MessageGateway: Trying fallback provider ' . $fallbackProvider . ' for message ' . $messageId);
                
                $result = $this->executeWithRetry($fallbackProvider, $type, $payload, $messageId);
                $result['fallback_from'] = $failedProvider;
                
                return $result;
                
            } catch (\Exception $e) {
                $this->log->write('MAS MessageGateway: Fallback provider ' . $fallbackProvider . ' failed: ' . $e->getMessage());
                continue;
            }
        }
        
        return null;
    }

    /**
     * Discovers available message providers.
     *
     * @return void
     */
    protected function discoverProviders(): void
    {
        $this->providers = [];
        
        try {
            $providerManager = $this->container->get('mas.provider_manager');
            
            foreach ($providerManager->list() as $code => $meta) {
                if (($meta['category'] ?? '') === 'message' || 
                    ($meta['type'] ?? '') === 'email' || 
                    ($meta['type'] ?? '') === 'sms' || 
                    ($meta['type'] ?? '') === 'push') {
                    $this->providers[$code] = $meta;
                }
            }
            
            $this->log->write('MAS MessageGateway: Discovered ' . count($this->providers) . ' message providers');
            
        } catch (\Exception $e) {
            $this->log->write('MAS MessageGateway: Error discovering providers - ' . $e->getMessage());
        }
    }

    /**
     * Gets provider instance.
     *
     * @param string $providerCode
     * @return object
     * @throws MessageGatewayException
     */
    protected function getProvider(string $providerCode): object
    {
        if (isset($this->providerInstances[$providerCode])) {
            return $this->providerInstances[$providerCode];
        }
        
        if (!isset($this->providers[$providerCode])) {
            throw new MessageGatewayException("Unknown message provider: {$providerCode}");
        }
        
        try {
            $provider = $this->container->get('mas.provider_manager')->get($providerCode);
            $this->providerInstances[$providerCode] = $provider;
            return $provider;
            
        } catch (\Exception $e) {
            throw new MessageGatewayException("Failed to load message provider {$providerCode}: " . $e->getMessage());
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
        
        throw new MessageGatewayException("No healthy message providers available for type: {$type}");
    }

    /**
     * Builds email payload.
     *
     * @param array $message
     * @param array $options
     * @return array
     */
    protected function buildEmailPayload(array $message, array $options = []): array
    {
        return [
            'to' => $message['to'] ?? [],
            'cc' => $message['cc'] ?? [],
            'bcc' => $message['bcc'] ?? [],
            'from' => $message['from'] ?? null,
            'reply_to' => $message['reply_to'] ?? null,
            'subject' => $message['subject'] ?? '',
            'html_body' => $message['html_body'] ?? '',
            'text_body' => $message['text_body'] ?? '',
            'attachments' => $message['attachments'] ?? [],
            'headers' => $message['headers'] ?? [],
            'template_id' => $message['template_id'] ?? null,
            'template_data' => $message['template_data'] ?? [],
        ];
    }

    /**
     * Builds SMS payload.
     *
     * @param array $message
     * @param array $options
     * @return array
     */
    protected function buildSmsPayload(array $message, array $options = []): array
    {
        return [
            'to' => $message['to'] ?? '',
            'from' => $message['from'] ?? null,
            'body' => $message['body'] ?? '',
            'media_urls' => $message['media_urls'] ?? [],
        ];
    }

    /**
     * Builds push notification payload.
     *
     * @param array $message
     * @param array $options
     * @return array
     */
    protected function buildPushPayload(array $message, array $options = []): array
    {
        return [
            'recipients' => $message['recipients'] ?? [],
            'title' => $message['title'] ?? '',
            'body' => $message['body'] ?? '',
            'icon' => $message['icon'] ?? null,
            'image' => $message['image'] ?? null,
            'url' => $message['url'] ?? null,
            'data' => $message['data'] ?? [],
            'buttons' => $message['buttons'] ?? [],
        ];
    }

    /**
     * Builds WhatsApp payload.
     *
     * @param array $message
     * @param array $options
     * @return array
     */
    protected function buildWhatsAppPayload(array $message, array $options = []): array
    {
        return [
            'to' => $message['to'] ?? '',
            'from' => $message['from'] ?? null,
            'type' => $message['type'] ?? 'text',
            'body' => $message['body'] ?? '',
            'media_url' => $message['media_url'] ?? null,
            'template_name' => $message['template_name'] ?? null,
            'template_language' => $message['template_language'] ?? 'en',
            'template_components' => $message['template_components'] ?? [],
        ];
    }

    /**
     * Builds Slack payload.
     *
     * @param array $message
     * @param array $options
     * @return array
     */
    protected function buildSlackPayload(array $message, array $options = []): array
    {
        return [
            'channel' => $message['channel'] ?? '',
            'text' => $message['text'] ?? '',
            'username' => $message['username'] ?? null,
            'icon_emoji' => $message['icon_emoji'] ?? null,
            'icon_url' => $message['icon_url'] ?? null,
            'attachments' => $message['attachments'] ?? [],
            'blocks' => $message['blocks'] ?? [],
        ];
    }

    /**
     * Builds webhook payload.
     *
     * @param array $message
     * @param array $options
     * @return array
     */
    protected function buildWebhookPayload(array $message, array $options = []): array
    {
        return [
            'url' => $message['url'] ?? '',
            'method' => $message['method'] ?? 'POST',
            'headers' => $message['headers'] ?? [],
            'body' => $message['body'] ?? [],
            'auth' => $message['auth'] ?? [],
            'timeout' => $message['timeout'] ?? 30,
        ];
    }

    /**
     * Builds payload for specific message type.
     *
     * @param string $type
     * @param array $message
     * @param array $options
     * @return array
     */
    protected function buildPayloadForType(string $type, array $message, array $options = []): array
    {
        return match ($type) {
            'email' => $this->buildEmailPayload($message, $options),
            'sms' => $this->buildSmsPayload($message, $options),
            'push' => $this->buildPushPayload($message, $options),
            'whatsapp' => $this->buildWhatsAppPayload($message, $options),
            'slack' => $this->buildSlackPayload($message, $options),
            'webhook' => $this->buildWebhookPayload($message, $options),
            default => $message,
        };
    }

    /**
     * Adds message to queue.
     *
     * @param string $queueId
     * @param string $type
     * @param array $payload
     * @param array $options
     * @return void
     */
    protected function addToQueue(string $queueId, string $type, array $payload, array $options): void
    {
        $this->db->query("
            INSERT INTO `mas_message_queue` SET
            `queue_id` = '" . $this->db->escape($queueId) . "',
            `type` = '" . $this->db->escape($type) . "',
            `payload` = '" . $this->db->escape(json_encode($payload)) . "',
            `options` = '" . $this->db->escape(json_encode($options)) . "',
            `priority` = '" . (int)($options['priority'] ?? 0) . "',
            `scheduled_at` = '" . $this->db->escape($options['scheduled_at'] ?? DateHelper::now()) . "',
            `status` = 'pending',
            `created_at` = NOW()
        ");
    }

    /**
     * Updates queued message status.
     *
     * @param int $messageId
     * @param string $status
     * @param array $result
     * @return void
     */
    protected function updateQueuedMessage(int $messageId, string $status, array $result): void
    {
        $this->db->query("
            UPDATE `mas_message_queue` SET
            `status` = '" . $this->db->escape($status) . "',
            `result` = '" . $this->db->escape(json_encode($result)) . "',
            `processed_at` = NOW()
            WHERE `id` = '" . (int)$messageId . "'
        ");
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
            'payload' => $this->getCacheablePayload($payload),
        ];
        
        return 'mas_message_' . md5(json_encode($cacheData));
    }

    /**
     * Gets cacheable version of payload.
     *
     * @param array $payload
     * @return array
     */
    protected function getCacheablePayload(array $payload): array
    {
        // Remove non-cacheable fields like timestamps, unique IDs, etc.
        $cacheable = $payload;
        unset($cacheable['message_id']);
        unset($cacheable['attempt']);
        unset($cacheable['timestamp']);
        
        return $cacheable;
    }

    /**
     * Checks if message type is cacheable.
     *
     * @param string $type
     * @param array $payload
     * @return bool
     */
    protected function isCacheable(string $type, array $payload): bool
    {
        // Only cache template rendering or validation results
        return isset($payload['template_id']) || isset($payload['validate_only']);
    }

    /**
     * Checks rate limit for provider.
     *
     * @param string $providerCode
     * @return void
     * @throws MessageGatewayException
     */
    protected function checkRateLimit(string $providerCode): void
    {
        $now = time();
        $windowSize = 60; // 1 minute window
        $maxMessages = 1000; // Default limit
        
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
        
        if ($counter['count'] >= $maxMessages) {
            throw new MessageGatewayException("Rate limit exceeded for provider: {$providerCode}");
        }
        
        $counter['count']++;
    }

    /**
     * Checks circuit breaker status.
     *
     * @param string $providerCode
     * @return void
     * @throws MessageGatewayException
     */
    protected function checkCircuitBreaker(string $providerCode): void
    {
        $health = $this->providerHealth[$providerCode] ?? null;
        
        if ($health && $health['status'] === 'circuit_open') {
            $timeSinceOpen = time() - $health['circuit_opened_at'];
            
            if ($timeSinceOpen < $this->circuitBreakerTimeout) {
                throw new MessageGatewayException("Circuit breaker open for provider: {$providerCode}");
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
        if (!isset($this->providers[$providerCode])) {
            return false;
        }
        
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
        $cached = $this->cache->get('mas_message_provider_health');
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
        $this->cache->set('mas_message_provider_health', $this->providerHealth, 3600);
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
        $this->log->write('MAS MessageGateway: Error with provider ' . $providerCode . ' for type ' . $type . ': ' . $exception->getMessage());
        
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
                'total_messages' => 0,
                'successful_messages' => 0,
                'failed_messages' => 0,
                'total_response_time' => 0,
                'last_updated' => time(),
            ];
        }
        
        $metrics = &$this->performanceMetrics[$key];
        $metrics['total_messages']++;
        $metrics['total_response_time'] += $responseTime;
        
        if ($success) {
            $metrics['successful_messages']++;
        } else {
            $metrics['failed_messages']++;
        }
        
        $metrics['last_updated'] = time();
        $metrics['avg_response_time'] = $metrics['total_response_time'] / $metrics['total_messages'];
        $metrics['success_rate'] = $metrics['successful_messages'] / $metrics['total_messages'];
    }

    /**
     * Logs message.
     *
     * @param string $messageId
     * @param string $type
     * @param string $providerCode
     * @param array $payload
     * @param array $result
     * @param string $source
     * @param float $responseTime
     * @return void
     */
    protected function logMessage(string $messageId, string $type, string $providerCode, array $payload, array $result, string $source, float $responseTime): void
    {
        $logData = [
            'message_id' => $messageId,
            'type' => $type,
            'provider' => $providerCode,
            'source' => $source,
            'success' => $result['success'] ?? false,
            'response_time' => $responseTime,
            'timestamp' => DateHelper::now(),
        ];
        
        $this->log->write('MAS MessageGateway: ' . json_encode($logData));
    }

    /**
     * Tracks message delivery.
     *
     * @param string $messageId
     * @param string $type
     * @param string $providerCode
     * @param array $payload
     * @param array $result
     * @return void
     */
    protected function trackDelivery(string $messageId, string $type, string $providerCode, array $payload, array $result): void
    {
        if (!$result['success']) {
            return;
        }

        $this->db->query("
            INSERT INTO `mas_message_analytics` SET
            `message_id` = '" . $this->db->escape($messageId) . "',
            `type` = '" . $this->db->escape($type) . "',
            `provider` = '" . $this->db->escape($providerCode) . "',
            `recipient` = '" . $this->db->escape($this->getRecipientFromPayload($payload)) . "',
            `status` = 'sent',
            `sent_at` = NOW()
        ");
    }

    /**
     * Gets recipient from payload.
     *
     * @param array $payload
     * @return string
     */
    protected function getRecipientFromPayload(array $payload): string
    {
        if (isset($payload['to'])) {
            if (is_array($payload['to'])) {
                return implode(', ', $payload['to']);
            }
            return $payload['to'];
        }
        
        if (isset($payload['recipients'])) {
            if (is_array($payload['recipients'])) {
                return implode(', ', $payload['recipients']);
            }
            return $payload['recipients'];
        }
        
        return 'unknown';
    }

    /**
     * Generates unique message ID.
     *
     * @return string
     */
    protected function generateMessageId(): string
    {
        return 'msg_' . uniqid() . '_' . time();
    }

    /**
     * Generates unique queue ID.
     *
     * @return string
     */
    protected function generateQueueId(): string
    {
        return 'queue_' . uniqid() . '_' . time();
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
        $this->cache->delete('mas_message_*');
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
        $totalMessages = array_sum(array_column($this->performanceMetrics, 'total_messages'));
        $successfulMessages = array_sum(array_column($this->performanceMetrics, 'successful_messages'));
        $failedMessages = array_sum(array_column($this->performanceMetrics, 'failed_messages'));

        return [
            'total_providers' => count($this->providers),
            'healthy_providers' => count(array_filter($this->providerHealth, function($health) {
                return $health['status'] === 'healthy';
            })),
            'total_messages' => $totalMessages,
            'successful_messages' => $successfulMessages,
            'failed_messages' => $failedMessages,
            'success_rate' => $totalMessages > 0 ? $successfulMessages / $totalMessages : 0,
            'avg_response_time' => $this->calculateOverallAverageResponseTime(),
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
        $totalMessages = array_sum(array_column($this->performanceMetrics, 'total_messages'));
        
        return $totalMessages > 0 ? $totalTime / $totalMessages : 0;
    }

    /**
     * Gets queue statistics.
     *
     * @return array
     */
    public function getQueueStatistics(): array
    {
        $query = $this->db->query("
            SELECT 
                `status`,
                COUNT(*) as count
            FROM `mas_message_queue`
            GROUP BY `status`
        ");

        $stats = [];
        foreach ($query->rows as $row) {
            $stats[$row['status']] = (int)$row['count'];
        }

        return $stats;
    }

    /**
     * Purges old queue entries.
     *
     * @param int $daysOld
     * @return int
     */
    public function purgeOldQueue(int $daysOld = 30): int
    {
        $cutoffDate = DateHelper::nowObject()->modify("-{$daysOld} days")->format('Y-m-d H:i:s');
        
        $this->db->query("
            DELETE FROM `mas_message_queue`
            WHERE `status` IN ('sent', 'failed')
            AND `processed_at` < '" . $this->db->escape($cutoffDate) . "'
        ");

        return $this->db->countAffected();
    }
}
