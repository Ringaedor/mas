<?php
namespace Opencart\System\Library\Mas\Core;

use Opencart\System\Engine\Registry;

/**
 * ConsentManager - Manages user consent for marketing automation.
 *
 * Handles registration, update, verification, and synchronization of user consents.
 */
class ConsentManager {
    /** @var Registry $registry */
    protected $registry;

    /** @var array $consents */
    protected $consents = [];

    /** @var array $consentHistory */
    protected $consentHistory = [];

    /**
     * Constructor.
     *
     * @param Registry $registry
     */
    public function __construct(Registry $registry) {
        $this->registry = $registry;
    }

    /**
     * Registers a new consent for a user.
     *
     * @param int $userId
     * @param string $consentType
     * @param bool $granted
     * @param array $context
     * @return void
     */
    public function registerConsent(int $userId, string $consentType, bool $granted, array $context = []): void {
        $consentKey = $this->getConsentKey($userId, $consentType);
        $this->consents[$consentKey] = [
            'userId'    => $userId,
            'type'      => $consentType,
            'granted'   => $granted,
            'timestamp' => date('Y-m-d H:i:s'),
            'context'   => $context
        ];
        $this->logConsent($userId, $consentType, $granted, 'Consent registered', $context);
    }

    /**
     * Updates an existing user consent.
     *
     * @param int $userId
     * @param string $consentType
     * @param bool $granted
     * @param array $context
     * @return void
     */
    public function updateConsent(int $userId, string $consentType, bool $granted, array $context = []): void {
        $consentKey = $this->getConsentKey($userId, $consentType);
        if (isset($this->consents[$consentKey])) {
            $this->consents[$consentKey] = [
                'userId'    => $userId,
                'type'      => $consentType,
                'granted'   => $granted,
                'timestamp' => date('Y-m-d H:i:s'),
                'context'   => $context
            ];
            $this->logConsent($userId, $consentType, $granted, 'Consent updated', $context);
        }
    }

    /**
     * Revokes a user consent.
     *
     * @param int $userId
     * @param string $consentType
     * @param array $context
     * @return void
     */
    public function revokeConsent(int $userId, string $consentType, array $context = []): void {
        $consentKey = $this->getConsentKey($userId, $consentType);
        if (isset($this->consents[$consentKey])) {
            $this->consents[$consentKey]['granted'] = false;
            $this->consents[$consentKey]['timestamp'] = date('Y-m-d H:i:s');
            $this->consents[$consentKey]['context'] = $context;
            $this->logConsent($userId, $consentType, false, 'Consent revoked', $context);
        }
    }

    /**
     * Checks if a user has granted a specific consent.
     *
     * @param int $userId
     * @param string $consentType
     * @return bool|null True if granted, false if revoked, null if not registered
     */
    public function hasConsent(int $userId, string $consentType): ?bool {
        $consentKey = $this->getConsentKey($userId, $consentType);
        return $this->consents[$consentKey]['granted'] ?? null;
    }

    /**
     * Gets all consents for a user.
     *
     * @param int $userId
     * @return array
     */
    public function getUserConsents(int $userId): array {
        $userConsents = [];
        foreach ($this->consents as $consent) {
            if ($consent['userId'] == $userId) {
                $userConsents[$consent['type']] = $consent;
            }
        }
        return $userConsents;
    }

    /**
     * Gets all registered consents.
     *
     * @return array
     */
    public function getAllConsents(): array {
        return $this->consents;
    }

    /**
     * Logs a consent event for auditing and troubleshooting.
     *
     * @param int $userId
     * @param string $consentType
     * @param bool $granted
     * @param string $message
     * @param array $context
     * @return void
     */
    protected function logConsent(int $userId, string $consentType, bool $granted, string $message, array $context = []): void {
        $this->consentHistory[] = [
            'timestamp'  => date('Y-m-d H:i:s'),
            'userId'     => $userId,
            'type'       => $consentType,
            'granted'    => $granted,
            'message'    => $message,
            'context'    => $context
        ];
    }

    /**
     * Gets the consent history for a user.
     *
     * @param int $userId
     * @return array
     */
    public function getUserConsentHistory(int $userId): array {
        return array_filter($this->consentHistory, function($entry) use ($userId) {
            return $entry['userId'] == $userId;
        });
    }

    /**
     * Gets the full consent history.
     *
     * @return array
     */
    public function getConsentHistory(): array {
        return $this->consentHistory;
    }

    /**
     * Synchronizes consents with OpenCart.
     * This method ensures that consents are always up-to-date with the main database.
     *
     * @return bool
     */
    public function syncWithOpenCart(): bool {
        // Example: you would synchronize consents with OpenCart's database here
        // For now, just keep the logic as a placeholder
        return true;
    }

    /**
     * Generates a unique key for a user consent.
     *
     * @param int $userId
     * @param string $consentType
     * @return string
     */
    protected function getConsentKey(int $userId, string $consentType): string {
        return $userId . '_' . $consentType;
    }
}