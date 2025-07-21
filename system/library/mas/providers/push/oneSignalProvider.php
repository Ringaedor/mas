<?php
/**
 * MAS - Marketing Automation Suite
 * OneSignal Push Provider
 *
 * Push notification provider for OneSignal service supporting web push, mobile push,
 * in-app messages, and email notifications. Provides comprehensive targeting options,
 * rich media support, and delivery tracking with webhook handling.
 *
 * Dependencies:
 *   - guzzlehttp/guzzle ^7.0
 *   - onesignal/onesignal-php-api ^2.0 (optional, for enhanced features)
 *
 * NOTE: Install dependencies via composer:
 *   composer require guzzlehttp/guzzle
 *   composer require onesignal/onesignal-php-api
 *
 * @copyright Copyright (c) 2025, Your Company
 * @license   Proprietary
 */

namespace Opencart\Library\Mas\Provider\Push;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Opencart\Library\Mas\Provider\AbstractProvider;
use Opencart\Library\Mas\Exception\ProviderException;
use Opencart\Library\Mas\Helper\ArrayHelper;

class OneSignalProvider extends AbstractProvider
{
    /**
     * @var string Provider version
     */
    public const VERSION = '1.0.0';

    /**
     * @var string OneSignal API base URL
     */
    private const BASE_URL = 'https://onesignal.com/api/v1';

    /**
     * @var Client|null HTTP client instance
     */
    protected ?Client $client = null;

    /**
     * @var array Last send result metadata
     */
    protected array $lastSendResult = [];

    /**
     * @var array Supported device types
     */
    protected array $deviceTypes = [
        'ios' => 0,
        'android' => 1,
        'amazon' => 2,
        'windows_phone' => 3,
        'chrome_app' => 4,
        'chrome_web' => 5,
        'windows_phone_mpns' => 6,
        'firefox' => 7,
        'safari' => 8,
        'edge' => 9,
        'mac_os' => 10,
        'alexa' => 11,
        'email' => 12,
        'sms' => 13,
        'huawei' => 14,
    ];

    /**
     * Returns the unique provider name.
     *
     * @return string
     */
    public static function getName(): string
    {
        return 'OneSignal Push';
    }

    /**
     * Returns a short human-readable description.
     *
     * @return string
     */
    public static function getDescription(): string
    {
        return 'OneSignal push notification service with multi-platform support and advanced targeting';
    }

    /**
     * Returns the provider type.
     *
     * @return string
     */
    public static function getType(): string
    {
        return self::TYPE_PUSH;
    }

    /**
     * Returns provider capabilities.
     *
     * @return string[]
     */
    public static function getCapabilities(): array
    {
        return [
            'web_push',
            'mobile_push',
            'in_app_messages',
            'email_notifications',
            'sms_notifications',
            'rich_media',
            'action_buttons',
            'deep_linking',
            'scheduled_delivery',
            'delayed_delivery',
            'timezone_delivery',
            'a_b_testing',
            'segmentation',
            'player_targeting',
            'geofencing',
            'delivery_tracking',
            'click_tracking',
            'conversion_tracking',
            'webhook_callbacks',
            'template_support',
            'multi_language',
            'custom_data',
            'badge_count',
            'sound_customization',
            'priority_control',
            'throttling',
            'analytics_integration'
        ];
    }

