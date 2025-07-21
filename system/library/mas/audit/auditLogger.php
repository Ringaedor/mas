<?php
/**
 * MAS - Marketing Automation Suite
 * AuditLogger
 *
 * Centralized audit logging system for MAS operations. Records all significant
 * system events, user actions, data changes, security events, and compliance
 * activities with detailed context and metadata.
 *
 * Features:
 * - Comprehensive audit trail for compliance (GDPR, SOX, HIPAA)
 * - Event categorization and severity levels
 * - User action tracking with IP and user agent
 * - Data change logging with before/after snapshots
 * - Security event monitoring
 * - Automatic log rotation and archival
 * - Real-time alerts for critical events
 * - Search and filtering capabilities
 *
 * @copyright Copyright (c) 2025, Your Company
 * @license   Proprietary
 */

namespace Opencart\Library\Mas\Audit;

use Opencart\Library\Mas\ServiceContainer;
use Opencart\Library\Mas\Exception\AuditException;
use Opencart\Library\Mas\Helper\ArrayHelper;
use Opencart\Library\Mas\Helper\DateHelper;
use Opencart\Library\Mas\Events\Event;
use Opencart\System\Engine\Log;
use Opencart\System\Library\Cache;
use Opencart\System\Library\DB;
use Opencart\System\Library\Session;

class AuditLogger
{
    /**
     * @var ServiceContainer
     */
    protected ServiceContainer $container;

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
     * @var Session
     */
    protected Session $session;

    /**
     * @var array Configuration settings
     */
    protected array $config = [];

    /**
     * @var bool Enable/disable audit logging
     */
    protected bool $enabled = true;

    /**
     * @var array Event categories
     */
    protected array $categories = [
        'security' => 'Security Events',
        'data' => 'Data Changes',
        'user' => 'User Actions',
        'system' => 'System Events',
        'workflow' => 'Workflow Operations',
        'segment' => 'Segment Operations',
        'campaign' => 'Campaign Operations',
        'compliance' => 'Compliance Events',
        'api' => 'API Calls',
        'email' => 'Email Operations',
        'payment' => 'Payment Operations',
    ];

    /**
     * @var array Severity levels
     */
    protected array $severityLevels = [
        'critical' => 1,
        'high' => 2,
        'medium' => 3,
        'low' => 4,
        'info' => 5,
    ];

    /**
     * @var array Events that require immediate alerts
     */
    protected array $alertEvents = [
        'security.login_failed_multiple',
        'security.unauthorized_access',
        'security.privilege_escalation',
        'data.mass_deletion',
        'system.critical_error',
        'compliance.gdpr_violation',
        'payment.fraud_detected',
    ];

    /**
     * @var int Log retention period in days
     */
    protected int $retentionDays = 2555; // 7 years for compliance

    /**
     * @var int Batch size for log processing
     */
    protected int $batchSize = 1000;

    /**
     * Constructor.
     *
     * @param ServiceContainer $container
     * @param array $config
     */
    public function __construct(ServiceContainer $container, array $config = [])
    {
        $this->container = $container;
        $this->log = $container->get('log');
        $this->cache = $container->get('cache');
        $this->db = $container->get('db');
        $this->session = $container->get('session');

        $this->config = $config;
        $this->enabled = $config['enabled'] ?? $this->enabled;
        $this->retentionDays = $config['retention_days'] ?? $this->retentionDays;
        $this->batchSize = $config['batch_size'] ?? $this->batchSize;

        if (!empty($config['alert_events'])) {
            $this->alertEvents = array_merge($this->alertEvents, $config['alert_events']);
        }
    }

    /**
     * Logs a security event.
     *
     * @param string $action
     * @param array $context
     * @param string $severity
     * @return void
     */
    public function logSecurity(string $action, array $context = [], string $severity = 'medium'): void
    {
        $this->logEvent('security', $action, $context, $severity);
    }

