<?php
/**
 * MAS - Marketing Automation Suite
 * Twilio SMS Provider
 *
 * SMS provider for Twilio service supporting text messages, MMS, status callbacks,
 * delivery tracking, and webhook validation. Provides comprehensive error handling
 * and support for bulk messaging with rate limiting.
 *
 * Dependencies:
 *   - twilio/sdk ^8.0
 *
 * NOTE: Install Twilio SDK via composer:
 *   composer require twilio/sdk
 *
 * @copyright Copyright (c) 2025, Your Company
 * @license   Proprietary
 */

namespace Opencart\Library\Mas\Provider\Sms;

use Twilio\Rest\Client;
use Twilio\Exceptions\TwilioException;
use Twilio\Security\RequestValidator;
use Opencart\Library\Mas\Provider\AbstractProvider;
use Opencart\Library\Mas\Exception\ProviderException;
use Opencart\Library\Mas\Helper\ArrayHelper;

class TwilioProvider extends AbstractProvider
{
    /**
     * @var string Provider version
     */
    public const VERSION = '1.0.0';

    /**
     * @var string Twilio API base URL
     */
    private const BASE_URL = 'https://api.twilio.com';

    /**
     * @var Client|null Twilio client instance
     */
    protected ?Client $client = null;

    /**
     * @var array Last send result metadata
     */
    protected array $lastSendResult = [];

    /**
     * @var RequestValidator|null Webhook validator instance
     */
    protected ?RequestValidator $validator = null;

    /**
     * Returns the unique provider name.
     *
     * @return string
     */
    public static function getName(): string
    {
        return 'Twilio SMS';
    }

    /**
     * Returns a short human-readable description.
     *
     * @return string
     */
    public static function getDescription(): string
    {
        return 'Twilio SMS service provider with global reach and delivery tracking';
    }

    /**
     * Returns the provider type.
     *
     * @return string
     */
    public static function getType(): string
    {
        return self::TYPE_SMS;
    }