    /**
     * Returns the full setup schema definition.
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
                'description' => static::getDescription(),
            ],
            'schema' => [
                'app_id' => [
                    'type' => 'string',
                    'required' => true,
                    'label' => 'App ID',
                    'description' => 'Your OneSignal App ID from the dashboard',
                    'placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
                    'validation' => ['pattern' => '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/'],
                ],
                'rest_api_key' => [
                    'type' => 'password',
                    'required' => true,
                    'label' => 'REST API Key',
                    'description' => 'Your OneSignal REST API Key for backend operations',
                ],
                'user_auth_key' => [
                    'type' => 'password',
                    'required' => false,
                    'label' => 'User Auth Key',
                    'description' => 'Required for app management operations (optional)',
                ],
                'default_url' => [
                    'type' => 'url',
                    'required' => false,
                    'label' => 'Default URL',
                    'description' => 'Default landing page for notifications',
                ],
                'default_icon' => [
                    'type' => 'url',
                    'required' => false,
                    'label' => 'Default Icon URL',
                    'description' => 'Default notification icon (256x256px recommended)',
                ],
                'chrome_web_icon' => [
                    'type' => 'url',
                    'required' => false,
                    'label' => 'Chrome Web Icon',
                    'description' => 'Chrome web push icon (192x192px)',
                ],
                'chrome_web_image' => [
                    'type' => 'url',
                    'required' => false,
                    'label' => 'Chrome Web Image',
                    'description' => 'Chrome web push large image',
                ],
                'firefox_icon' => [
                    'type' => 'url',
                    'required' => false,
                    'label' => 'Firefox Icon',
                    'description' => 'Firefox notification icon',
                ],
                'safari_icon' => [
                    'type' => 'url',
                    'required' => false,
                    'label' => 'Safari Icon',
                    'description' => 'Safari notification icon',
                ],
                'timeout' => [
                    'type' => 'integer',
                    'required' => false,
                    'default' => 30,
                    'min' => 5,
                    'max' => 120,
                    'label' => 'Timeout (seconds)',
                    'description' => 'HTTP request timeout in seconds',
                ],
                'enable_webhooks' => [
                    'type' => 'boolean',
                    'required' => false,
                    'default' => false,
                    'label' => 'Enable Webhooks',
                    'description' => 'Enable webhook callbacks for delivery tracking',
                ],
                'webhook_url' => [
                    'type' => 'url',
                    'required' => false,
                    'label' => 'Webhook URL',
                    'description' => 'URL to receive webhook notifications',
                ],
                'batch_size' => [
                    'type' => 'integer',
                    'required' => false,
                    'default' => 2000,
                    'min' => 1,
                    'max' => 10000,
                    'label' => 'Batch Size',
                    'description' => 'Maximum recipients per batch request',
                ],
                'rate_limit_per_minute' => [
                    'type' => 'integer',
                    'required' => false,
                    'default' => 30,
                    'min' => 1,
                    'max' => 3000,
                    'label' => 'Rate Limit (per minute)',
                    'description' => 'Maximum API requests per minute',
                ],
                'enable_analytics' => [
                    'type' => 'boolean',
                    'required' => false,
                    'default' => true,
                    'label' => 'Enable Analytics',
                    'description' => 'Enable detailed analytics tracking',
                ],
                'enable_a_b_testing' => [
                    'type' => 'boolean',
                    'required' => false,
                    'default' => false,
                    'label' => 'Enable A/B Testing',
                    'description' => 'Enable A/B testing for notifications',
                ],
                'default_timezone' => [
                    'type' => 'select',
                    'required' => false,
                    'default' => 'UTC',
                    'options' => [
                        'UTC' => 'UTC',
                        'America/New_York' => 'Eastern Time',
                        'America/Chicago' => 'Central Time',
                        'America/Denver' => 'Mountain Time',
                        'America/Los_Angeles' => 'Pacific Time',
                        'Europe/London' => 'GMT',
                        'Europe/Paris' => 'Central European Time',
                        'Asia/Tokyo' => 'Japan Standard Time',
                        'Asia/Shanghai' => 'China Standard Time',
                        'Australia/Sydney' => 'Australian Eastern Time',
                    ],
                    'label' => 'Default Timezone',
                    'description' => 'Default timezone for scheduled notifications',
                ],
            ],
            'capabilities' => static::getCapabilities(),
        ];
    }

    /**
     * Sends a push notification using OneSignal.
     *
     * @param array $payload Notification payload
     * @return array
     * @throws ProviderException
     */
    public function send(array $payload): array
    {
        if (!$this->isAuthenticated()) {
            throw new ProviderException('OneSignal provider is not authenticated', 'push', 'onesignal');
        }

        try {
            $this->initializeClient();

            // Prepare notification data
            $notificationData = $this->prepareNotificationData($payload);

            // Send the notification
            $response = $this->client->post('/notifications', [
                'json' => $notificationData
            ]);

            $responseBody = json_decode($response->getBody()->getContents(), true);

            if (isset($responseBody['errors']) && !empty($responseBody['errors'])) {
                throw new ProviderException('OneSignal API error: ' . implode(', ', $responseBody['errors']), 'push', 'onesignal');
            }

            $this->lastSendResult = [
                'success' => true,
                'notification_id' => $responseBody['id'] ?? null,
                'recipients' => $responseBody['recipients'] ?? 0,
                'external_id' => $responseBody['external_id'] ?? null,
                'timestamp' => date('Y-m-d H:i:s'),
            ];

            return [
                'success' => true,
                'message_id' => $responseBody['id'] ?? null,
                'meta' => [
                    'provider' => 'onesignal',
                    'recipients' => $responseBody['recipients'] ?? 0,
                    'external_id' => $responseBody['external_id'] ?? null,
                    'timestamp' => $this->lastSendResult['timestamp'],
                    'platform_delivery_stats' => $responseBody['platform_delivery_stats'] ?? null,
                ],
            ];

        } catch (GuzzleException $e) {
            $this->handleGuzzleException($e);
        } catch (\Exception $e) {
            throw new ProviderException('Unexpected error: ' . $e->getMessage(), 'push', 'onesignal', 0, [], $e);
        }
    }

