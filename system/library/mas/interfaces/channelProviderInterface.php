<?php
/**
 * MAS - Marketing Automation Suite
 * ChannelProviderInterface
 *
 * Contract for all provider classes (email, sms, push, ai, etc.) used in MAS.
 */

namespace Opencart\Library\Mas\Interfaces;

interface ChannelProviderInterface
{
    /** Provider type constants */
    public const TYPE_EMAIL = 'email';
    public const TYPE_SMS   = 'sms';
    public const TYPE_PUSH  = 'push';
    public const TYPE_AI    = 'ai';

    /**
     * Sends a message or executes the provider’s primary action.
     *
     * @param array $payload Normalised payload (recipient data, content, options).
     * @return array{
     *     success: bool,
     *     message_id?: string,
     *     error?: string,
     *     meta?: array
     * }
     */
    public function send(array $payload): array;

    /**
     * Authenticates or initialises the provider with given credentials/config.
     *
     * @param array $config Configuration (API keys, endpoints, etc.).
     * @return bool
     */
    public function authenticate(array $config): bool;

    /**
     * Tests connectivity and credentials without sending a real message.
     *
     * @return bool
     */
    public function testConnection(): bool;

    /**
     * Returns the unique provider name (e.g., “SendGrid”, “Twilio”).
     *
     * @return string
     */
    public static function getName(): string;

    /**
     * Returns a short human-readable description.
     *
     * @return string
     */
    public static function getDescription(): string;

    /**
     * Returns the provider type (email, sms, push, ai).
     *
     * @return string
     */
    public static function getType(): string;

    /**
     * Returns provider version.
     *
     * @return string
     */
    public static function getVersion(): string;

    /**
     * Returns an array describing provider capabilities
     * (e.g., ['bulk_send', 'tracking', 'templates']).
     *
     * @return string[]
     */
    public static function getCapabilities(): array;

    /**
     * Returns the full setup schema definition.
     * Implemented as per MAS auto-discovery requirements.
     *
     * @return array<string, mixed>
     */
    public static function getSetupSchema(): array;

    /**
     * Sets or updates runtime configuration after authentication.
     *
     * @param array $config
     * @return void
     */
    public function setConfig(array $config): void;

    /**
     * Returns current runtime configuration (sanitised).
     *
     * @return array
     */
    public function getConfig(): array;
}