    /**
     * Returns provider capabilities.
     *
     * @return string[]
     */
    public static function getCapabilities(): array
    {
        return [
            'send_sms',
            'send_mms',
            'delivery_tracking',
            'status_callbacks',
            'webhook_validation',
            'bulk_messaging',
            'message_scheduling',
            'alphanumeric_sender',
            'shortcode_support',
            'global_reach',
            'two_way_messaging'
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
                'account_sid' => [
                    'type' => 'string',
                    'required' => true,
                    'label' => 'Account SID',
                    'description' => 'Your Twilio Account SID from console.twilio.com',
                    'placeholder' => 'AC...',
                ],
                'auth_token' => [
                    'type' => 'password',
                    'required' => true,
                    'label' => 'Auth Token',
                    'description' => 'Your Twilio Auth Token from console.twilio.com',
                ],
                'from_number' => [
                    'type' => 'string',
                    'required' => true,
                    'label' => 'From Number',
                    'description' => 'Your Twilio phone number (E.164 format)',
                    'placeholder' => '+1234567890',
                    'validation' => ['pattern' => '/^\+[1-9]\d{1,14}$/'],
                ],
                'messaging_service_sid' => [
                    'type' => 'string',
                    'required' => false,
                    'label' => 'Messaging Service SID',
                    'description' => 'Optional Messaging Service SID for advanced features',
                    'placeholder' => 'MG...',
                ],
                'status_callback_url' => [
                    'type' => 'url',
                    'required' => false,
                    'label' => 'Status Callback URL',
                    'description' => 'Webhook URL for delivery status updates',
                ],
                'region' => [
                    'type' => 'select',
                    'required' => false,
                    'default' => 'us1',
                    'options' => [
                        'us1' => 'United States (US1)',
                        'ie1' => 'Ireland (IE1)',
                        'au1' => 'Australia (AU1)',
                        'sg1' => 'Singapore (SG1)',
                    ],
                    'label' => 'Region',
                    'description' => 'Twilio region for data processing',
                ],
                'timeout' => [
                    'type' => 'integer',
                    'required' => false,
                    'default' => 30,
                    'min' => 5,
                    'max' => 120,
                    'label' => 'Timeout (seconds)',
                    'description' => 'Request timeout in seconds',
                ],
                'enable_mms' => [
                    'type' => 'boolean',
                    'required' => false,
                    'default' => true,
                    'label' => 'Enable MMS',
                    'description' => 'Allow sending multimedia messages',
                ],
                'max_media_count' => [
                    'type' => 'integer',
                    'required' => false,
                    'default' => 10,
                    'min' => 1,
                    'max' => 10,
                    'label' => 'Max Media Count',
                    'description' => 'Maximum number of media attachments per message',
                ],
                'rate_limit_per_minute' => [
                    'type' => 'integer',
                    'required' => false,
                    'default' => 100,
                    'min' => 1,
                    'max' => 1000,
                    'label' => 'Rate Limit (per minute)',
                    'description' => 'Maximum messages per minute',
                ],
                'alphanumeric_sender' => [
                    'type' => 'string',
                    'required' => false,
                    'label' => 'Alphanumeric Sender ID',
                    'description' => 'Custom sender ID for supported regions',
                    'validation' => ['max_length' => 11],
                ],
                'webhook_validation' => [
                    'type' => 'boolean',
                    'required' => false,
                    'default' => true,
                    'label' => 'Webhook Validation',
                    'description' => 'Validate incoming webhooks from Twilio',
                ],
            ],
            'capabilities' => static::getCapabilities(),
        ];
    }

    /**
     * Sends an SMS/MMS message using Twilio.
     *
     * @param array $payload Message payload
     * @return array
     * @throws ProviderException
     */
    public function send(array $payload): array
    {
        if (!$this->isAuthenticated()) {
            throw new ProviderException('Twilio provider is not authenticated', 'sms', 'twilio');
        }

        try {
            $this->initializeClient();

            // Validate recipient
            $to = $this->validateRecipient($payload);
            
            // Prepare message options
            $options = $this->prepareMessageOptions($payload);

            // Send the message
            $message = $this->client->messages->create($to, $options);

            $this->lastSendResult = [
                'success' => true,
                'message_sid' => $message->sid,
                'status' => $message->status,
                'to' => $to,
                'from' => $options['from'] ?? $options['messagingServiceSid'] ?? '',
                'timestamp' => date('Y-m-d H:i:s'),
            ];

            return [
                'success' => true,
                'message_id' => $message->sid,
                'meta' => [
                    'provider' => 'twilio',
                    'status' => $message->status,
                    'to' => $to,
                    'from' => $this->lastSendResult['from'],
                    'timestamp' => $this->lastSendResult['timestamp'],
                    'price' => $message->price,
                    'price_unit' => $message->priceUnit,
                    'direction' => $message->direction,
                    'account_sid' => $message->accountSid,
                ],
            ];

        } catch (TwilioException $e) {
            $this->handleTwilioException($e);
        } catch (\Exception $e) {
            throw new ProviderException('Unexpected error: ' . $e->getMessage(), 'sms', 'twilio', 0, [], $e);
        }
    }

    /**
     * Authenticates the Twilio provider.
     *
     * @param array $config
     * @return bool
     */
    public function authenticate(array $config): bool
    {
        $this->setConfig($config);

        // Validate required configuration
        $required = ['account_sid', 'auth_token', 'from_number'];
        foreach ($required as $field) {
            if (empty($config[$field])) {
                $this->lastError = "Missing required field: {$field}";
                return false;
            }
        }

        // Validate account_sid format
        if (!preg_match('/^AC[a-f0-9]{32}$/', $config['account_sid'])) {
            $this->lastError = 'Invalid Account SID format';
            return false;
        }

        // Validate auth_token format
        if (!preg_match('/^[a-f0-9]{32}$/', $config['auth_token'])) {
            $this->lastError = 'Invalid Auth Token format';
            return false;
        }

        // Validate from_number format (E.164)
        if (!preg_match('/^\+[1-9]\d{1,14}$/', $config['from_number'])) {
            $this->lastError = 'Invalid from_number format (must be E.164)';
            return false;
        }

        // Validate messaging_service_sid if provided
        if (!empty($config['messaging_service_sid']) && !preg_match('/^MG[a-f0-9]{32}$/', $config['messaging_service_sid'])) {
            $this->lastError = 'Invalid Messaging Service SID format';
            return false;
        }

        // Validate status_callback_url if provided
        if (!empty($config['status_callback_url']) && !filter_var($config['status_callback_url'], FILTER_VALIDATE_URL)) {
            $this->lastError = 'Invalid status_callback_url format';
            return false;
        }

        $this->authenticated = true;
        $this->lastError = null;

        return true;
    }

    /**
     * Tests the Twilio connection.
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

            // Test connection by fetching account info
            $account = $this->client->api->v2010->account->fetch();
            
            if ($account->status === 'active') {
                return true;
            } else {
                $this->lastError = 'Account is not active: ' . $account->status;
                return false;
            }

        } catch (TwilioException $e) {
            $this->lastError = 'Twilio connection test failed: ' . $e->getMessage();
            return false;
        } catch (\Exception $e) {
            $this->lastError = 'Connection test failed: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Validates a webhook request from Twilio.
     *
     * @param array $payload Request payload
     * @param string $signature X-Twilio-Signature header
     * @param string $url Full request URL
     * @return bool
     */
    public function validateWebhook(array $payload, string $signature, string $url): bool
    {
        if (!$this->config['webhook_validation'] ?? true) {
            return true;
        }

        if (!$this->validator) {
            $this->validator = new RequestValidator($this->config['auth_token']);
        }

        return $this->validator->validate($signature, $url, $payload);
    }

    /**
     * Processes a status callback from Twilio.
     *
     * @param array $payload Webhook payload
     * @return array
     */
    public function processStatusCallback(array $payload): array
    {
        return [
            'message_sid' => $payload['MessageSid'] ?? '',
            'status' => $payload['MessageStatus'] ?? '',
            'to' => $payload['To'] ?? '',
            'from' => $payload['From'] ?? '',
            'error_code' => $payload['ErrorCode'] ?? null,
            'error_message' => $this->getErrorMessage($payload['ErrorCode'] ?? null),
            'timestamp' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Initializes the Twilio client.
     *
     * @return void
     */
    protected function initializeClient(): void
    {
        if ($this->client === null) {
            $this->client = new Client(
                $this->config['account_sid'],
                $this->config['auth_token']
            );

            // Set custom region if specified
            if (!empty($this->config['region']) && $this->config['region'] !== 'us1') {
                $this->client->setRegion($this->config['region']);
            }
        }
    }

    /**
     * Validates the recipient phone number.
     *
     * @param array $payload
     * @return string
     * @throws ProviderException
     */
    protected function validateRecipient(array $payload): string
    {
        if (empty($payload['to'])) {
            throw new ProviderException('No recipient specified', 'sms', 'twilio');
        }

        $to = $payload['to'];

        // Validate E.164 format
        if (!preg_match('/^\+[1-9]\d{1,14}$/', $to)) {
            throw new ProviderException('Invalid recipient format (must be E.164)', 'sms', 'twilio');
        }

        return $to;
    }

    /**
     * Prepares message options for Twilio API.
     *
     * @param array $payload
     * @return array
     * @throws ProviderException
     */
    protected function prepareMessageOptions(array $payload): array
    {
        $options = [];

        // Set sender
        if (!empty($this->config['messaging_service_sid'])) {
            $options['messagingServiceSid'] = $this->config['messaging_service_sid'];
        } elseif (!empty($this->config['alphanumeric_sender'])) {
            $options['from'] = $this->config['alphanumeric_sender'];
        } else {
            $options['from'] = $this->config['from_number'];
        }

        // Set message body
        if (empty($payload['body'])) {
            throw new ProviderException('No message body specified', 'sms', 'twilio');
        }
        $options['body'] = $payload['body'];

        // Set media URLs for MMS
        if (!empty($payload['media_urls']) && $this->config['enable_mms']) {
            $mediaUrls = is_array($payload['media_urls']) ? $payload['media_urls'] : [$payload['media_urls']];
            
            if (count($mediaUrls) > ($this->config['max_media_count'] ?? 10)) {
                throw new ProviderException('Too many media attachments', 'sms', 'twilio');
            }
            
            foreach ($mediaUrls as $mediaUrl) {
                if (!filter_var($mediaUrl, FILTER_VALIDATE_URL)) {
                    throw new ProviderException('Invalid media URL: ' . $mediaUrl, 'sms', 'twilio');
                }
            }
            
            $options['mediaUrl'] = $mediaUrls;
        }

        // Set status callback
        if (!empty($this->config['status_callback_url'])) {
            $options['statusCallback'] = $this->config['status_callback_url'];
        }

        // Set maximum price (if specified)
        if (!empty($payload['max_price'])) {
            $options['maxPrice'] = $payload['max_price'];
        }

        // Set validity period (if specified)
        if (!empty($payload['validity_period'])) {
            $options['validityPeriod'] = $payload['validity_period'];
        }

        // Set scheduled send time (if specified)
        if (!empty($payload['send_at'])) {
            $options['sendAt'] = $payload['send_at'];
        }

        return $options;
    }

    /**
     * Handles Twilio exceptions and converts them to ProviderException.
     *
     * @param TwilioException $e
     * @throws ProviderException
     */
    protected function handleTwilioException(TwilioException $e): void
    {
        $code = $e->getCode();
        $message = $e->getMessage();
        
        // Map common Twilio errors to user-friendly messages
        $errorMap = [
            21211 => 'Invalid phone number format',
            21212 => 'Invalid phone number',
            21408 => 'Permission denied for sending to this number',
            21610 => 'Message body exceeds maximum length',
            21614 => 'Message contains invalid characters',
            30001 => 'Queue overflow - message not sent',
            30002 => 'Account suspended',
            30003 => 'Unreachable destination',
            30004 => 'Message blocked by carrier',
            30005 => 'Unknown destination',
            30006 => 'Landline or unreachable carrier',
            30007 => 'Carrier violation',
            30008 => 'Unknown error',
        ];

        $userMessage = $errorMap[$code] ?? $message;
        
        throw new ProviderException(
            "Twilio error [{$code}]: {$userMessage}",
            'sms',
            'twilio',
            $code,
            ['original_message' => $message],
            $e
        );
    }

    /**
     * Gets human-readable error message from error code.
     *
     * @param int|null $errorCode
     * @return string|null
     */
    protected function getErrorMessage(?int $errorCode): ?string
    {
        if ($errorCode === null) {
            return null;
        }

        $errorMessages = [
            30001 => 'Queue overflow',
            30002 => 'Account suspended',
            30003 => 'Unreachable destination',
            30004 => 'Message blocked',
            30005 => 'Unknown destination',
            30006 => 'Landline or unreachable carrier',
            30007 => 'Carrier violation',
            30008 => 'Unknown error',
        ];

        return $errorMessages[$errorCode] ?? "Error code: {$errorCode}";
    }

    /**
     * Gets message status information.
     *
     * @param string $messageSid
     * @return array|null
     */
    public function getMessageStatus(string $messageSid): ?array
    {
        if (!$this->isAuthenticated()) {
            return null;
        }

        try {
            $this->initializeClient();
            $message = $this->client->messages($messageSid)->fetch();

            return [
                'sid' => $message->sid,
                'status' => $message->status,
                'error_code' => $message->errorCode,
                'error_message' => $this->getErrorMessage($message->errorCode),
                'price' => $message->price,
                'price_unit' => $message->priceUnit,
                'direction' => $message->direction,
                'date_created' => $message->dateCreated->format('Y-m-d H:i:s'),
                'date_sent' => $message->dateSent ? $message->dateSent->format('Y-m-d H:i:s') : null,
                'date_updated' => $message->dateUpdated->format('Y-m-d H:i:s'),
            ];

        } catch (TwilioException $e) {
            return null;
        }
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
        $mask = $fieldsToMask ?: ['auth_token'];
        return parent::getSanitisedConfig($mask);
    }
}