    /**
     * Authenticates the OneSignal provider.
     *
     * @param array $config
     * @return bool
     */
    public function authenticate(array $config): bool
    {
        $this->setConfig($config);

        // Validate required configuration
        $required = ['app_id', 'rest_api_key'];
        foreach ($required as $field) {
            if (empty($config[$field])) {
                $this->lastError = "Missing required field: {$field}";
                return false;
            }
        }

        // Validate app_id format (UUID)
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $config['app_id'])) {
            $this->lastError = 'Invalid App ID format (must be UUID)';
            return false;
        }

        // Validate webhook URL if provided
        if (!empty($config['webhook_url']) && !filter_var($config['webhook_url'], FILTER_VALIDATE_URL)) {
            $this->lastError = 'Invalid webhook URL format';
            return false;
        }

        // Validate icon URLs if provided
        $iconFields = ['default_icon', 'chrome_web_icon', 'chrome_web_image', 'firefox_icon', 'safari_icon'];
        foreach ($iconFields as $field) {
            if (!empty($config[$field]) && !filter_var($config[$field], FILTER_VALIDATE_URL)) {
                $this->lastError = "Invalid {$field} URL format";
                return false;
            }
        }

        $this->authenticated = true;
        $this->lastError = null;

        return true;
    }

    /**
     * Tests the OneSignal connection.
     *
     * @return bool
     */
    public function testConnection(): bool
    {
        if (!$this->isAuthenticated()) {
            $this->lastError = 'Provider not authenticated';
            return false;
        }

        try {
            $this->initializeClient();

            // Test connection by getting app info
            $response = $this->client->get('/apps/' . $this->config['app_id']);
            $responseBody = json_decode($response->getBody()->getContents(), true);

            if (isset($responseBody['errors'])) {
                $this->lastError = 'OneSignal API error: ' . implode(', ', $responseBody['errors']);
                return false;
            }

            return true;

        } catch (GuzzleException $e) {
            $this->lastError = 'OneSignal connection test failed: ' . $e->getMessage();
            return false;
        } catch (\Exception $e) {
            $this->lastError = 'Connection test failed: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Cancels a scheduled notification.
     *
     * @param string $notificationId
     * @return array
     * @throws ProviderException
     */
    public function cancelNotification(string $notificationId): array
    {
        if (!$this->isAuthenticated()) {
            throw new ProviderException('OneSignal provider is not authenticated', 'push', 'onesignal');
        }

        try {
            $this->initializeClient();

            $response = $this->client->delete('/notifications/' . $notificationId . '?app_id=' . $this->config['app_id']);
            $responseBody = json_decode($response->getBody()->getContents(), true);

            if (isset($responseBody['errors'])) {
                throw new ProviderException('OneSignal API error: ' . implode(', ', $responseBody['errors']), 'push', 'onesignal');
            }

            return [
                'success' => true,
                'message' => 'Notification cancelled successfully',
                'notification_id' => $notificationId,
            ];

        } catch (GuzzleException $e) {
            $this->handleGuzzleException($e);
        }
    }

    /**
     * Gets notification details.
     *
     * @param string $notificationId
     * @return array|null
     */
    public function getNotification(string $notificationId): ?array
    {
        if (!$this->isAuthenticated()) {
            return null;
        }

        try {
            $this->initializeClient();

            $response = $this->client->get('/notifications/' . $notificationId . '?app_id=' . $this->config['app_id']);
            $responseBody = json_decode($response->getBody()->getContents(), true);

            if (isset($responseBody['errors'])) {
                return null;
            }

            return [
                'id' => $responseBody['id'],
                'headings' => $responseBody['headings'] ?? null,
                'contents' => $responseBody['contents'] ?? null,
                'url' => $responseBody['url'] ?? null,
                'web_url' => $responseBody['web_url'] ?? null,
                'app_url' => $responseBody['app_url'] ?? null,
                'data' => $responseBody['data'] ?? null,
                'platform_delivery_stats' => $responseBody['platform_delivery_stats'] ?? null,
                'successful' => $responseBody['successful'] ?? 0,
                'failed' => $responseBody['failed'] ?? 0,
                'errored' => $responseBody['errored'] ?? 0,
                'converted' => $responseBody['converted'] ?? 0,
                'remaining' => $responseBody['remaining'] ?? 0,
                'queued_at' => $responseBody['queued_at'] ?? null,
                'send_after' => $responseBody['send_after'] ?? null,
                'canceled' => $responseBody['canceled'] ?? false,
                'completed_at' => $responseBody['completed_at'] ?? null,
            ];

        } catch (GuzzleException $e) {
            return null;
        }
    }

    /**
     * Gets list of notifications.
     *
     * @param array $options
     * @return array
     */
    public function getNotifications(array $options = []): array
    {
        if (!$this->isAuthenticated()) {
            return [];
        }

        try {
            $this->initializeClient();

            $queryParams = [
                'app_id' => $this->config['app_id'],
                'limit' => $options['limit'] ?? 50,
                'offset' => $options['offset'] ?? 0,
            ];

            if (!empty($options['kind'])) {
                $queryParams['kind'] = $options['kind'];
            }

            $response = $this->client->get('/notifications?' . http_build_query($queryParams));
            $responseBody = json_decode($response->getBody()->getContents(), true);

            if (isset($responseBody['errors'])) {
                return [];
            }

            return $responseBody['notifications'] ?? [];

        } catch (GuzzleException $e) {
            return [];
        }
    }

    /**
     * Creates a device/player subscription.
     *
     * @param array $deviceData
     * @return array
     * @throws ProviderException
     */
    public function createDevice(array $deviceData): array
    {
        if (!$this->isAuthenticated()) {
            throw new ProviderException('OneSignal provider is not authenticated', 'push', 'onesignal');
        }

        try {
            $this->initializeClient();

            $playerData = array_merge([
                'app_id' => $this->config['app_id'],
            ], $deviceData);

            $response = $this->client->post('/players', [
                'json' => $playerData
            ]);

            $responseBody = json_decode($response->getBody()->getContents(), true);

            if (isset($responseBody['errors'])) {
                throw new ProviderException('OneSignal API error: ' . implode(', ', $responseBody['errors']), 'push', 'onesignal');
            }

            return [
                'success' => true,
                'player_id' => $responseBody['id'] ?? null,
                'message' => 'Device created successfully',
            ];

        } catch (GuzzleException $e) {
            $this->handleGuzzleException($e);
        }
    }

    /**
     * Processes a webhook from OneSignal.
     *
     * @param array $payload
     * @return array
     */
    public function processWebhook(array $payload): array
    {
        return [
            'notification_id' => $payload['id'] ?? '',
            'event' => $payload['event'] ?? '',
            'successful' => $payload['successful'] ?? 0,
            'failed' => $payload['failed'] ?? 0,
            'converted' => $payload['converted'] ?? 0,
            'remaining' => $payload['remaining'] ?? 0,
            'queued_at' => $payload['queued_at'] ?? null,
            'send_after' => $payload['send_after'] ?? null,
            'completed_at' => $payload['completed_at'] ?? null,
            'url' => $payload['url'] ?? null,
            'web_url' => $payload['web_url'] ?? null,
            'app_url' => $payload['app_url'] ?? null,
            'headings' => $payload['headings'] ?? null,
            'contents' => $payload['contents'] ?? null,
            'data' => $payload['data'] ?? null,
            'platform_delivery_stats' => $payload['platform_delivery_stats'] ?? null,
        ];
    }

    /**
     * Initializes the HTTP client.
     *
     * @return void
     */
    protected function initializeClient(): void
    {
        if ($this->client === null) {
            $timeout = (int)ArrayHelper::get($this->config, 'timeout', 30);

            $this->client = new Client([
                'base_uri' => self::BASE_URL,
                'timeout' => $timeout,
                'headers' => [
                    'Authorization' => 'Basic ' . $this->config['rest_api_key'],
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
            ]);
        }
    }

    /**
     * Prepares notification data for OneSignal API.
     *
     * @param array $payload
     * @return array
     * @throws ProviderException
     */
    protected function prepareNotificationData(array $payload): array
    {
        $data = [
            'app_id' => $this->config['app_id'],
        ];

        // Set content
        if (!empty($payload['contents'])) {
            $data['contents'] = $payload['contents'];
        } elseif (!empty($payload['message'])) {
            $data['contents'] = ['en' => $payload['message']];
        } else {
            throw new ProviderException('No message content specified', 'push', 'onesignal');
        }

        // Set headings
        if (!empty($payload['headings'])) {
            $data['headings'] = $payload['headings'];
        } elseif (!empty($payload['title'])) {
            $data['headings'] = ['en' => $payload['title']];
        }

        // Set targeting
        if (!empty($payload['include_player_ids'])) {
            $data['include_player_ids'] = $payload['include_player_ids'];
        } elseif (!empty($payload['include_external_user_ids'])) {
            $data['include_external_user_ids'] = $payload['include_external_user_ids'];
        } elseif (!empty($payload['included_segments'])) {
            $data['included_segments'] = $payload['included_segments'];
        } else {
            $data['included_segments'] = ['Subscribed Users'];
        }

        // Set URLs
        if (!empty($payload['url'])) {
            $data['url'] = $payload['url'];
        } elseif (!empty($this->config['default_url'])) {
            $data['url'] = $this->config['default_url'];
        }

        if (!empty($payload['web_url'])) {
            $data['web_url'] = $payload['web_url'];
        }

        if (!empty($payload['app_url'])) {
            $data['app_url'] = $payload['app_url'];
        }

        // Set icons and images
        if (!empty($payload['large_icon'])) {
            $data['large_icon'] = $payload['large_icon'];
        } elseif (!empty($this->config['default_icon'])) {
            $data['large_icon'] = $this->config['default_icon'];
        }

        if (!empty($payload['big_picture'])) {
            $data['big_picture'] = $payload['big_picture'];
        }

        if (!empty($payload['chrome_web_icon'])) {
            $data['chrome_web_icon'] = $payload['chrome_web_icon'];
        } elseif (!empty($this->config['chrome_web_icon'])) {
            $data['chrome_web_icon'] = $this->config['chrome_web_icon'];
        }

        if (!empty($payload['chrome_web_image'])) {
            $data['chrome_web_image'] = $payload['chrome_web_image'];
        } elseif (!empty($this->config['chrome_web_image'])) {
            $data['chrome_web_image'] = $this->config['chrome_web_image'];
        }

        // Set custom data
        if (!empty($payload['data'])) {
            $data['data'] = $payload['data'];
        }

        // Set scheduling
        if (!empty($payload['send_after'])) {
            $data['send_after'] = $payload['send_after'];
        }

        if (!empty($payload['delayed_option'])) {
            $data['delayed_option'] = $payload['delayed_option'];
        }

        if (!empty($payload['delivery_time_of_day'])) {
            $data['delivery_time_of_day'] = $payload['delivery_time_of_day'];
        }

        // Set priority and other options
        if (isset($payload['priority'])) {
            $data['priority'] = $payload['priority'];
        }

        if (isset($payload['ttl'])) {
            $data['ttl'] = $payload['ttl'];
        }

        if (!empty($payload['collapse_id'])) {
            $data['collapse_id'] = $payload['collapse_id'];
        }

        // Set action buttons
        if (!empty($payload['buttons'])) {
            $data['buttons'] = $payload['buttons'];
        }

        // Set web action buttons
        if (!empty($payload['web_buttons'])) {
            $data['web_buttons'] = $payload['web_buttons'];
        }

        // Set filters
        if (!empty($payload['filters'])) {
            $data['filters'] = $payload['filters'];
        }

        // Set external_id for tracking
        if (!empty($payload['external_id'])) {
            $data['external_id'] = $payload['external_id'];
        }

        // Set template
        if (!empty($payload['template_id'])) {
            $data['template_id'] = $payload['template_id'];
        }

        // Set A/B testing
        if (!empty($payload['contents_b'])) {
            $data['contents_b'] = $payload['contents_b'];
        }

        if (!empty($payload['headings_b'])) {
            $data['headings_b'] = $payload['headings_b'];
        }

        return $data;
    }

    /**
     * Handles Guzzle exceptions and converts them to ProviderException.
     *
     * @param GuzzleException $e
     * @throws ProviderException
     */
    protected function handleGuzzleException(GuzzleException $e): void
    {
        $message = $e->getMessage();
        $code = $e->getCode();

        if ($e->hasResponse()) {
            $response = $e->getResponse();
            $body = json_decode($response->getBody()->getContents(), true);
            
            if (isset($body['errors'])) {
                $message = 'OneSignal API error: ' . implode(', ', $body['errors']);
            }
        }

        throw new ProviderException(
            $message,
            'push',
            'onesignal',
            $code,
            ['original_message' => $e->getMessage()],
            $e
        );
    }

    /**
     * Gets the last send result.
     *
     * @return array
     */
    public function getLastSendResult(): array
    {
        return $this->lastSendResult;
    }

    /**
     * Returns sanitised configuration (masking sensitive data).
     *
     * @param array|null $fieldsToMask
     * @return array
     */
    public function getSanitisedConfig(array $fieldsToMask = null): array
    {
        $mask = $fieldsToMask ?: ['rest_api_key', 'user_auth_key'];
        return parent::getSanitisedConfig($mask);
    }
}