    /**
     * Logs a data change event.
     *
     * @param string $action
     * @param string $table
     * @param int $recordId
     * @param array $before
     * @param array $after
     * @param string $severity
     * @return void
     */
    public function logDataChange(string $action, string $table, int $recordId, array $before = [], array $after = [], string $severity = 'info'): void
    {
        $context = [
            'table' => $table,
            'record_id' => $recordId,
            'before' => $before,
            'after' => $after,
            'changes' => $this->calculateChanges($before, $after),
        ];

        $this->logEvent('data', $action, $context, $severity);
    }

    /**
     * Logs a user action.
     *
     * @param string $action
     * @param array $context
     * @param string $severity
     * @return void
     */
    public function logUserAction(string $action, array $context = [], string $severity = 'info'): void
    {
        $this->logEvent('user', $action, $context, $severity);
    }

    /**
     * Logs a system event.
     *
     * @param string $action
     * @param array $context
     * @param string $severity
     * @return void
     */
    public function logSystem(string $action, array $context = [], string $severity = 'info'): void
    {
        $this->logEvent('system', $action, $context, $severity);
    }

    /**
     * Logs a workflow event.
     *
     * @param string $action
     * @param int $workflowId
     * @param int $executionId
     * @param array $context
     * @param string $severity
     * @return void
     */
    public function logWorkflow(string $action, int $workflowId, int $executionId = null, array $context = [], string $severity = 'info'): void
    {
        $context = array_merge($context, [
            'workflow_id' => $workflowId,
            'execution_id' => $executionId,
        ]);

        $this->logEvent('workflow', $action, $context, $severity);
    }

    /**
     * Logs a segment event.
     *
     * @param string $action
     * @param int $segmentId
     * @param array $context
     * @param string $severity
     * @return void
     */
    public function logSegment(string $action, int $segmentId, array $context = [], string $severity = 'info'): void
    {
        $context = array_merge($context, [
            'segment_id' => $segmentId,
        ]);

        $this->logEvent('segment', $action, $context, $severity);
    }

    /**
     * Logs a campaign event.
     *
     * @param string $action
     * @param int $campaignId
     * @param array $context
     * @param string $severity
     * @return void
     */
    public function logCampaign(string $action, int $campaignId, array $context = [], string $severity = 'info'): void
    {
        $context = array_merge($context, [
            'campaign_id' => $campaignId,
        ]);

        $this->logEvent('campaign', $action, $context, $severity);
    }

    /**
     * Logs a compliance event.
     *
     * @param string $action
     * @param array $context
     * @param string $severity
     * @return void
     */
    public function logCompliance(string $action, array $context = [], string $severity = 'high'): void
    {
        $this->logEvent('compliance', $action, $context, $severity);
    }

    /**
     * Logs an API call.
     *
     * @param string $endpoint
     * @param string $method
     * @param array $context
     * @param string $severity
     * @return void
     */
    public function logApi(string $endpoint, string $method, array $context = [], string $severity = 'info'): void
    {
        $context = array_merge($context, [
            'endpoint' => $endpoint,
            'method' => $method,
        ]);

        $this->logEvent('api', 'call', $context, $severity);
    }

    /**
     * Logs an email event.
     *
     * @param string $action
     * @param array $context
     * @param string $severity
     * @return void
     */
    public function logEmail(string $action, array $context = [], string $severity = 'info'): void
    {
        $this->logEvent('email', $action, $context, $severity);
    }

    /**
     * Logs a payment event.
     *
     * @param string $action
     * @param array $context
     * @param string $severity
     * @return void
     */
    public function logPayment(string $action, array $context = [], string $severity = 'medium'): void
    {
        // Sanitize sensitive payment data
        $context = $this->sanitizePaymentData($context);
        $this->logEvent('payment', $action, $context, $severity);
    }

    /**
     * Main event logging method.
     *
     * @param string $category
     * @param string $action
     * @param array $context
     * @param string $severity
     * @return void
     */
    protected function logEvent(string $category, string $action, array $context = [], string $severity = 'info'): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $eventData = $this->buildEventData($category, $action, $context, $severity);
            $this->storeEvent($eventData);
            
            if ($this->container->has('mas.event_dispatcher')) {
                $this->container->get('mas.event_dispatcher')->dispatch(
                    new Event('audit.logged', $eventData)
                    );
            }

