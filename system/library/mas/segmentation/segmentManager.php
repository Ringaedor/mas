<?php
/**
 * MAS - Marketing Automation Suite
 * SegmentManager
 *
 * Manages customer segmentation with support for multiple filter types,
 * segment materialization, caching, AI-powered suggestions, and analytics.
 * Handles RFM analysis, behavioral segmentation, predictive modeling,
 * and real-time segment updates.
 *
 * @copyright Copyright (c) 2025, Your Company
 * @license   Proprietary
 */

namespace Opencart\Library\Mas\Segmentation;

use Opencart\Library\Mas\ServiceContainer;
use Opencart\Library\Mas\Interfaces\SegmentFilterInterface;
use Opencart\Library\Mas\Exception\SegmentException;
use Opencart\Library\Mas\Exception\ValidationException;
use Opencart\Library\Mas\Helper\ArrayHelper;
use Opencart\Library\Mas\Helper\DateHelper;
use Opencart\System\Engine\Registry;
use Opencart\System\Engine\Loader;
use Opencart\System\Engine\Log;
use Opencart\System\Library\Cache;
use Opencart\System\Library\DB;

class SegmentManager
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
     * @var Loader
     */
    protected Loader $loader;

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
     * @var array<string, SegmentFilterInterface> Registered filter types
     */
    protected array $filterTypes = [];

    /**
     * @var array<string, array> Loaded segment definitions
     */
    protected array $segments = [];

    /**
     * @var array<string, array> Materialized segment data
     */
    protected array $materializedSegments = [];

    /**
     * @var array Configuration settings
     */
    protected array $config = [];

    /**
     * @var int Cache TTL in minutes
     */
    protected int $cacheMinutes = 30;

    /**
     * @var int Batch size for materialization
     */
    protected int $batchSize = 1000;

    /**
     * @var int Maximum segment size
     */
    protected int $maxSegmentSize = 100000;

    /**
     * @var array<string, callable> Event listeners
     */
    protected array $eventListeners = [];

    /**
     * @var array Segment performance metrics
     */
    protected array $performanceMetrics = [];

    /**
     * Constructor.
     *
     * @param ServiceContainer $container
     */
    public function __construct(ServiceContainer $container)
    {
        $this->container = $container;
        $this->registry = $container->get('registry');
        $this->loader = $container->get('loader');
        $this->log = $container->get('log');
        $this->cache = $container->get('cache');
        $this->db = $this->registry->get('db');

        $this->loadConfiguration();
        $this->registerDefaultFilters();
        $this->initializeEventListeners();
    }

    /**
     * Creates a new segment.
     *
     * @param array $segmentData
     * @return array
     * @throws SegmentException
     */
    public function createSegment(array $segmentData): array
    {
        $this->validateSegmentData($segmentData);

        $segmentId = $this->generateSegmentId();
        $definition = $this->buildSegmentDefinition($segmentData);

        // Validate segment filters
        $this->validateSegmentFilters($definition['filters']);

        // Save segment to database
        $this->saveSegment($segmentId, $definition);

        // Cache segment
        $this->cacheSegment($segmentId, $definition);

        // Materialize segment if requested
        if ($segmentData['materialize'] ?? false) {
            $this->materializeSegment($segmentId);
        }

        $this->log->write('MAS: Segment created - ID: ' . $segmentId);

        return [
            'segment_id' => $segmentId,
            'name' => $definition['name'],
            'type' => $definition['type'],
            'status' => 'active',
            'created_at' => DateHelper::now(),
            'filter_count' => count($definition['filters']),
        ];
    }

    /**
     * Updates an existing segment.
     *
     * @param string $segmentId
     * @param array $segmentData
     * @return array
     * @throws SegmentException
     */
    public function updateSegment(string $segmentId, array $segmentData): array
    {
        if (!$this->segmentExists($segmentId)) {
            throw new SegmentException("Segment not found: {$segmentId}", 0);
        }

        $this->validateSegmentData($segmentData);

        $definition = $this->buildSegmentDefinition($segmentData);
        $definition['updated_at'] = DateHelper::now();

        // Validate segment filters
        $this->validateSegmentFilters($definition['filters']);

        // Update segment in database
        $this->saveSegment($segmentId, $definition);

        // Update cache
        $this->cacheSegment($segmentId, $definition);

        // Invalidate materialized segment
        $this->invalidateMaterializedSegment($segmentId);

        // Re-materialize if auto-materialize is enabled
        if ($definition['auto_materialize'] ?? false) {
            $this->materializeSegment($segmentId);
        }

        $this->log->write('MAS: Segment updated - ID: ' . $segmentId);

        return [
            'segment_id' => $segmentId,
            'name' => $definition['name'],
            'type' => $definition['type'],
            'status' => $definition['status'],
            'updated_at' => $definition['updated_at'],
            'filter_count' => count($definition['filters']),
        ];
    }

    /**
     * Deletes a segment.
     *
     * @param string $segmentId
     * @return bool
     * @throws SegmentException
     */
    public function deleteSegment(string $segmentId): bool
    {
        if (!$this->segmentExists($segmentId)) {
            throw new SegmentException("Segment not found: {$segmentId}", 0);
        }

        // Check if segment is used in active workflows
        if ($this->isSegmentInUse($segmentId)) {
            throw new SegmentException("Cannot delete segment in use: {$segmentId}", 0);
        }

        // Delete from database
        $this->db->query("DELETE FROM `mas_segment` WHERE `segment_id` = '" . $this->db->escape($segmentId) . "'");
        $this->db->query("DELETE FROM `mas_segment_materialized` WHERE `segment_id` = '" . $this->db->escape($segmentId) . "'");
        $this->db->query("DELETE FROM `mas_segment_analytics` WHERE `segment_id` = '" . $this->db->escape($segmentId) . "'");

        // Remove from cache
        $this->cache->delete('mas_segment_' . $segmentId);
        $this->cache->delete('mas_segment_materialized_' . $segmentId);

        // Remove from memory
        unset($this->segments[$segmentId]);
        unset($this->materializedSegments[$segmentId]);

        $this->log->write('MAS: Segment deleted - ID: ' . $segmentId);

        return true;
    }

    /**
     * Applies segment filters and returns matching customer IDs.
     *
     * @param string $segmentId
     * @param bool $forceRefresh
     * @return array
     * @throws SegmentException
     */
    public function applySegment(string $segmentId, bool $forceRefresh = false): array
    {
        $segment = $this->getSegment($segmentId);
        if (!$segment) {
            throw new SegmentException("Segment not found: {$segmentId}", 0);
        }

        // Check if materialized segment exists and is not expired
        if (!$forceRefresh && $this->hasMaterializedSegment($segmentId)) {
            return $this->getMaterializedSegment($segmentId);
        }

        $startTime = microtime(true);

        // Apply filters
        $customerIds = $this->executeSegmentFilters($segment['filters']);

        // Apply segment logic (AND/OR)
        $finalCustomerIds = $this->combineFilterResults($customerIds, $segment['logic'] ?? 'AND');

        // Apply size limits
        if (count($finalCustomerIds) > $this->maxSegmentSize) {
            $finalCustomerIds = array_slice($finalCustomerIds, 0, $this->maxSegmentSize);
        }

        $executionTime = microtime(true) - $startTime;

        // Update performance metrics
        $this->updateSegmentMetrics($segmentId, count($finalCustomerIds), $executionTime);

        // Cache results if segment is large enough
        if (count($finalCustomerIds) > 100) {
            $this->cacheMaterializedSegment($segmentId, $finalCustomerIds);
        }

        $this->log->write('MAS: Segment applied - ID: ' . $segmentId . ', Results: ' . count($finalCustomerIds));

        return $finalCustomerIds;
    }

    /**
     * Materializes a segment (pre-calculates and stores results).
     *
     * @param string $segmentId
     * @return array
     * @throws SegmentException
     */
    public function materializeSegment(string $segmentId): array
    {
        $segment = $this->getSegment($segmentId);
        if (!$segment) {
            throw new SegmentException("Segment not found: {$segmentId}", 0);
        }

        $startTime = microtime(true);

        // Apply segment filters
        $customerIds = $this->applySegment($segmentId, true);

        // Save materialized results
        $this->saveMaterializedSegment($segmentId, $customerIds);

        $executionTime = microtime(true) - $startTime;

        $this->log->write('MAS: Segment materialized - ID: ' . $segmentId . ', Count: ' . count($customerIds));

        return [
            'segment_id' => $segmentId,
            'customer_count' => count($customerIds),
            'materialized_at' => DateHelper::now(),
            'execution_time' => $executionTime,
        ];
    }

    /**
     * Gets segment by ID.
     *
     * @param string $segmentId
     * @return array|null
     */
    public function getSegment(string $segmentId): ?array
    {
        // Check cache first
        $cached = $this->cache->get('mas_segment_' . $segmentId);
        if ($cached) {
            return $cached;
        }

        // Load from database
        $query = $this->db->query("
            SELECT * FROM `mas_segment`
            WHERE `segment_id` = '" . $this->db->escape($segmentId) . "'
        ");

        if ($query->num_rows) {
            $row = $query->row;
            $segment = [
                'segment_id' => $row['segment_id'],
                'name' => $row['name'],
                'description' => $row['description'],
                'type' => $row['type'],
                'status' => $row['status'],
                'filters' => json_decode($row['filters'], true),
                'logic' => $row['logic'],
                'auto_materialize' => $row['auto_materialize'],
                'materialization_schedule' => $row['materialization_schedule'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ];

            // Cache segment
            $this->cache->set('mas_segment_' . $segmentId, $segment, $this->cacheMinutes * 60);

            return $segment;
        }

        return null;
    }

    /**
     * Gets all segments.
     *
     * @param array $filters
     * @return array
     */
    public function getSegments(array $filters = []): array
    {
        $sql = "SELECT * FROM `mas_segment`";
        $conditions = [];

        if (!empty($filters['status'])) {
            $conditions[] = "`status` = '" . $this->db->escape($filters['status']) . "'";
        }

        if (!empty($filters['type'])) {
            $conditions[] = "`type` = '" . $this->db->escape($filters['type']) . "'";
        }

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }

        $sql .= " ORDER BY `created_at` DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }

        $query = $this->db->query($sql);
        $segments = [];

        foreach ($query->rows as $row) {
            $segments[] = [
                'segment_id' => $row['segment_id'],
                'name' => $row['name'],
                'description' => $row['description'],
                'type' => $row['type'],
                'status' => $row['status'],
                'filter_count' => count(json_decode($row['filters'], true)),
                'auto_materialize' => $row['auto_materialize'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ];
        }

        return $segments;
    }

    /**
     * Gets segment statistics.
     *
     * @param string $segmentId
     * @return array
     */
    public function getSegmentStatistics(string $segmentId): array
    {
        $query = $this->db->query("
            SELECT 
                COUNT(*) as customer_count,
                AVG(CASE WHEN c.status = 1 THEN 1 ELSE 0 END) as active_percentage,
                AVG(DATEDIFF(NOW(), c.date_added)) as avg_customer_age_days
            FROM `mas_segment_materialized` sm
            LEFT JOIN `customer` c ON sm.customer_id = c.customer_id
            WHERE sm.segment_id = '" . $this->db->escape($segmentId) . "'
        ");

        $stats = $query->row;

        // Get analytics data
        $analyticsQuery = $this->db->query("
            SELECT 
                SUM(email_sent) as total_emails_sent,
                SUM(email_opened) as total_emails_opened,
                SUM(email_clicked) as total_emails_clicked,
                SUM(conversions) as total_conversions,
                SUM(revenue) as total_revenue
            FROM `mas_segment_analytics`
            WHERE `segment_id` = '" . $this->db->escape($segmentId) . "'
        ");

        $analytics = $analyticsQuery->row;

        return [
            'customer_count' => (int)$stats['customer_count'],
            'active_percentage' => round($stats['active_percentage'] * 100, 2),
            'avg_customer_age_days' => (int)$stats['avg_customer_age_days'],
            'total_emails_sent' => (int)$analytics['total_emails_sent'],
            'total_emails_opened' => (int)$analytics['total_emails_opened'],
            'total_emails_clicked' => (int)$analytics['total_emails_clicked'],
            'total_conversions' => (int)$analytics['total_conversions'],
            'total_revenue' => (float)$analytics['total_revenue'],
            'open_rate' => $analytics['total_emails_sent'] > 0 ? 
                round(($analytics['total_emails_opened'] / $analytics['total_emails_sent']) * 100, 2) : 0,
            'click_rate' => $analytics['total_emails_sent'] > 0 ? 
                round(($analytics['total_emails_clicked'] / $analytics['total_emails_sent']) * 100, 2) : 0,
            'conversion_rate' => $analytics['total_emails_sent'] > 0 ? 
                round(($analytics['total_conversions'] / $analytics['total_emails_sent']) * 100, 2) : 0,
        ];
    }

    /**
     * Gets customers in a segment.
     *
     * @param string $segmentId
     * @param array $options
     * @return array
     */
    public function getSegmentCustomers(string $segmentId, array $options = []): array
    {
        $limit = $options['limit'] ?? 100;
        $offset = $options['offset'] ?? 0;

        $query = $this->db->query("
            SELECT c.customer_id, c.firstname, c.lastname, c.email, c.telephone, c.date_added
            FROM `mas_segment_materialized` sm
            JOIN `customer` c ON sm.customer_id = c.customer_id
            WHERE sm.segment_id = '" . $this->db->escape($segmentId) . "'
            ORDER BY c.date_added DESC
            LIMIT " . (int)$offset . ", " . (int)$limit
        );

        return $query->rows;
    }

    /**
     * Checks if a customer is in a segment.
     *
     * @param string $segmentId
     * @param int $customerId
     * @return bool
     */
    public function isCustomerInSegment(string $segmentId, int $customerId): bool
    {
        $query = $this->db->query("
            SELECT COUNT(*) as count
            FROM `mas_segment_materialized`
            WHERE `segment_id` = '" . $this->db->escape($segmentId) . "'
            AND `customer_id` = '" . (int)$customerId . "'
        ");

        return $query->row['count'] > 0;
    }

    /**
     * Adds a customer to a segment.
     *
     * @param string $segmentId
     * @param int $customerId
     * @return bool
     */
    public function addCustomerToSegment(string $segmentId, int $customerId): bool
    {
        if ($this->isCustomerInSegment($segmentId, $customerId)) {
            return true; // Already in segment
        }

        $this->db->query("
            INSERT INTO `mas_segment_materialized` SET
            `segment_id` = '" . $this->db->escape($segmentId) . "',
            `customer_id` = '" . (int)$customerId . "',
            `added_at` = NOW()
        ");

        // Invalidate cache
        $this->cache->delete('mas_segment_materialized_' . $segmentId);

        return true;
    }

    /**
     * Removes a customer from a segment.
     *
     * @param string $segmentId
     * @param int $customerId
     * @return bool
     */
    public function removeCustomerFromSegment(string $segmentId, int $customerId): bool
    {
        $this->db->query("
            DELETE FROM `mas_segment_materialized`
            WHERE `segment_id` = '" . $this->db->escape($segmentId) . "'
            AND `customer_id` = '" . (int)$customerId . "'
        ");

        // Invalidate cache
        $this->cache->delete('mas_segment_materialized_' . $segmentId);

        return $this->db->countAffected() > 0;
    }

    /**
     * Registers a filter type.
     *
     * @param string $type
     * @param string $className
     * @return void
     */
    public function registerFilter(string $type, string $className): void
    {
        if (!class_exists($className)) {
            throw new SegmentException("Filter class not found: {$className}");
        }

        if (!is_subclass_of($className, SegmentFilterInterface::class)) {
            throw new SegmentException("Filter class must implement SegmentFilterInterface: {$className}");
        }

        $this->filterTypes[$type] = $className;
    }

    /**
     * Creates a filter instance.
     *
     * @param string $type
     * @param array $config
     * @return SegmentFilterInterface
     * @throws SegmentException
     */
    public function createFilter(string $type, array $config = []): SegmentFilterInterface
    {
        if (!isset($this->filterTypes[$type])) {
            throw new SegmentException("Unknown filter type: {$type}");
        }

        $className = $this->filterTypes[$type];
        $filter = new $className();
        $filter->setConfig($config);

        return $filter;
    }

    /**
     * Gets available filter types.
     *
     * @return array
     */
    public function getAvailableFilters(): array
    {
        $filters = [];

        foreach ($this->filterTypes as $type => $className) {
            $filters[$type] = [
                'type' => $type,
                'label' => $className::getLabel(),
                'description' => $className::getDescription(),
                'schema' => $className::getConfigSchema(),
            ];
        }

        return $filters;
    }

    /**
     * Suggests segments based on AI analysis.
     *
     * @param array $context
     * @return array
     */
    public function suggestSegments(array $context = []): array
    {
        try {
            $suggestor = $this->container->get('mas.segment_suggestor');
            if (!$suggestor) {
                return [];
            }

            $suggestions = $suggestor->suggest($context);
            
            return $suggestions['suggestion'] ?? [];
        } catch (\Exception $e) {
            $this->log->write('MAS: Segment suggestion failed - ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Processes scheduled materializations.
     *
     * @return array
     */
    public function processScheduledMaterializations(): array
    {
        $query = $this->db->query("
            SELECT * FROM `mas_segment`
            WHERE `auto_materialize` = 1
            AND `status` = 'active'
            AND (`last_materialized` IS NULL OR 
                 `last_materialized` < DATE_SUB(NOW(), INTERVAL 1 HOUR))
            ORDER BY `priority` DESC, `created_at` ASC
            LIMIT 50
        ");

        $processed = [];

        foreach ($query->rows as $row) {
            try {
                $result = $this->materializeSegment($row['segment_id']);
                $processed[] = $result;

                // Update last materialized timestamp
                $this->db->query("
                    UPDATE `mas_segment`
                    SET `last_materialized` = NOW()
                    WHERE `segment_id` = '" . $this->db->escape($row['segment_id']) . "'
                ");

            } catch (\Exception $e) {
                $this->log->write('MAS: Scheduled materialization failed - Segment: ' . $row['segment_id'] . ', Error: ' . $e->getMessage());
                
                $processed[] = [
                    'segment_id' => $row['segment_id'],
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $processed;
    }

    /**
     * Analyzes segment performance.
     *
     * @param string $segmentId
     * @param int $days
     * @return array
     */
    public function analyzeSegmentPerformance(string $segmentId, int $days = 30): array
    {
        $startDate = DateHelper::nowObject()->modify("-{$days} days")->format('Y-m-d H:i:s');

        $query = $this->db->query("
            SELECT 
                DATE(created_at) as date,
                SUM(email_sent) as emails_sent,
                SUM(email_opened) as emails_opened,
                SUM(email_clicked) as emails_clicked,
                SUM(conversions) as conversions,
                SUM(revenue) as revenue
            FROM `mas_segment_analytics`
            WHERE `segment_id` = '" . $this->db->escape($segmentId) . "'
            AND `created_at` >= '" . $this->db->escape($startDate) . "'
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");

        $performance = [];
        $totalSent = 0;
        $totalOpened = 0;
        $totalClicked = 0;
        $totalConversions = 0;
        $totalRevenue = 0;

        foreach ($query->rows as $row) {
            $performance[] = [
                'date' => $row['date'],
                'emails_sent' => (int)$row['emails_sent'],
                'emails_opened' => (int)$row['emails_opened'],
                'emails_clicked' => (int)$row['emails_clicked'],
                'conversions' => (int)$row['conversions'],
                'revenue' => (float)$row['revenue'],
                'open_rate' => $row['emails_sent'] > 0 ? 
                    round(($row['emails_opened'] / $row['emails_sent']) * 100, 2) : 0,
                'click_rate' => $row['emails_sent'] > 0 ? 
                    round(($row['emails_clicked'] / $row['emails_sent']) * 100, 2) : 0,
                'conversion_rate' => $row['emails_sent'] > 0 ? 
                    round(($row['conversions'] / $row['emails_sent']) * 100, 2) : 0,
            ];

            $totalSent += $row['emails_sent'];
            $totalOpened += $row['emails_opened'];
            $totalClicked += $row['emails_clicked'];
            $totalConversions += $row['conversions'];
            $totalRevenue += $row['revenue'];
        }

        return [
            'daily_performance' => $performance,
            'summary' => [
                'total_emails_sent' => $totalSent,
                'total_emails_opened' => $totalOpened,
                'total_emails_clicked' => $totalClicked,
                'total_conversions' => $totalConversions,
                'total_revenue' => $totalRevenue,
                'avg_open_rate' => $totalSent > 0 ? round(($totalOpened / $totalSent) * 100, 2) : 0,
                'avg_click_rate' => $totalSent > 0 ? round(($totalClicked / $totalSent) * 100, 2) : 0,
                'avg_conversion_rate' => $totalSent > 0 ? round(($totalConversions / $totalSent) * 100, 2) : 0,
            ],
        ];
    }

    /**
     * Exports segment data.
     *
     * @param string $segmentId
     * @param string $format
     * @return array
     */
    public function exportSegment(string $segmentId, string $format = 'csv'): array
    {
        $customers = $this->getSegmentCustomers($segmentId, ['limit' => 10000]);
        
        switch ($format) {
            case 'csv':
                return $this->exportToCsv($customers);
            case 'json':
                return $this->exportToJson($customers);
            default:
                throw new SegmentException("Unsupported export format: {$format}");
        }
    }

    /**
     * Compares two segments.
     *
     * @param string $segmentId1
     * @param string $segmentId2
     * @return array
     */
    public function compareSegments(string $segmentId1, string $segmentId2): array
    {
        $segment1Customers = $this->applySegment($segmentId1);
        $segment2Customers = $this->applySegment($segmentId2);

        $intersection = array_intersect($segment1Customers, $segment2Customers);
        $union = array_unique(array_merge($segment1Customers, $segment2Customers));
        $segment1Only = array_diff($segment1Customers, $segment2Customers);
        $segment2Only = array_diff($segment2Customers, $segment1Customers);

        return [
            'segment1_count' => count($segment1Customers),
            'segment2_count' => count($segment2Customers),
            'intersection_count' => count($intersection),
            'union_count' => count($union),
            'segment1_only_count' => count($segment1Only),
            'segment2_only_count' => count($segment2Only),
            'overlap_percentage' => count($union) > 0 ? 
                round((count($intersection) / count($union)) * 100, 2) : 0,
            'intersection_customers' => array_slice($intersection, 0, 100),
            'segment1_only_customers' => array_slice($segment1Only, 0, 100),
            'segment2_only_customers' => array_slice($segment2Only, 0, 100),
        ];
    }

    /**
     * Validates segment data.
     *
     * @param array $segmentData
     * @return void
     * @throws ValidationException
     */
    protected function validateSegmentData(array $segmentData): void
    {
        $errors = [];

        if (empty($segmentData['name'])) {
            $errors['name'] = 'Segment name is required';
        }

        if (empty($segmentData['type'])) {
            $errors['type'] = 'Segment type is required';
        }

        if (empty($segmentData['filters']) || !is_array($segmentData['filters'])) {
            $errors['filters'] = 'Segment must have at least one filter';
        }

        if (!empty($errors)) {
            throw new ValidationException('Segment validation failed', $errors);
        }
    }

    /**
     * Validates segment filters.
     *
     * @param array $filters
     * @return void
     * @throws SegmentException
     */
    protected function validateSegmentFilters(array $filters): void
    {
        foreach ($filters as $filter) {
            if (empty($filter['type'])) {
                throw new SegmentException('Filter type is required');
            }

            if (!isset($this->filterTypes[$filter['type']])) {
                throw new SegmentException("Unknown filter type: {$filter['type']}");
            }

            $filterInstance = $this->createFilter($filter['type'], $filter['config'] ?? []);
            if (!$filterInstance->validate()) {
                throw new SegmentException("Filter validation failed for type: {$filter['type']}");
            }
        }
    }

    /**
     * Builds segment definition from input data.
     *
     * @param array $segmentData
     * @return array
     */
    protected function buildSegmentDefinition(array $segmentData): array
    {
        return [
            'name' => $segmentData['name'],
            'description' => $segmentData['description'] ?? '',
            'type' => $segmentData['type'],
            'status' => $segmentData['status'] ?? 'active',
            'filters' => $segmentData['filters'],
            'logic' => $segmentData['logic'] ?? 'AND',
            'auto_materialize' => $segmentData['auto_materialize'] ?? false,
            'materialization_schedule' => $segmentData['materialization_schedule'] ?? null,
            'priority' => $segmentData['priority'] ?? 0,
            'created_at' => DateHelper::now(),
            'updated_at' => DateHelper::now(),
        ];
    }

    /**
     * Executes segment filters.
     *
     * @param array $filters
     * @return array
     */
    protected function executeSegmentFilters(array $filters): array
    {
        $results = [];

        foreach ($filters as $filter) {
            $filterInstance = $this->createFilter($filter['type'], $filter['config'] ?? []);
            $customerIds = $filterInstance->apply(['db' => $this->db, 'container' => $this->container]);
            $results[] = $customerIds;
        }

        return $results;
    }

    /**
     * Combines filter results based on logic.
     *
     * @param array $results
     * @param string $logic
     * @return array
     */
    protected function combineFilterResults(array $results, string $logic): array
    {
        if (empty($results)) {
            return [];
        }

        if (count($results) === 1) {
            return $results[0];
        }

        if ($logic === 'OR') {
            return array_unique(array_merge(...$results));
        }

        // Default to AND logic
        return array_intersect(...$results);
    }

    /**
     * Saves segment to database.
     *
     * @param string $segmentId
     * @param array $definition
     * @return void
     */
    protected function saveSegment(string $segmentId, array $definition): void
    {
        $this->db->query("
            INSERT INTO `mas_segment` SET
            `segment_id` = '" . $this->db->escape($segmentId) . "',
            `name` = '" . $this->db->escape($definition['name']) . "',
            `description` = '" . $this->db->escape($definition['description']) . "',
            `type` = '" . $this->db->escape($definition['type']) . "',
            `status` = '" . $this->db->escape($definition['status']) . "',
            `filters` = '" . $this->db->escape(json_encode($definition['filters'])) . "',
            `logic` = '" . $this->db->escape($definition['logic']) . "',
            `auto_materialize` = '" . (int)$definition['auto_materialize'] . "',
            `materialization_schedule` = '" . $this->db->escape($definition['materialization_schedule']) . "',
            `priority` = '" . (int)$definition['priority'] . "',
            `created_at` = NOW(),
            `updated_at` = NOW()
            ON DUPLICATE KEY UPDATE
            `name` = VALUES(`name`),
            `description` = VALUES(`description`),
            `type` = VALUES(`type`),
            `status` = VALUES(`status`),
            `filters` = VALUES(`filters`),
            `logic` = VALUES(`logic`),
            `auto_materialize` = VALUES(`auto_materialize`),
            `materialization_schedule` = VALUES(`materialization_schedule`),
            `priority` = VALUES(`priority`),
            `updated_at` = NOW()
        ");
    }

    /**
     * Caches segment definition.
     *
     * @param string $segmentId
     * @param array $definition
     * @return void
     */
    protected function cacheSegment(string $segmentId, array $definition): void
    {
        $this->cache->set('mas_segment_' . $segmentId, $definition, $this->cacheMinutes * 60);
    }

    /**
     * Saves materialized segment.
     *
     * @param string $segmentId
     * @param array $customerIds
     * @return void
     */
    protected function saveMaterializedSegment(string $segmentId, array $customerIds): void
    {
        // Clear existing materialized data
        $this->db->query("DELETE FROM `mas_segment_materialized` WHERE `segment_id` = '" . $this->db->escape($segmentId) . "'");

        // Insert new data in batches
        $chunks = array_chunk($customerIds, $this->batchSize);
        foreach ($chunks as $chunk) {
            $values = [];
            foreach ($chunk as $customerId) {
                $values[] = "('" . $this->db->escape($segmentId) . "', '" . (int)$customerId . "', NOW())";
            }

            if (!empty($values)) {
                $this->db->query("
                    INSERT INTO `mas_segment_materialized` (`segment_id`, `customer_id`, `added_at`)
                    VALUES " . implode(', ', $values)
                );
            }
        }
    }

    /**
     * Caches materialized segment.
     *
     * @param string $segmentId
     * @param array $customerIds
     * @return void
     */
    protected function cacheMaterializedSegment(string $segmentId, array $customerIds): void
    {
        $this->cache->set('mas_segment_materialized_' . $segmentId, $customerIds, $this->cacheMinutes * 60);
    }

    /**
     * Gets materialized segment.
     *
     * @param string $segmentId
     * @return array
     */
    protected function getMaterializedSegment(string $segmentId): array
    {
        // Check cache first
        $cached = $this->cache->get('mas_segment_materialized_' . $segmentId);
        if ($cached) {
            return $cached;
        }

        // Load from database
        $query = $this->db->query("
            SELECT customer_id FROM `mas_segment_materialized`
            WHERE `segment_id` = '" . $this->db->escape($segmentId) . "'
        ");

        $customerIds = array_column($query->rows, 'customer_id');

        // Cache results
        $this->cache->set('mas_segment_materialized_' . $segmentId, $customerIds, $this->cacheMinutes * 60);

        return $customerIds;
    }

    /**
     * Checks if materialized segment exists.
     *
     * @param string $segmentId
     * @return bool
     */
    protected function hasMaterializedSegment(string $segmentId): bool
    {
        $query = $this->db->query("
            SELECT COUNT(*) as count FROM `mas_segment_materialized`
            WHERE `segment_id` = '" . $this->db->escape($segmentId) . "'
        ");

        return $query->row['count'] > 0;
    }

    /**
     * Invalidates materialized segment.
     *
     * @param string $segmentId
     * @return void
     */
    protected function invalidateMaterializedSegment(string $segmentId): void
    {
        $this->db->query("DELETE FROM `mas_segment_materialized` WHERE `segment_id` = '" . $this->db->escape($segmentId) . "'");
        $this->cache->delete('mas_segment_materialized_' . $segmentId);
    }

    /**
     * Checks if segment exists.
     *
     * @param string $segmentId
     * @return bool
     */
    protected function segmentExists(string $segmentId): bool
    {
        $query = $this->db->query("
            SELECT COUNT(*) as count FROM `mas_segment`
            WHERE `segment_id` = '" . $this->db->escape($segmentId) . "'
        ");

        return $query->row['count'] > 0;
    }

    /**
     * Checks if segment is in use.
     *
     * @param string $segmentId
     * @return bool
     */
    protected function isSegmentInUse(string $segmentId): bool
    {
        $query = $this->db->query("
            SELECT COUNT(*) as count FROM `mas_workflow`
            WHERE JSON_EXTRACT(`definition`, '$.filters[*].segment_id') = '" . $this->db->escape($segmentId) . "'
            AND `status` = 'active'
        ");

        return $query->row['count'] > 0;
    }

    /**
     * Updates segment metrics.
     *
     * @param string $segmentId
     * @param int $customerCount
     * @param float $executionTime
     * @return void
     */
    protected function updateSegmentMetrics(string $segmentId, int $customerCount, float $executionTime): void
    {
        $this->performanceMetrics[$segmentId] = [
            'customer_count' => $customerCount,
            'execution_time' => $executionTime,
            'last_executed' => DateHelper::now(),
        ];
    }

    /**
     * Exports to CSV.
     *
     * @param array $customers
     * @return array
     */
    protected function exportToCsv(array $customers): array
    {
        $csv = "customer_id,firstname,lastname,email,telephone,date_added\n";
        
        foreach ($customers as $customer) {
            $csv .= implode(',', [
                $customer['customer_id'],
                '"' . str_replace('"', '""', $customer['firstname']) . '"',
                '"' . str_replace('"', '""', $customer['lastname']) . '"',
                '"' . str_replace('"', '""', $customer['email']) . '"',
                '"' . str_replace('"', '""', $customer['telephone']) . '"',
                $customer['date_added'],
            ]) . "\n";
        }

        return [
            'format' => 'csv',
            'content' => $csv,
            'filename' => 'segment_export_' . date('Y-m-d_H-i-s') . '.csv',
        ];
    }

    /**
     * Exports to JSON.
     *
     * @param array $customers
     * @return array
     */
    protected function exportToJson(array $customers): array
    {
        return [
            'format' => 'json',
            'content' => json_encode($customers, JSON_PRETTY_PRINT),
            'filename' => 'segment_export_' . date('Y-m-d_H-i-s') . '.json',
        ];
    }

    /**
     * Generates a unique segment ID.
     *
     * @return string
     */
    protected function generateSegmentId(): string
    {
        return 'seg_' . uniqid() . '_' . time();
    }

    /**
     * Loads configuration from MAS config.
     *
     * @return void
     */
    protected function loadConfiguration(): void
    {
        $config = $this->container->get('config');
        $masConfig = $config->get('mas_config') ?: [];

        $this->config = $masConfig;
        $this->cacheMinutes = $masConfig['segment']['cache_minutes'] ?? 30;
        $this->batchSize = $masConfig['segment']['batch_size'] ?? 1000;
        $this->maxSegmentSize = $masConfig['segment']['max_segment_size'] ?? 100000;
    }

    /**
     * Registers default filters.
     *
     * @return void
     */
    protected function registerDefaultFilters(): void
    {
        $this->registerFilter('rfm', 'Opencart\Library\Mas\Segmentation\Filter\RfmFilter');
        $this->registerFilter('behavioral', 'Opencart\Library\Mas\Segmentation\Filter\BehaviouralFilter');
        $this->registerFilter('predictive', 'Opencart\Library\Mas\Segmentation\Filter\PredictiveFilter');
        $this->registerFilter('demographic', 'Opencart\Library\Mas\Segmentation\Filter\DemographicFilter');
    }

    /**
     * Initializes event listeners.
     *
     * @return void
     */
    protected function initializeEventListeners(): void
    {
        $this->addEventListener('customer.updated', [$this, 'handleCustomerUpdated']);
        $this->addEventListener('order.complete', [$this, 'handleOrderComplete']);
        $this->addEventListener('customer.register', [$this, 'handleCustomerRegister']);
    }

    /**
     * Adds an event listener.
     *
     * @param string $event
     * @param callable $callback
     * @return void
     */
    protected function addEventListener(string $event, callable $callback): void
    {
        $this->eventListeners[$event][] = $callback;
    }

    /**
     * Handles customer updated event.
     *
     * @param array $data
     * @return void
     */
    public function handleCustomerUpdated(array $data): void
    {
        $this->invalidateSegmentsForCustomer($data['customer_id']);
    }

    /**
     * Handles order complete event.
     *
     * @param array $data
     * @return void
     */
    public function handleOrderComplete(array $data): void
    {
        $this->invalidateSegmentsForCustomer($data['customer_id']);
    }

    /**
     * Handles customer register event.
     *
     * @param array $data
     * @return void
     */
    public function handleCustomerRegister(array $data): void
    {
        $this->invalidateSegmentsForCustomer($data['customer_id']);
    }

    /**
     * Invalidates segments for a customer.
     *
     * @param int $customerId
     * @return void
     */
    protected function invalidateSegmentsForCustomer(int $customerId): void
    {
        // This would typically trigger re-evaluation of segments for this customer
        // For now, we'll just clear related caches
        $this->cache->delete('mas_customer_segments_' . $customerId);
    }

    /**
     * Cleans up old segment data.
     *
     * @param int $daysOld
     * @return int
     */
    public function cleanupOldSegmentData(int $daysOld = 90): int
    {
        $cutoffDate = DateHelper::nowObject()->modify("-{$daysOld} days")->format('Y-m-d H:i:s');
        
        $this->db->query("
            DELETE FROM `mas_segment_analytics`
            WHERE `created_at` < '" . $this->db->escape($cutoffDate) . "'
        ");

        return $this->db->countAffected();
    }

    /**
     * Gets segment performance metrics.
     *
     * @return array
     */
    public function getPerformanceMetrics(): array
    {
        return $this->performanceMetrics;
    }

    /**
     * Resets performance metrics.
     *
     * @return void
     */
    public function resetPerformanceMetrics(): void
    {
        $this->performanceMetrics = [];
    }
}
