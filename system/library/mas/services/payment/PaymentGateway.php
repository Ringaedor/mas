<?php
/**
 * MAS - Marketing Automation Suite
 * PaymentGateway
 *
 * Centralized gateway for payment-related operations (authorize, capture, refund,
 * void, subscription management). Auto-discovers MAS payment providers,
 * adds uniform logging, fallback logic, retry with backoff, rate‐limit protection,
 * circuit‐breaker health monitoring and transaction analytics.
 *
 * Path: system/library/mas/services/payment/PaymentGateway.php
 *
 * © 2025 Your Company – Proprietary
 */

namespace Opencart\Library\Mas\Services\Payment;

use Opencart\Library\Mas\ServiceContainer;
use Opencart\Library\Mas\Exception\ProviderException;
use Opencart\Library\Mas\Exception\PaymentGatewayException;
use Opencart\Library\Mas\Helper\ArrayHelper;
use Opencart\Library\Mas\Helper\DateHelper;
use Opencart\System\Engine\Log;
use Opencart\System\Library\Cache;
use Opencart\System\Library\DB;

class PaymentGateway
{
    protected ServiceContainer $container;
    protected Log $log;
    protected Cache $cache;
    protected DB $db;
    
    /** @var array<string,array> Discovered provider metadata */
    protected array $providers = [];
    /** @var array<string,object> Cached provider instances */
    protected array $instances = [];
    
    protected string $defaultProvider   = 'stripe';
    protected int    $maxRetries        = 2;
    protected float  $backoffMultiplier = 2.0;
    protected int    $circuitThreshold  = 3;
    protected int    $circuitTimeout    = 300; // seconds
    protected int    $rateLimitWindow   = 60;  // seconds
    protected int    $rateLimitMax      = 100; // per window
    
    protected array $providerHealth = [];
    protected array $rateCounters   = [];
    protected array $performance    = [];
    
    public function __construct(ServiceContainer $container, array $config = [])
    {
        $this->container = $container;
        $this->log       = $container->get('log');
        $this->cache     = $container->get('cache');
        $this->db        = $container->get('db');
        
        $this->defaultProvider   = $config['default_provider']   ?? $this->defaultProvider;
        $this->maxRetries        = $config['max_retries']        ?? $this->maxRetries;
        $this->backoffMultiplier = $config['backoff_multiplier'] ?? $this->backoffMultiplier;
        $this->circuitThreshold  = $config['circuit_threshold']  ?? $this->circuitThreshold;
        $this->circuitTimeout    = $config['circuit_timeout']    ?? $this->circuitTimeout;
        $this->rateLimitWindow   = $config['rate_limit_window']  ?? $this->rateLimitWindow;
        $this->rateLimitMax      = $config['rate_limit_max']     ?? $this->rateLimitMax;
        
        $this->discoverProviders();
        $this->loadHealth();
    }
    
    // --- High-level payment actions ---
    
    public function authorize(array $data, ?string $provider = null): array
    {
        return $this->dispatch('authorize', $data, $provider);
    }
    
    public function capture(array $data, ?string $provider = null): array
    {
        return $this->dispatch('capture', $data, $provider);
    }
    
    public function refund(array $data, ?string $provider = null): array
    {
        return $this->dispatch('refund', $data, $provider);
    }
    
    public function void(array $data, ?string $provider = null): array
    {
        return $this->dispatch('void', $data, $provider);
    }
    
    public function subscribe(array $data, ?string $provider = null): array
    {
        return $this->dispatch('subscribe', $data, $provider);
    }
    
    public function cancelSubscription(array $data, ?string $provider = null): array
    {
        return $this->dispatch('cancel_subscription', $data, $provider);
    }
    
    // --- Core dispatch method ---
    
    protected function dispatch(string $action, array $payload, ?string $providerCode = null): array
    {
        $start = microtime(true);
        $providerCode = $providerCode ?: $this->selectProvider($action);
        
        try {
            $this->checkRateLimit($providerCode);
            $this->checkCircuit($providerCode);
            
            $instance = $this->getProviderInstance($providerCode);
            $attempt  = 0;
            $lastEx   = null;
            
            while ($attempt < $this->maxRetries) {
                $attempt++;
                try {
                    $response = $instance->execute($action, $payload);
                    $this->markHealthy($providerCode);
                    $this->logPerformance($providerCode, $action, microtime(true) - $start, true);
                    return [
                        'success'  => true,
                        'provider' => $providerCode,
                        'action'   => $action,
                        'response' => $response,
                        'time_ms'  => round((microtime(true) - $start) * 1000),
                    ];
                } catch (ProviderException $e) {
                    $lastEx = $e;
                    if ($attempt >= $this->maxRetries) {
                        break;
                    }
                    sleep((int)pow($this->backoffMultiplier, $attempt - 1));
                }
            }
            
            $this->markUnhealthy($providerCode);
            throw $lastEx ?: new ProviderException('Max retries exceeded');
            
        } catch (\Exception $e) {
            $this->logPerformance($providerCode, $action, microtime(true) - $start, false);
            $fallback = $this->nextProvider($action, $providerCode);
            if ($fallback) {
                return $this->dispatch($action, $payload, $fallback);
            }
            throw new PaymentGatewayException("Payment action '{$action}' failed: " . $e->getMessage(), 0, [], $e);
        }
    }
    
