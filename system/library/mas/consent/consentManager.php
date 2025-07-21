<?php
/**
 * MAS - Marketing Automation Suite
 * ConsentManager
 *
 * Centralized manager for customer/user consents (GDPR, marketing, cookies, T&C, etc.).
 * Handles creation and lifecycle of consent definitions, logging of accept/revoke events,
 * version control, analytics and compliance exports (EN 29184 / GDPR Art. 7).
 *
 * Path: system/library/mas/consent/ConsentManager.php
 *
 * © 2025 Your Company – Proprietary
 */

namespace Opencart\Library\Mas\Consent;

use Opencart\Library\Mas\ServiceContainer;
use Opencart\Library\Mas\Exception\ConsentException;
use Opencart\Library\Mas\Helper\ArrayHelper;
use Opencart\Library\Mas\Helper\DateHelper;
use Opencart\Library\Mas\Events\Event;
use Opencart\System\Engine\Log;
use Opencart\System\Library\DB;
use Opencart\System\Library\Cache;

class ConsentManager
{
    public const TABLE_DEFINITION = 'mas_consent_definition';
    public const TABLE_LOG        = 'mas_consent_log';

    protected ServiceContainer $container;
    protected Log    $log;
    protected DB     $db;
    protected Cache  $cache;

    /** Cache TTL for definitions (seconds) */
    protected int $ttl = 3600;

    public function __construct(ServiceContainer $container)
    {
        $this->container = $container;
        $this->log       = $container->get('log');
        $this->db        = $container->get('db');
        $this->cache     = $container->get('cache');
    }

    /* ---------------------------------------------------------------------
     *  Definitions
     * -------------------------------------------------------------------*/
    public function createDefinition(array $data): string
    {
        $this->validateDefinition($data);

        $code    = $data['code'];
        $version = $data['version'] ?? '1.0';

        $this->db->query("
            INSERT INTO `" . self::TABLE_DEFINITION . "`
            SET `code`        = '" . $this->db->escape($code) . "',
                `name`        = '" . $this->db->escape($data['name']) . "',
                `description` = '" . $this->db->escape($data['description']) . "',
                `version`     = '" . $this->db->escape($version) . "',
                `required`    = '" . (int)($data['required'] ?? 0) . "',
                `created_at`  = NOW(),
                `updated_at`  = NOW()
        ");

        $this->cache->delete('mas_consent_def_' . $code);
        return $code;
    }

    public function updateDefinition(string $code, array $data): void
    {
        if (!$this->definitionExists($code)) {
            throw new ConsentException("Consent definition {$code} not found");
        }
        $this->validateDefinition($data, false);

        $fields = [];
        foreach (['name','description','version','required'] as $k) {
            if (isset($data[$k])) {
                $value = is_numeric($data[$k]) ? (int)$data[$k] : $this->db->escape($data[$k]);
                $fields[] = "`{$k}` = '{$value}'";
            }
        }
        if (!$fields) {
            return;
        }
        $fields[] = "`updated_at` = NOW()";

        $this->db->query("
            UPDATE `" . self::TABLE_DEFINITION . "`
            SET " . implode(', ', $fields) . "
            WHERE `code` = '" . $this->db->escape($code) . "'
        ");
        $this->cache->delete('mas_consent_def_' . $code);
    }

    public function getDefinition(string $code): ?array
    {
        if ($cached = $this->cache->get('mas_consent_def_' . $code)) {
            return $cached;
        }
        $q = $this->db->query("SELECT * FROM `" . self::TABLE_DEFINITION . "` WHERE `code` = '" . $this->db->escape($code) . "'");
        if (!$q->num_rows) {
            return null;
        }
        $this->cache->set('mas_consent_def_' . $code, $q->row, $this->ttl);
        return $q->row;
    }

    public function listDefinitions(): array
    {
        $q = $this->db->query("SELECT * FROM `" . self::TABLE_DEFINITION . "` ORDER BY `code`");
        return $q->rows;
    }

    public function deleteDefinition(string $code): void
    {
        $this->db->query("DELETE FROM `" . self::TABLE_DEFINITION . "` WHERE `code` = '" . $this->db->escape($code) . "'");
        $this->cache->delete('mas_consent_def_' . $code);
    }

    /* ---------------------------------------------------------------------
     *  Customer consent log
     * -------------------------------------------------------------------*/
    public function recordConsent(int $customerId, string $code, array $meta = []): void
    {
        $def = $this->getDefinition($code);
        if (!$def) {
            throw new ConsentException("Consent code {$code} unknown");
        }

        $this->db->query("
            INSERT INTO `" . self::TABLE_LOG . "`
            SET `customer_id`  = {$customerId},
                `code`         = '" . $this->db->escape($code) . "',
                `version`      = '" . $this->db->escape($def['version']) . "',
                `action`       = 'accept',
                `metadata`     = '" . $this->db->escape(json_encode($meta)) . "',
                `ip_address`   = '" . $this->db->escape($meta['ip'] ?? $_SERVER['REMOTE_ADDR'] ?? '') . "',
                `user_agent`   = '" . $this->db->escape($meta['ua'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')) . "',
                `created_at`   = NOW()
        ");
        
        if ($this->container->has('mas.event_dispatcher')) {
            $this->container->get('mas.event_dispatcher')->dispatch(
                new Event('consent.accepted', [
                    'customer_id' => $customerId,
                    'code' => $code,
                    'version' => $def['version']
                ])
                );
        }

        $this->emit('consent.accepted', [
            'customer_id' => $customerId,
            'code'        => $code,
            'version'     => $def['version'],
        ]);
    }

    public function revokeConsent(int $customerId, string $code, array $meta = []): void
    {
        $def = $this->getDefinition($code);
        if (!$def) {
            throw new ConsentException("Consent code {$code} unknown");
        }

        $this->db->query("
            INSERT INTO `" . self::TABLE_LOG . "`
            SET `customer_id`  = {$customerId},
                `code`         = '" . $this->db->escape($code) . "',
                `version`      = '" . $this->db->escape($def['version']) . "',
                `action`       = 'revoke',
                `metadata`     = '" . $this->db->escape(json_encode($meta)) . "',
                `ip_address`   = '" . $this->db->escape($meta['ip'] ?? $_SERVER['REMOTE_ADDR'] ?? '') . "',
                `user_agent`   = '" . $this->db->escape($meta['ua'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')) . "',
                `created_at`   = NOW()
        ");
        
        if ($this->container->has('mas.event_dispatcher')) {
            $this->container->get('mas.event_dispatcher')->dispatch(
                new Event('consent.revoked', [
                    'customer_id' => $customerId,
                    'code' => $code,
                    'version' => $def['version']
                ])
                );
        }

        $this->emit('consent.revoked', [
            'customer_id' => $customerId,
            'code'        => $code,
            'version'     => $def['version'],
        ]);
    }

