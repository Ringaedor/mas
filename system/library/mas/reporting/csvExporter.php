<?php
/**
 * MAS - Marketing Automation Suite
 * CsvExporter
 *
 * Utility class for exporting MAS data to CSV format with customizable columns,
 * filters, formatting, and batch processing for large datasets.
 *
 * Features:
 * - Memory-efficient streaming for large exports
 * - Customizable column mapping and formatting
 * - Data filtering and sorting
 * - UTF-8 BOM support for Excel compatibility
 * - Progress tracking for long-running exports
 * - Event emission for audit logging
 *
 * @copyright Copyright (c) 2025, Your Company
 * @license   Proprietary
 */

namespace Opencart\Library\Mas\Reporting;

use Opencart\Library\Mas\ServiceContainer;
use Opencart\Library\Mas\Exception\ExportException;
use Opencart\Library\Mas\Helper\ArrayHelper;
use Opencart\Library\Mas\Helper\DateHelper;
use Opencart\Library\Mas\Events\Event;
use Opencart\System\Engine\Log;
use Opencart\System\Library\DB;

class CsvExporter
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
     * @var DB
     */
    protected DB $db;

    /**
     * @var array Export configuration
     */
    protected array $config = [];

    /**
     * @var resource File handle for streaming
     */
    protected $fileHandle = null;

    /**
     * @var string CSV delimiter
     */
    protected string $delimiter = ',';

    /**
     * @var string CSV enclosure
     */
    protected string $enclosure = '"';

    /**
     * @var string CSV escape character
     */
    protected string $escape = '"';

    /**
     * @var bool Add UTF-8 BOM for Excel
     */
    protected bool $addBom = true;

    /**
     * @var int Batch size for processing
     */
    protected int $batchSize = 1000;

    /**
     * @var int Progress counter
     */
    protected int $progressCounter = 0;

    /**
     * @var int Total rows to process
     */
    protected int $totalRows = 0;

    /**
     * @var array Column definitions
     */
    protected array $columns = [];

    /**
     * @var array Data formatters
     */
    protected array $formatters = [];

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
        $this->db = $container->get('db');

        $this->config = $config;
        $this->delimiter = $config['delimiter'] ?? $this->delimiter;
        $this->enclosure = $config['enclosure'] ?? $this->enclosure;
        $this->escape = $config['escape'] ?? $this->escape;
        $this->addBom = $config['add_bom'] ?? $this->addBom;
        $this->batchSize = $config['batch_size'] ?? $this->batchSize;

        $this->initializeFormatters();
    }

    /**
     * Exports customers data to CSV.
     *
     * @param string $filename
     * @param array $filters
     * @param array $columns
     * @return array
     */
    public function exportCustomers(string $filename, array $filters = [], array $columns = []): array
    {
        $this->columns = $columns ?: $this->getDefaultCustomerColumns();
        
        $sql = $this->buildCustomerQuery($filters);
        $countSql = $this->buildCustomerCountQuery($filters);
        
        return $this->processExport($filename, $sql, $countSql, 'customers', $filters);
    }

    /**
     * Exports orders data to CSV.
     *
     * @param string $filename
     * @param array $filters
     * @param array $columns
     * @return array
     */
    public function exportOrders(string $filename, array $filters = [], array $columns = []): array
    {
        $this->columns = $columns ?: $this->getDefaultOrderColumns();
        
        $sql = $this->buildOrderQuery($filters);
        $countSql = $this->buildOrderCountQuery($filters);
        
        return $this->processExport($filename, $sql, $countSql, 'orders', $filters);
    }

    /**
     * Exports segments data to CSV.
     *
     * @param string $filename
     * @param int $segmentId
     * @param array $filters
     * @param array $columns
     * @return array
     */
    public function exportSegment(string $filename, int $segmentId, array $filters = [], array $columns = []): array
    {
        $this->columns = $columns ?: $this->getDefaultSegmentColumns();
        
        $sql = $this->buildSegmentQuery($segmentId, $filters);
        $countSql = $this->buildSegmentCountQuery($segmentId, $filters);
        
        return $this->processExport($filename, $sql, $countSql, 'segment', $filters + ['segment_id' => $segmentId]);
    }

    /**
     * Exports workflow executions data to CSV.
     *
     * @param string $filename
     * @param array $filters
     * @param array $columns
     * @return array
     */
    public function exportWorkflowExecutions(string $filename, array $filters = [], array $columns = []): array
    {
        $this->columns = $columns ?: $this->getDefaultWorkflowExecutionColumns();
        
        $sql = $this->buildWorkflowExecutionQuery($filters);
        $countSql = $this->buildWorkflowExecutionCountQuery($filters);
        
        return $this->processExport($filename, $sql, $countSql, 'workflow_executions', $filters);
    }

    /**
     * Exports custom data to CSV.
     *
     * @param string $filename
     * @param string $sql
     * @param array $columns
     * @param string $exportType
     * @return array
     */
    public function exportCustomData(string $filename, string $sql, array $columns, string $exportType = 'custom'): array
    {
        $this->columns = $columns;
        
        // For custom SQL, we'll estimate count by running the query first
        $countSql = "SELECT COUNT(*) as count FROM ({$sql}) as temp_table";
        
        return $this->processExport($filename, $sql, $countSql, $exportType, []);
    }

    /**
     * Processes the export with progress tracking.
     *
     * @param string $filename
     * @param string $sql
     * @param string $countSql
     * @param string $exportType
     * @param array $filters
     * @return array
     */
    protected function processExport(string $filename, string $sql, string $countSql, string $exportType, array $filters): array
    {
        $startTime = microtime(true);
        
        try {
            // Get total count
            $countResult = $this->db->query($countSql);
            $this->totalRows = (int)$countResult->row['count'];
            
            // Initialize file
            $this->initializeFile($filename);
            
            // Write headers
            $this->writeHeaders();
            
            // Process data in batches
            $offset = 0;
            $this->progressCounter = 0;
            
            while ($offset < $this->totalRows) {
                $batchSql = $sql . " LIMIT {$this->batchSize} OFFSET {$offset}";
                $batchResult = $this->db->query($batchSql);
                
                foreach ($batchResult->rows as $row) {
                    $this->writeDataRow($row);
                    $this->progressCounter++;
                    
                    // Emit progress event every 100 rows
                    if ($this->progressCounter % 100 === 0) {
                        $this->emitProgressEvent($exportType, $this->progressCounter, $this->totalRows);
                    }
                }
                
                $offset += $this->batchSize;
            }
            
            // Close file
            $this->closeFile();
            
            $executionTime = microtime(true) - $startTime;
            $fileSize = filesize($filename);
            
            // Emit completion event
            $this->emitCompletionEvent($exportType, $filename, $this->totalRows, $executionTime, $fileSize, $filters);
            
            if ($this->container->has('mas.audit_logger')) {
                $this->container->get('mas.audit_logger')->logSystem('csv_export_completed', [
                    'export_type' => $exportType,
                    'filename' => $filename,
                    'rows_exported' => $this->totalRows,
                    'execution_time' => $executionTime
                ]);
            }
            
            return [
                'success' => true,
                'filename' => $filename,
                'rows_exported' => $this->totalRows,
                'execution_time' => round($executionTime, 2),
                'file_size' => $fileSize,
            ];
            
        } catch (\Exception $e) {
            if ($this->fileHandle) {
                $this->closeFile();
            }
            
            $this->log->write('MAS CsvExporter: Export failed - ' . $e->getMessage());
            
            throw new ExportException('CSV export failed: ' . $e->getMessage(), 0, [], $e);
        }
    }

    /**
     * Initializes the CSV file for writing.
     *
     * @param string $filename
     * @return void
     */
    protected function initializeFile(string $filename): void
    {
        $this->fileHandle = fopen($filename, 'w');
        
        if (!$this->fileHandle) {
            throw new ExportException("Cannot open file for writing: {$filename}");
        }
        
        // Add BOM for Excel compatibility
        if ($this->addBom) {
            fwrite($this->fileHandle, "\xEF\xBB\xBF");
        }
    }

    /**
     * Writes CSV headers.
     *
     * @return void
     */
    protected function writeHeaders(): void
    {
        $headers = array_column($this->columns, 'label');
        $this->writeCsvLine($headers);
    }

    /**
     * Writes a data row to CSV.
     *
     * @param array $row
     * @return void
     */
    protected function writeDataRow(array $row): void
    {
        $csvRow = [];
        
        foreach ($this->columns as $column) {
            $field = $column['field'];
            $value = $row[$field] ?? '';
            
            // Apply formatter if specified
            if (isset($column['formatter']) && isset($this->formatters[$column['formatter']])) {
                $value = $this->formatters[$column['formatter']]($value, $row);
            }
            
            $csvRow[] = $value;
        }
        
        $this->writeCsvLine($csvRow);
    }

    /**
     * Writes a line to CSV file.
     *
     * @param array $data
     * @return void
     */
    protected function writeCsvLine(array $data): void
    {
        fputcsv($this->fileHandle, $data, $this->delimiter, $this->enclosure, $this->escape);
    }

    /**
     * Closes the CSV file.
     *
     * @return void
     */
    protected function closeFile(): void
    {
        if ($this->fileHandle) {
            fclose($this->fileHandle);
            $this->fileHandle = null;
        }
    }

    /**
     * Builds customer query with filters.
     *
     * @param array $filters
     * @return string
     */
    protected function buildCustomerQuery(array $filters): string
    {
        $sql = "
            SELECT 
                c.customer_id,
                c.firstname,
                c.lastname,
                c.email,
                c.telephone,
                c.date_added,
                c.status,
                c.newsletter,
                CONCAT(c.firstname, ' ', c.lastname) as full_name,
                a.company,
                a.address_1,
                a.address_2,
                a.city,
                a.postcode,
                a.zone as region,
                a.country,
                cg.name as customer_group,
                (SELECT COUNT(*) FROM `order` o WHERE o.customer_id = c.customer_id AND o.order_status_id IN (2,3,5)) as total_orders,
                (SELECT SUM(o.total) FROM `order` o WHERE o.customer_id = c.customer_id AND o.order_status_id IN (2,3,5)) as total_spent
            FROM `customer` c
            LEFT JOIN `address` a ON c.address_id = a.address_id
            LEFT JOIN `customer_group` cg ON c.customer_group_id = cg.customer_group_id
            WHERE 1=1
        ";
        
        return $this->applyCustomerFilters($sql, $filters);
    }

    /**
     * Builds customer count query.
     *
     * @param array $filters
     * @return string
     */
    protected function buildCustomerCountQuery(array $filters): string
    {
        $sql = "
            SELECT COUNT(*) as count
            FROM `customer` c
            LEFT JOIN `address` a ON c.address_id = a.address_id
            WHERE 1=1
        ";
        
        return $this->applyCustomerFilters($sql, $filters);
    }

    /**
     * Applies customer filters to SQL query.
     *
     * @param string $sql
     * @param array $filters
     * @return string
     */
    protected function applyCustomerFilters(string $sql, array $filters): string
    {
        if (!empty($filters['status'])) {
            $sql .= " AND c.status = '" . (int)$filters['status'] . "'";
        }
        
        if (!empty($filters['customer_group_id'])) {
            $sql .= " AND c.customer_group_id = '" . (int)$filters['customer_group_id'] . "'";
        }
        
        if (!empty($filters['newsletter'])) {
            $sql .= " AND c.newsletter = '" . (int)$filters['newsletter'] . "'";
        }
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(c.date_added) >= '" . $this->db->escape($filters['date_from']) . "'";
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(c.date_added) <= '" . $this->db->escape($filters['date_to']) . "'";
        }
        
        if (!empty($filters['country'])) {
            $sql .= " AND a.country = '" . $this->db->escape($filters['country']) . "'";
        }
        
        if (!empty($filters['search'])) {
            $search = $this->db->escape($filters['search']);
            $sql .= " AND (c.firstname LIKE '%{$search}%' OR c.lastname LIKE '%{$search}%' OR c.email LIKE '%{$search}%')";
        }
        
        $sql .= " ORDER BY c.customer_id ASC";
        
        return $sql;
    }

    /**
     * Builds order query with filters.
     *
     * @param array $filters
     * @return string
     */
    protected function buildOrderQuery(array $filters): string
    {
        $sql = "
            SELECT 
                o.order_id,
                o.invoice_no,
                o.invoice_prefix,
                o.customer_id,
                CONCAT(o.firstname, ' ', o.lastname) as customer_name,
                o.email,
                o.telephone,
                o.total,
                o.order_status_id,
                os.name as order_status,
                o.date_added,
                o.date_modified,
                o.currency_code,
                o.currency_value,
                o.payment_method,
                o.shipping_method,
                o.comment,
                COUNT(op.product_id) as product_count,
                SUM(op.quantity) as total_quantity
            FROM `order` o
            LEFT JOIN `order_status` os ON o.order_status_id = os.order_status_id
            LEFT JOIN `order_product` op ON o.order_id = op.order_id
            WHERE 1=1
        ";
        
        $sql = $this->applyOrderFilters($sql, $filters);
        $sql .= " GROUP BY o.order_id";
        
        return $sql;
    }

    /**
     * Builds order count query.
     *
     * @param array $filters
     * @return string
     */
    protected function buildOrderCountQuery(array $filters): string
    {
        $sql = "
            SELECT COUNT(DISTINCT o.order_id) as count
            FROM `order` o
            LEFT JOIN `order_status` os ON o.order_status_id = os.order_status_id
            WHERE 1=1
        ";
        
        return $this->applyOrderFilters($sql, $filters);
    }

    /**
     * Applies order filters to SQL query.
     *
     * @param string $sql
     * @param array $filters
     * @return string
     */
    protected function applyOrderFilters(string $sql, array $filters): string
    {
        if (!empty($filters['order_status_id'])) {
            if (is_array($filters['order_status_id'])) {
                $statuses = implode(',', array_map('intval', $filters['order_status_id']));
                $sql .= " AND o.order_status_id IN ({$statuses})";
            } else {
                $sql .= " AND o.order_status_id = '" . (int)$filters['order_status_id'] . "'";
            }
        }
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(o.date_added) >= '" . $this->db->escape($filters['date_from']) . "'";
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(o.date_added) <= '" . $this->db->escape($filters['date_to']) . "'";
        }
        
        if (!empty($filters['customer_id'])) {
            $sql .= " AND o.customer_id = '" . (int)$filters['customer_id'] . "'";
        }
        
        if (!empty($filters['total_from'])) {
            $sql .= " AND o.total >= '" . (float)$filters['total_from'] . "'";
        }
        
        if (!empty($filters['total_to'])) {
            $sql .= " AND o.total <= '" . (float)$filters['total_to'] . "'";
        }
        
        if (!empty($filters['payment_method'])) {
            $sql .= " AND o.payment_method = '" . $this->db->escape($filters['payment_method']) . "'";
        }
        
        if (!empty($filters['currency_code'])) {
            $sql .= " AND o.currency_code = '" . $this->db->escape($filters['currency_code']) . "'";
        }
        
        $sql .= " ORDER BY o.order_id DESC";
        
        return $sql;
    }

    /**
     * Builds segment query with filters.
     *
     * @param int $segmentId
     * @param array $filters
     * @return string
     */
    protected function buildSegmentQuery(int $segmentId, array $filters): string
    {
        $sql = "
            SELECT 
                c.customer_id,
                c.firstname,
                c.lastname,
                c.email,
                c.telephone,
                c.date_added as customer_since,
                sc.added_at as segment_added,
                s.name as segment_name,
                (SELECT COUNT(*) FROM `order` o WHERE o.customer_id = c.customer_id AND o.order_status_id IN (2,3,5)) as total_orders,
                (SELECT SUM(o.total) FROM `order` o WHERE o.customer_id = c.customer_id AND o.order_status_id IN (2,3,5)) as total_spent,
                (SELECT MAX(o.date_added) FROM `order` o WHERE o.customer_id = c.customer_id AND o.order_status_id IN (2,3,5)) as last_order_date
            FROM `mas_segment_customer` sc
            JOIN `customer` c ON sc.customer_id = c.customer_id
            JOIN `mas_segment` s ON sc.segment_id = s.segment_id
            WHERE sc.segment_id = '" . (int)$segmentId . "'
        ";
        
        return $this->applySegmentFilters($sql, $filters);
    }

    /**
     * Builds segment count query.
     *
     * @param int $segmentId
     * @param array $filters
     * @return string
     */
    protected function buildSegmentCountQuery(int $segmentId, array $filters): string
    {
        $sql = "
            SELECT COUNT(*) as count
            FROM `mas_segment_customer` sc
            JOIN `customer` c ON sc.customer_id = c.customer_id
            WHERE sc.segment_id = '" . (int)$segmentId . "'
        ";
        
        return $this->applySegmentFilters($sql, $filters);
    }

    /**
     * Applies segment filters to SQL query.
     *
     * @param string $sql
     * @param array $filters
     * @return string
     */
    protected function applySegmentFilters(string $sql, array $filters): string
    {
        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(sc.added_at) >= '" . $this->db->escape($filters['date_from']) . "'";
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(sc.added_at) <= '" . $this->db->escape($filters['date_to']) . "'";
        }
        
        if (!empty($filters['customer_status'])) {
            $sql .= " AND c.status = '" . (int)$filters['customer_status'] . "'";
        }
        
        $sql .= " ORDER BY sc.added_at DESC";
        
        return $sql;
    }

    /**
     * Builds workflow execution query with filters.
     *
     * @param array $filters
     * @return string
     */
    protected function buildWorkflowExecutionQuery(array $filters): string
    {
        $sql = "
            SELECT 
                we.execution_id,
                we.workflow_id,
                w.name as workflow_name,
                we.customer_id,
                CONCAT(c.firstname, ' ', c.lastname) as customer_name,
                c.email as customer_email,
                we.status,
                we.trigger_type,
                we.trigger_data,
                we.started_at,
                we.completed_at,
                we.error_message,
                TIMESTAMPDIFF(SECOND, we.started_at, IFNULL(we.completed_at, NOW())) as duration_seconds,
                (SELECT COUNT(*) FROM `mas_workflow_execution_log` wel WHERE wel.execution_id = we.execution_id) as step_count
            FROM `mas_workflow_execution` we
            LEFT JOIN `mas_workflow` w ON we.workflow_id = w.workflow_id
            LEFT JOIN `customer` c ON we.customer_id = c.customer_id
            WHERE 1=1
        ";
        
        return $this->applyWorkflowExecutionFilters($sql, $filters);
    }

    /**
     * Builds workflow execution count query.
     *
     * @param array $filters
     * @return string
     */
    protected function buildWorkflowExecutionCountQuery(array $filters): string
    {
        $sql = "
            SELECT COUNT(*) as count
            FROM `mas_workflow_execution` we
            LEFT JOIN `mas_workflow` w ON we.workflow_id = w.workflow_id
            LEFT JOIN `customer` c ON we.customer_id = c.customer_id
            WHERE 1=1
        ";
        
        return $this->applyWorkflowExecutionFilters($sql, $filters);
    }

    /**
     * Applies workflow execution filters to SQL query.
     *
     * @param string $sql
     * @param array $filters
     * @return string
     */
    protected function applyWorkflowExecutionFilters(string $sql, array $filters): string
    {
        if (!empty($filters['workflow_id'])) {
            $sql .= " AND we.workflow_id = '" . (int)$filters['workflow_id'] . "'";
        }
        
        if (!empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $statuses = "'" . implode("','", array_map([$this->db, 'escape'], $filters['status'])) . "'";
                $sql .= " AND we.status IN ({$statuses})";
            } else {
                $sql .= " AND we.status = '" . $this->db->escape($filters['status']) . "'";
            }
        }
        
        if (!empty($filters['customer_id'])) {
            $sql .= " AND we.customer_id = '" . (int)$filters['customer_id'] . "'";
        }
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(we.started_at) >= '" . $this->db->escape($filters['date_from']) . "'";
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(we.started_at) <= '" . $this->db->escape($filters['date_to']) . "'";
        }
        
        if (!empty($filters['trigger_type'])) {
            $sql .= " AND we.trigger_type = '" . $this->db->escape($filters['trigger_type']) . "'";
        }
        
        $sql .= " ORDER BY we.started_at DESC";
        
        return $sql;
    }

    /**
     * Gets default customer columns.
     *
     * @return array
     */
    protected function getDefaultCustomerColumns(): array
    {
        return [
            ['field' => 'customer_id', 'label' => 'ID'],
            ['field' => 'firstname', 'label' => 'First Name'],
            ['field' => 'lastname', 'label' => 'Last Name'],
            ['field' => 'email', 'label' => 'Email'],
            ['field' => 'telephone', 'label' => 'Phone'],
            ['field' => 'date_added', 'label' => 'Registration Date', 'formatter' => 'datetime'],
            ['field' => 'status', 'label' => 'Status', 'formatter' => 'customer_status'],
            ['field' => 'newsletter', 'label' => 'Newsletter', 'formatter' => 'yes_no'],
            ['field' => 'customer_group', 'label' => 'Customer Group'],
            ['field' => 'total_orders', 'label' => 'Total Orders'],
            ['field' => 'total_spent', 'label' => 'Total Spent', 'formatter' => 'currency'],
            ['field' => 'company', 'label' => 'Company'],
            ['field' => 'city', 'label' => 'City'],
            ['field' => 'country', 'label' => 'Country'],
        ];
    }

    /**
     * Gets default order columns.
     *
     * @return array
     */
    protected function getDefaultOrderColumns(): array
    {
        return [
            ['field' => 'order_id', 'label' => 'Order ID'],
            ['field' => 'invoice_no', 'label' => 'Invoice No'],
            ['field' => 'customer_name', 'label' => 'Customer Name'],
            ['field' => 'email', 'label' => 'Customer Email'],
            ['field' => 'total', 'label' => 'Total', 'formatter' => 'currency'],
            ['field' => 'order_status', 'label' => 'Status'],
            ['field' => 'date_added', 'label' => 'Order Date', 'formatter' => 'datetime'],
            ['field' => 'payment_method', 'label' => 'Payment Method'],
            ['field' => 'shipping_method', 'label' => 'Shipping Method'],
            ['field' => 'currency_code', 'label' => 'Currency'],
            ['field' => 'product_count', 'label' => 'Products Count'],
            ['field' => 'total_quantity', 'label' => 'Total Quantity'],
        ];
    }

    /**
     * Gets default segment columns.
     *
     * @return array
     */
    protected function getDefaultSegmentColumns(): array
    {
        return [
            ['field' => 'customer_id', 'label' => 'Customer ID'],
            ['field' => 'firstname', 'label' => 'First Name'],
            ['field' => 'lastname', 'label' => 'Last Name'],
            ['field' => 'email', 'label' => 'Email'],
            ['field' => 'segment_name', 'label' => 'Segment'],
            ['field' => 'segment_added', 'label' => 'Added to Segment', 'formatter' => 'datetime'],
            ['field' => 'customer_since', 'label' => 'Customer Since', 'formatter' => 'date'],
            ['field' => 'total_orders', 'label' => 'Total Orders'],
            ['field' => 'total_spent', 'label' => 'Total Spent', 'formatter' => 'currency'],
            ['field' => 'last_order_date', 'label' => 'Last Order', 'formatter' => 'date'],
        ];
    }

    /**
     * Gets default workflow execution columns.
     *
     * @return array
     */
    protected function getDefaultWorkflowExecutionColumns(): array
    {
        return [
            ['field' => 'execution_id', 'label' => 'Execution ID'],
            ['field' => 'workflow_name', 'label' => 'Workflow'],
            ['field' => 'customer_name', 'label' => 'Customer'],
            ['field' => 'customer_email', 'label' => 'Customer Email'],
            ['field' => 'status', 'label' => 'Status'],
            ['field' => 'trigger_type', 'label' => 'Trigger Type'],
            ['field' => 'started_at', 'label' => 'Started At', 'formatter' => 'datetime'],
            ['field' => 'completed_at', 'label' => 'Completed At', 'formatter' => 'datetime'],
            ['field' => 'duration_seconds', 'label' => 'Duration (seconds)'],
            ['field' => 'step_count', 'label' => 'Steps Count'],
            ['field' => 'error_message', 'label' => 'Error Message'],
        ];
    }

    /**
     * Initializes data formatters.
     *
     * @return void
     */
    protected function initializeFormatters(): void
    {
        $this->formatters = [
            'datetime' => function($value) {
                if (!$value || $value === '0000-00-00 00:00:00') {
                    return '';
                }
                return DateHelper::format($value, 'Y-m-d H:i:s');
            },
            'date' => function($value) {
                if (!$value || $value === '0000-00-00') {
                    return '';
                }
                return DateHelper::format($value, 'Y-m-d');
            },
            'currency' => function($value) {
                return number_format((float)$value, 2, '.', '');
            },
            'yes_no' => function($value) {
                return $value ? 'Yes' : 'No';
            },
            'customer_status' => function($value) {
                return $value ? 'Active' : 'Inactive';
            },
        ];
    }

    /**
     * Emits progress event.
     *
     * @param string $exportType
     * @param int $current
     * @param int $total
     * @return void
     */
    protected function emitProgressEvent(string $exportType, int $current, int $total): void
    {
        $this->emitEvent('export.progress', [
            'export_type' => $exportType,
            'current' => $current,
            'total' => $total,
            'percentage' => round(($current / $total) * 100, 2),
        ]);
    }

    /**
     * Emits completion event.
     *
     * @param string $exportType
     * @param string $filename
     * @param int $rows
     * @param float $executionTime
     * @param int $fileSize
     * @param array $filters
     * @return void
     */
    protected function emitCompletionEvent(string $exportType, string $filename, int $rows, float $executionTime, int $fileSize, array $filters): void
    {
        $this->emitEvent('export.completed', [
            'export_type' => $exportType,
            'filename' => $filename,
            'rows_exported' => $rows,
            'execution_time' => $executionTime,
            'file_size' => $fileSize,
            'filters' => $filters,
        ]);
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
     * Gets export progress.
     *
     * @return array
     */
    public function getProgress(): array
    {
        return [
            'current' => $this->progressCounter,
            'total' => $this->totalRows,
            'percentage' => $this->totalRows > 0 ? round(($this->progressCounter / $this->totalRows) * 100, 2) : 0,
        ];
    }

    /**
     * Sets custom formatter.
     *
     * @param string $name
     * @param callable $formatter
     * @return void
     */
    public function setFormatter(string $name, callable $formatter): void
    {
        $this->formatters[$name] = $formatter;
    }

    /**
     * Sets CSV delimiter.
     *
     * @param string $delimiter
     * @return void
     */
    public function setDelimiter(string $delimiter): void
    {
        $this->delimiter = $delimiter;
    }

    /**
     * Sets batch size.
     *
     * @param int $batchSize
     * @return void
     */
    public function setBatchSize(int $batchSize): void
    {
        $this->batchSize = max(100, $batchSize);
    }

    /**
     * Enables or disables BOM.
     *
     * @param bool $addBom
     * @return void
     */
    public function setAddBom(bool $addBom): void
    {
        $this->addBom = $addBom;
    }
}