    // --- Provider discovery & selection ---
    
    protected function discoverProviders(): void
    {
        $pm = $this->container->get('mas.provider_manager');
        foreach ($pm->list() as $code => $meta) {
            if (($meta['category'] ?? '') === 'payment') {
                $this->providers[$code] = $meta;
            }
        }
        $this->log->write('PaymentGateway: discovered ' . count($this->providers) . ' providers');
    }
    
    protected function getProviderInstance(string $code)
    {
        if (!isset($this->instances[$code])) {
            $this->instances[$code] = $this->container->get('mas.provider_manager')->get($code);
        }
        return $this->instances[$code];
    }
    
    protected function selectProvider(string $action): string
    {
        $order = $this->fallbackOrder()[$action] ?? [$this->defaultProvider];
        foreach ($order as $code) {
            if (isset($this->providers[$code]) && $this->isHealthy($code)) {
                return $code;
            }
        }
        return $this->defaultProvider;
    }
    
    protected function nextProvider(string $action, string $failed): ?string
    {
        $order = $this->fallbackOrder()[$action] ?? [];
        $i = array_search($failed, $order, true);
        return ($i !== false && isset($order[$i + 1])) ? $order[$i + 1] : null;
    }
    
    protected function fallbackOrder(): array
    {
        return [
            'authorize'          => ['stripe','paypal','authorizenet'],
            'capture'            => ['stripe','paypal'],
            'refund'             => ['stripe','paypal'],
            'void'               => ['stripe','authorizenet'],
            'subscribe'          => ['stripe','paypal'],
            'cancel_subscription'=> ['stripe','paypal'],
        ];
    }
    
    // --- Rate limiting & circuit breaker ---
    
    protected function checkRateLimit(string $code): void
    {
        $now = time();
        $key = "rate_{$code}";
        if (!isset($this->rateCounters[$key])) {
            $this->rateCounters[$key] = ['count'=>0,'start'=>$now];
        }
        $c = &$this->rateCounters[$key];
        if ($now - $c['start'] >= $this->rateLimitWindow) {
            $c = ['count'=>0,'start'=>$now];
        }
        if (++$c['count'] > $this->rateLimitMax) {
            throw new PaymentGatewayException("Rate limit exceeded for provider {$code}");
        }
    }
    
    protected function checkCircuit(string $code): void
    {
        $h = $this->providerHealth[$code] ?? null;
        if (($h['status'] ?? '') === 'open' && time() - ($h['opened_at'] ?? 0) < $this->circuitTimeout) {
            throw new PaymentGatewayException("Circuit breaker open for provider {$code}");
        }
        if (($h['status'] ?? '') === 'open') {
            $this->providerHealth[$code]['status'] = 'half_open';
        }
    }
    
    // --- Health management ---
    
    protected function isHealthy(string $code): bool
    {
        $h = $this->providerHealth[$code] ?? null;
        return !$h || in_array($h['status'], ['healthy','half_open'], true);
    }
    
    protected function markHealthy(string $code): void
    {
        $this->providerHealth[$code] = [
            'status' => 'healthy',
            'fails'  => 0,
            'last_success' => time(),
        ];
        $this->saveHealth();
    }
    
    protected function markUnhealthy(string $code): void
    {
        $h = $this->providerHealth[$code] ?? ['fails'=>0];
        $h['fails'] = ($h['fails'] ?? 0) + 1;
        $h['last_failure'] = time();
        if ($h['fails'] >= $this->circuitThreshold) {
            $h['status']     = 'open';
            $h['opened_at']  = time();
        }
        $this->providerHealth[$code] = $h;
        $this->saveHealth();
    }
    
    protected function loadHealth(): void
    {
        $h = $this->cache->get('mas_payment_health');
        if ($h) {
            $this->providerHealth = $h;
        }
    }
    
    protected function saveHealth(): void
    {
        $this->cache->set('mas_payment_health', $this->providerHealth, $this->cacheTtl);
    }
    
    // --- Performance metrics ---
    
    protected function logPerformance(string $code, string $action, float $time, bool $success): void
    {
        $key = "{$code}_{$action}";
        $m = &$this->performance[$key];
        if (!isset($m)) {
            $m = ['total'=>0,'success'=>0,'fail'=>0,'time'=>0.0];
        }
        $m['total']++;
        $m['time']  += $time;
        $success ? $m['success']++ : $m['fail']++;
        $m['avg_time']    = $m['time'] / $m['total'];
        $m['success_rate']= $m['success'] / $m['total'];
    }
    
    // --- Public introspection ---
    
    public function listProviders(): array
    {
        return $this->providers;
    }
    
    public function getHealth(): array
    {
        return $this->providerHealth;
    }
    
    public function getPerformance(): array
    {
        return $this->performance;
    }
}