    public function hasConsent(int $customerId, string $code): bool
    {
        $q = $this->db->query("
            SELECT `action`
            FROM `" . self::TABLE_LOG . "`
            WHERE `customer_id` = {$customerId}
              AND `code` = '" . $this->db->escape($code) . "'
            ORDER BY `created_at` DESC
            LIMIT 1
        ");
        if (!$q->num_rows) {
            return false;
        }
        return $q->row['action'] === 'accept';
    }

    public function customerConsents(int $customerId): array
    {
        $q = $this->db->query("
            SELECT *
            FROM `" . self::TABLE_LOG . "`
            WHERE `customer_id` = {$customerId}
            ORDER BY `created_at` DESC
        ");
        return $q->rows;
    }

    public function listLogs(array $filters = []): array
    {
        $sql = "SELECT * FROM `" . self::TABLE_LOG . "` WHERE 1=1";
        if (!empty($filters['code'])) {
            $sql .= " AND `code` = '" . $this->db->escape($filters['code']) . "'";
        }
        if (!empty($filters['customer_id'])) {
            $sql .= " AND `customer_id` = " . (int)$filters['customer_id'];
        }
        if (!empty($filters['action'])) {
            $sql .= " AND `action` = '" . $this->db->escape($filters['action']) . "'";
        }
        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(`created_at`) >= '" . $this->db->escape($filters['date_from']) . "'";
        }
        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(`created_at`) <= '" . $this->db->escape($filters['date_to']) . "'";
        }
        $sql .= " ORDER BY `created_at` DESC";
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }
        return $this->db->query($sql)->rows;
    }

    /* ---------------------------------------------------------------------
     *  Utilities
     * -------------------------------------------------------------------*/
    protected function definitionExists(string $code): bool
    {
        return (bool)$this->db->query("
            SELECT 1 FROM `" . self::TABLE_DEFINITION . "` WHERE `code` = '" . $this->db->escape($code) . "' LIMIT 1
        ")->num_rows;
    }

    protected function validateDefinition(array $data, bool $requireAll = true): void
    {
        foreach (['code','name','description'] as $k) {
            if (($requireAll && empty($data[$k])) || (!empty($data[$k]) && !is_string($data[$k]))) {
                throw new ConsentException("Invalid field '{$k}' in consent definition");
            }
        }
    }

    protected function emit(string $eventName, array $payload): void
    {
        if ($this->container->has('mas.event_dispatcher')) {
            $this->container->get('mas.event_dispatcher')->dispatch(new Event($eventName, $payload));
        }
    }
}