            // Check for alert conditions
            $eventKey = $category . '.' . $action;
            if (in_array($eventKey, $this->alertEvents) || $severity === 'critical') {
                $this->triggerAlert($eventData);
            }

            // Emit event for other components
            $this->emitAuditEvent($eventData);

        } catch (\Exception $e) {
            $this->log->write('MAS AuditLogger: Failed to log event - ' . $e->getMessage());
        }
    }

    /**
     * Builds event data structure.
     *
     * @param string $category
     * @param string $action
     * @param array $context
     * @param string $severity
     * @return array
     */
    protected function buildEventData(string $category, string $action, array $context, string $severity): array
    {
        $userId = $this->getCurrentUserId();
        $customerId = $this->getCurrentCustomerId();

        return [
            'event_id' => $this->generateEventId(),
            'category' => $category,
            'action' => $action,
            'severity' => $severity,
            'severity_level' => $this->severityLevels[$severity] ?? 5,
            'user_id' => $userId,
            'customer_id' => $customerId,
            'ip_address' => $this->getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'session_id' => $this->session->getId(),
            'context' => json_encode($context),
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'timestamp' => DateHelper::now(),
            'created_at' => 'NOW()',
        ];
    }

    /**
     * Stores event in database.
     *
     * @param array $eventData
     * @return void
     */
    protected function storeEvent(array $eventData): void
    {
        $fields = [];
        $values = [];

        foreach ($eventData as $field => $value) {
            if ($field === 'created_at' && $value === 'NOW()') {
                $fields[] = "`{$field}`";
                $values[] = "NOW()";
            } else {
                $fields[] = "`{$field}`";
                $values[] = "'" . $this->db->escape($value) . "'";
            }
        }

        $this->db->query("
            INSERT INTO `mas_audit_log` 
            (" . implode(', ', $fields) . ") 
            VALUES (" . implode(', ', $values) . ")
        ");
    }

    /**
     * Searches audit logs.
     *
     * @param array $filters
     * @param int $page
     * @param int $limit
     * @return array
     */
    public function searchLogs(array $filters = [], int $page = 1, int $limit = 50): array
    {
        $offset = ($page - 1) * $limit;
        $conditions = [];
        $params = [];

        // Build WHERE conditions
        if (!empty($filters['category'])) {
            $conditions[] = "`category` = ?";
            $params[] = $filters['category'];
        }

        if (!empty($filters['action'])) {
            $conditions[] = "`action` LIKE ?";
            $params[] = '%' . $filters['action'] . '%';
        }

        if (!empty($filters['severity'])) {
            if (is_array($filters['severity'])) {
                $placeholders = str_repeat('?,', count($filters['severity']) - 1) . '?';
                $conditions[] = "`severity` IN ({$placeholders})";
                $params = array_merge($params, $filters['severity']);
            } else {
                $conditions[] = "`severity` = ?";
                $params[] = $filters['severity'];
            }
        }

        if (!empty($filters['user_id'])) {
            $conditions[] = "`user_id` = ?";
            $params[] = $filters['user_id'];
        }

        if (!empty($filters['customer_id'])) {
            $conditions[] = "`customer_id` = ?";
            $params[] = $filters['customer_id'];
        }

        if (!empty($filters['ip_address'])) {
            $conditions[] = "`ip_address` = ?";
            $params[] = $filters['ip_address'];
        }

        if (!empty($filters['date_from'])) {
            $conditions[] = "DATE(`created_at`) >= ?";
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $conditions[] = "DATE(`created_at`) <= ?";
            $params[] = $filters['date_to'];
        }

        if (!empty($filters['search'])) {
            $conditions[] = "(context LIKE ? OR action LIKE ?)";
            $params[] = '%' . $filters['search'] . '%';
            $params[] = '%' . $filters['search'] . '%';
        }

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        // Get total count
        $countQuery = $this->db->query("
            SELECT COUNT(*) as total 
            FROM `mas_audit_log` 
            {$whereClause}
        ", $params);

        $total = (int)$countQuery->row['total'];

        // Get results
        $dataQuery = $this->db->query("
            SELECT * 
            FROM `mas_audit_log` 
            {$whereClause}
            ORDER BY `created_at` DESC 
            LIMIT {$limit} OFFSET {$offset}
        ", $params);

        $logs = $dataQuery->rows;

        // Decode context for each log
        foreach ($logs as &$log) {
            $log['context'] = json_decode($log['context'], true) ?: [];
        }

        return [
            'logs' => $logs,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($total / $limit),
        ];
    }

    /**
     * Gets audit statistics.
     *
     * @param string $period
     * @return array
     */
    public function getStatistics(string $period = '7d'): array
    {
        $dateFrom = match ($period) {
            '1d' => DateHelper::nowObject()->subDay()->format('Y-m-d H:i:s'),
            '7d' => DateHelper::nowObject()->subDays(7)->format('Y-m-d H:i:s'),
            '30d' => DateHelper::nowObject()->subDays(30)->format('Y-m-d H:i:s'),
            '90d' => DateHelper::nowObject()->subDays(90)->format('Y-m-d H:i:s'),
            default => DateHelper::nowObject()->subDays(7)->format('Y-m-d H:i:s'),
        };

        $stats = [];

        // Total events by category
        $categoryQuery = $this->db->query("
            SELECT category, COUNT(*) as count
            FROM `mas_audit_log`
            WHERE `created_at` >= ?
            GROUP BY category
            ORDER BY count DESC
        ", [$dateFrom]);

        $stats['by_category'] = $categoryQuery->rows;

        // Total events by severity
        $severityQuery = $this->db->query("
            SELECT severity, COUNT(*) as count
            FROM `mas_audit_log`
            WHERE `created_at` >= ?
            GROUP BY severity
            ORDER BY severity_level ASC
        ", [$dateFrom]);

        $stats['by_severity'] = $severityQuery->rows;

        // Timeline
        $timelineQuery = $this->db->query("
            SELECT DATE(created_at) as date, COUNT(*) as count
            FROM `mas_audit_log`
            WHERE `created_at` >= ?
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ", [$dateFrom]);

        $stats['timeline'] = $timelineQuery->rows;

        // Top users
        $usersQuery = $this->db->query("
            SELECT user_id, COUNT(*) as count
            FROM `mas_audit_log`
            WHERE `created_at` >= ? AND user_id IS NOT NULL
            GROUP BY user_id
            ORDER BY count DESC
            LIMIT 10
        ", [$dateFrom]);

        $stats['top_users'] = $usersQuery->rows;

        // Recent critical events
        $criticalQuery = $this->db->query("
            SELECT *
            FROM `mas_audit_log`
            WHERE `created_at` >= ? AND severity IN ('critical', 'high')
            ORDER BY created_at DESC
            LIMIT 10
        ", [$dateFrom]);

        $stats['recent_critical'] = $criticalQuery->rows;

        return $stats;
    }

    /**
     * Exports audit logs to CSV.
     *
     * @param array $filters
     * @param string $filename
     * @return string
     */
    public function exportToCsv(array $filters = [], string $filename = null): string
    {
        $filename = $filename ?: 'audit_log_' . date('Y-m-d_H-i-s') . '.csv';
        $filepath = DIR_STORAGE . 'audit/' . $filename;

        // Ensure directory exists
        $dir = dirname($filepath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $handle = fopen($filepath, 'w');
        if (!$handle) {
            throw new AuditException("Cannot create export file: {$filepath}");
        }

        // Write UTF-8 BOM for Excel compatibility
        fwrite($handle, "\xEF\xBB\xBF");

        // Write headers
        $headers = [
            'Event ID', 'Category', 'Action', 'Severity', 'User ID', 'Customer ID',
            'IP Address', 'User Agent', 'Request URI', 'Context', 'Created At'
        ];
        fputcsv($handle, $headers);

        // Process in batches
        $page = 1;
        do {
            $result = $this->searchLogs($filters, $page, $this->batchSize);
            
            foreach ($result['logs'] as $log) {
                $row = [
                    $log['event_id'],
                    $log['category'],
                    $log['action'],
                    $log['severity'],
                    $log['user_id'],
                    $log['customer_id'],
                    $log['ip_address'],
                    $log['user_agent'],
                    $log['request_uri'],
                    json_encode($log['context']),
                    $log['created_at'],
                ];
                fputcsv($handle, $row);
            }

            $page++;
        } while ($page <= $result['pages']);

        fclose($handle);

        $this->logSystem('audit_export', ['filename' => $filename, 'filters' => $filters]);

        return $filepath;
    }

    /**
     * Archives old logs.
     *
     * @param int $days
     * @return int
     */
    public function archiveLogs(int $days = null): int
    {
        $days = $days ?: $this->retentionDays;
        $cutoffDate = DateHelper::nowObject()->subDays($days)->format('Y-m-d H:i:s');

        // First, export to archive table
        $this->db->query("
            INSERT INTO `mas_audit_log_archive` 
            SELECT * FROM `mas_audit_log` 
            WHERE `created_at` < ?
        ", [$cutoffDate]);

        // Then delete from main table
        $this->db->query("
            DELETE FROM `mas_audit_log` 
            WHERE `created_at` < ?
        ", [$cutoffDate]);

        $archivedCount = $this->db->countAffected();

        $this->logSystem('logs_archived', [
            'cutoff_date' => $cutoffDate,
            'archived_count' => $archivedCount
        ]);

        return $archivedCount;
    }

    /**
     * Purges archived logs.
     *
     * @param int $days
     * @return int
     */
    public function purgeArchivedLogs(int $days): int
    {
        $cutoffDate = DateHelper::nowObject()->subDays($days)->format('Y-m-d H:i:s');

        $this->db->query("
            DELETE FROM `mas_audit_log_archive` 
            WHERE `created_at` < ?
        ", [$cutoffDate]);

        $purgedCount = $this->db->countAffected();

        $this->logSystem('archived_logs_purged', [
            'cutoff_date' => $cutoffDate,
            'purged_count' => $purgedCount
        ]);

        return $purgedCount;
    }

    /**
     * Gets user activity summary.
     *
     * @param int $userId
     * @param string $period
     * @return array
     */
    public function getUserActivity(int $userId, string $period = '30d'): array
    {
        $dateFrom = match ($period) {
            '1d' => DateHelper::nowObject()->subDay()->format('Y-m-d H:i:s'),
            '7d' => DateHelper::nowObject()->subDays(7)->format('Y-m-d H:i:s'),
            '30d' => DateHelper::nowObject()->subDays(30)->format('Y-m-d H:i:s'),
            '90d' => DateHelper::nowObject()->subDays(90)->format('Y-m-d H:i:s'),
            default => DateHelper::nowObject()->subDays(30)->format('Y-m-d H:i:s'),
        };

        $query = $this->db->query("
            SELECT 
                category,
                action,
                COUNT(*) as count,
                MAX(created_at) as last_activity
            FROM `mas_audit_log`
            WHERE user_id = ? AND created_at >= ?
            GROUP BY category, action
            ORDER BY count DESC
        ", [$userId, $dateFrom]);

        return $query->rows;
    }

    /**
     * Calculates data changes between before and after arrays.
     *
     * @param array $before
     * @param array $after
     * @return array
     */
    protected function calculateChanges(array $before, array $after): array
    {
        $changes = [];

        // Find modified fields
        foreach ($after as $field => $newValue) {
            if (!array_key_exists($field, $before) || $before[$field] !== $newValue) {
                $changes[$field] = [
                    'old' => $before[$field] ?? null,
                    'new' => $newValue,
                ];
            }
        }

        // Find deleted fields
        foreach ($before as $field => $oldValue) {
            if (!array_key_exists($field, $after)) {
                $changes[$field] = [
                    'old' => $oldValue,
                    'new' => null,
                ];
            }
        }

        return $changes;
    }

    /**
     * Sanitizes sensitive payment data.
     *
     * @param array $context
     * @return array
     */
    protected function sanitizePaymentData(array $context): array
    {
        $sensitiveFields = ['card_number', 'cvv', 'password', 'token', 'secret'];

        foreach ($sensitiveFields as $field) {
            if (isset($context[$field])) {
                $context[$field] = $this->maskSensitiveData($context[$field]);
            }
        }

        return $context;
    }

    /**
     * Masks sensitive data.
     *
     * @param string $data
     * @return string
     */
    protected function maskSensitiveData(string $data): string
    {
        if (strlen($data) <= 4) {
            return str_repeat('*', strlen($data));
        }

        return substr($data, 0, 2) . str_repeat('*', strlen($data) - 4) . substr($data, -2);
    }

    /**
     * Triggers alert for critical events.
     *
     * @param array $eventData
     * @return void
     */
    protected function triggerAlert(array $eventData): void
    {
        // Emit alert event
        $this->emitEvent('audit.alert', $eventData);

        // Log to system log for immediate attention
        $this->log->write('MAS ALERT: ' . json_encode([
            'category' => $eventData['category'],
            'action' => $eventData['action'],
            'severity' => $eventData['severity'],
            'user_id' => $eventData['user_id'],
            'ip_address' => $eventData['ip_address'],
        ]));
    }

    /**
     * Emits audit event.
     *
     * @param array $eventData
     * @return void
     */
    protected function emitAuditEvent(array $eventData): void
    {
        $this->emitEvent('audit.logged', $eventData);
    }

    /**
     * Emits an event.
     *
     * @param string $eventName
     * @param array $payload
     * @return void
     */
    protected function emitEvent(string $eventName, array $payload): void
    {
        if ($this->container->has('mas.event_dispatcher')) {
            $this->container->get('mas.event_dispatcher')->dispatch(new Event($eventName, $payload));
        }
    }

    /**
     * Gets current user ID.
     *
     * @return int|null
     */
    protected function getCurrentUserId(): ?int
    {
        if ($this->session->data['user_id'] ?? null) {
            return (int)$this->session->data['user_id'];
        }

        return null;
    }

    /**
     * Gets current customer ID.
     *
     * @return int|null
     */
    protected function getCurrentCustomerId(): ?int
    {
        if ($this->session->data['customer_id'] ?? null) {
            return (int)$this->session->data['customer_id'];
        }

        return null;
    }

    /**
     * Gets client IP address.
     *
     * @return string
     */
    protected function getClientIp(): string
    {
        $headers = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Generates unique event ID.
     *
     * @return string
     */
    protected function generateEventId(): string
    {
        return 'audit_' . uniqid() . '_' . time();
    }

    /**
     * Enables audit logging.
     *
     * @return void
     */
    public function enable(): void
    {
        $this->enabled = true;
        $this->logSystem('audit_enabled');
    }

    /**
     * Disables audit logging.
     *
     * @return void
     */
    public function disable(): void
    {
        $this->logSystem('audit_disabled');
        $this->enabled = false;
    }

    /**
     * Checks if audit logging is enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Gets supported categories.
     *
     * @return array
     */
    public function getCategories(): array
    {
        return $this->categories;
    }

    /**
     * Gets severity levels.
     *
     * @return array
     */
    public function getSeverityLevels(): array
    {
        return $this->severityLevels;
    }

    /**
     * Sets retention period.
     *
     * @param int $days
     * @return void
     */
    public function setRetentionPeriod(int $days): void
    {
        $this->retentionDays = $days;
        $this->logSystem('retention_period_changed', ['days' => $days]);
    }

    /**
     * Adds custom category.
     *
     * @param string $code
     * @param string $name
     * @return void
     */
    public function addCategory(string $code, string $name): void
    {
        $this->categories[$code] = $name;
    }

    /**
     * Adds alert event.
     *
     * @param string $eventKey
     * @return void
     */
    public function addAlertEvent(string $eventKey): void
    {
        if (!in_array($eventKey, $this->alertEvents)) {
            $this->alertEvents[] = $eventKey;
        }
    }

    /**
     * Removes alert event.
     *
     * @param string $eventKey
     * @return void
     */
    public function removeAlertEvent(string $eventKey): void
    {
        $this->alertEvents = array_filter($this->alertEvents, function($event) use ($eventKey) {
            return $event !== $eventKey;
        });
    }
}
