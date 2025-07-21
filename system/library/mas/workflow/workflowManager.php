<?php
/**
 * MAS - Marketing Automation Suite
 * WorkflowManager
 *
 * Manages workflow creation, execution, scheduling, and lifecycle management.
 * Handles workflow nodes (triggers, actions, delays), serialization, validation,
 * and integration with event dispatcher and queue system.
 *
 * @copyright Copyright (c) 2025, Your Company
 * @license   Proprietary
 */

namespace Opencart\Library\Mas\Workflow;

use Opencart\Library\Mas\ServiceContainer;
use Opencart\Library\Mas\Interfaces\NodeInterface;
use Opencart\Library\Mas\Exception\WorkflowException;
use Opencart\Library\Mas\Exception\ValidationException;
use Opencart\Library\Mas\Helper\ArrayHelper;
use Opencart\Library\Mas\Helper\DateHelper;
use Opencart\System\Engine\Registry;
use Opencart\System\Engine\Loader;
use Opencart\System\Engine\Log;
use Opencart\System\Library\Cache;
use Opencart\System\Library\DB;

class WorkflowManager
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
     * @var array<string, NodeInterface> Registered node types
     */
    protected array $nodeTypes = [];

    /**
     * @var array<string, array> Loaded workflow definitions
     */
    protected array $workflows = [];

    /**
     * @var array<string, array> Active workflow executions
     */
    protected array $executions = [];

    /**
     * @var array Configuration settings
     */
    protected array $config = [];

    /**
     * @var int Maximum number of nodes per workflow
     */
    protected int $maxNodesPerWorkflow = 50;

    /**
     * @var int Maximum active workflows per customer
     */
    protected int $maxActivePerCustomer = 10;

    /**
     * @var int Workflow execution timeout in seconds
     */
    protected int $executionTimeout = 300;

    /**
     * @var array<string, callable> Event listeners
     */
    protected array $eventListeners = [];

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
        $this->registerDefaultNodeTypes();
        $this->initializeEventListeners();
    }

    /**
     * Creates a new workflow.
     *
     * @param array $workflowData
     * @return array
     * @throws WorkflowException
     */
    public function createWorkflow(array $workflowData): array
    {
        $this->validateWorkflowData($workflowData);

        $workflowId = $this->generateWorkflowId();
        $definition = $this->buildWorkflowDefinition($workflowData);

        // Validate workflow structure
        $this->validateWorkflowStructure($definition);

        // Save workflow to database
        $this->saveWorkflow($workflowId, $definition);

        // Cache workflow
        $this->cacheWorkflow($workflowId, $definition);

        $this->log->write('MAS: Workflow created - ID: ' . $workflowId);

        return [
            'workflow_id' => $workflowId,
            'name' => $definition['name'],
            'type' => $definition['type'],
            'status' => 'active',
            'created_at' => DateHelper::now(),
            'nodes_count' => count($definition['nodes']),
        ];
    }

    /**
     * Updates an existing workflow.
     *
     * @param string $workflowId
     * @param array $workflowData
     * @return array
     * @throws WorkflowException
     */
    public function updateWorkflow(string $workflowId, array $workflowData): array
    {
        if (!$this->workflowExists($workflowId)) {
            throw new WorkflowException("Workflow not found: {$workflowId}", 0);
        }

        $this->validateWorkflowData($workflowData);

        $definition = $this->buildWorkflowDefinition($workflowData);
        $definition['updated_at'] = DateHelper::now();

        // Validate workflow structure
        $this->validateWorkflowStructure($definition);

        // Update workflow in database
        $this->saveWorkflow($workflowId, $definition);

        // Update cache
        $this->cacheWorkflow($workflowId, $definition);

        $this->log->write('MAS: Workflow updated - ID: ' . $workflowId);

        return [
            'workflow_id' => $workflowId,
            'name' => $definition['name'],
            'type' => $definition['type'],
            'status' => $definition['status'],
            'updated_at' => $definition['updated_at'],
            'nodes_count' => count($definition['nodes']),
        ];
    }

    /**
     * Deletes a workflow.
     *
     * @param string $workflowId
     * @return bool
     * @throws WorkflowException
     */
    public function deleteWorkflow(string $workflowId): bool
    {
        if (!$this->workflowExists($workflowId)) {
            throw new WorkflowException("Workflow not found: {$workflowId}", 0);
        }

        // Check for active executions
        $activeExecutions = $this->getActiveExecutions($workflowId);
        if (!empty($activeExecutions)) {
            throw new WorkflowException("Cannot delete workflow with active executions: {$workflowId}", 0);
        }

        // Delete from database
        $this->db->query("DELETE FROM `mas_workflow` WHERE `workflow_id` = '" . $this->db->escape($workflowId) . "'");
        $this->db->query("DELETE FROM `mas_workflow_execution` WHERE `workflow_id` = '" . $this->db->escape($workflowId) . "'");

        // Remove from cache
        $this->cache->delete('mas_workflow_' . $workflowId);

        // Remove from memory
        unset($this->workflows[$workflowId]);

        $this->log->write('MAS: Workflow deleted - ID: ' . $workflowId);

        return true;
    }

    /**
     * Executes a workflow with given context.
     *
     * @param string $workflowId
     * @param array $context
     * @return array
     * @throws WorkflowException
     */
    public function executeWorkflow(string $workflowId, array $context): array
    {
        $workflow = $this->getWorkflow($workflowId);
        if (!$workflow) {
            throw new WorkflowException("Workflow not found: {$workflowId}", 0);
        }

        if ($workflow['status'] !== 'active') {
            throw new WorkflowException("Workflow is not active: {$workflowId}", 0);
        }

        // Check customer execution limits
        $customerId = $context['customer_id'] ?? null;
        if ($customerId && !$this->checkExecutionLimits($customerId)) {
            throw new WorkflowException("Customer execution limit exceeded: {$customerId}", 0);
        }

        $executionId = $this->generateExecutionId();
        $execution = $this->createExecution($executionId, $workflowId, $context);

        try {
            // Execute workflow nodes
            $result = $this->executeNodes($execution, $workflow['nodes']);

            // Update execution status
            $this->updateExecutionStatus($executionId, 'completed', $result);

            $this->log->write('MAS: Workflow executed - ID: ' . $workflowId . ', Execution: ' . $executionId);

            return [
                'execution_id' => $executionId,
                'workflow_id' => $workflowId,
                'status' => 'completed',
                'result' => $result,
                'started_at' => $execution['started_at'],
                'completed_at' => DateHelper::now(),
            ];

        } catch (\Exception $e) {
            $this->updateExecutionStatus($executionId, 'failed', ['error' => $e->getMessage()]);
            throw new WorkflowException("Workflow execution failed: {$e->getMessage()}", 0, [], $e);
        }
    }

    /**
     * Schedules a workflow execution.
     *
     * @param string $workflowId
     * @param array $context
     * @param string $scheduleTime
     * @return array
     * @throws WorkflowException
     */
    public function scheduleWorkflow(string $workflowId, array $context, string $scheduleTime): array
    {
        $workflow = $this->getWorkflow($workflowId);
        if (!$workflow) {
            throw new WorkflowException("Workflow not found: {$workflowId}", 0);
        }

        $scheduledAt = DateHelper::parse($scheduleTime);
        if (!$scheduledAt || $scheduledAt <= DateHelper::nowObject()) {
            throw new WorkflowException("Invalid schedule time: {$scheduleTime}", 0);
        }

        $executionId = $this->generateExecutionId();
        
        // Create scheduled execution
        $this->db->query("
            INSERT INTO `mas_workflow_execution` SET
            `execution_id` = '" . $this->db->escape($executionId) . "',
            `workflow_id` = '" . $this->db->escape($workflowId) . "',
            `customer_id` = '" . (int)($context['customer_id'] ?? 0) . "',
            `context` = '" . $this->db->escape(json_encode($context)) . "',
            `status` = 'scheduled',
            `scheduled_at` = '" . $this->db->escape($scheduledAt->format('Y-m-d H:i:s')) . "',
            `created_at` = NOW()
        ");

        $this->log->write('MAS: Workflow scheduled - ID: ' . $workflowId . ', Execution: ' . $executionId);

        return [
            'execution_id' => $executionId,
            'workflow_id' => $workflowId,
            'status' => 'scheduled',
            'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Cancels a scheduled workflow execution.
     *
     * @param string $executionId
     * @return bool
     */
    public function cancelExecution(string $executionId): bool
    {
        $execution = $this->getExecution($executionId);
        if (!$execution) {
            return false;
        }

        if ($execution['status'] !== 'scheduled') {
            return false;
        }

        $this->updateExecutionStatus($executionId, 'cancelled');

        $this->log->write('MAS: Workflow execution cancelled - ID: ' . $executionId);

        return true;
    }

    /**
     * Processes scheduled workflow executions.
     *
     * @return array
     */
    public function processScheduledExecutions(): array
    {
        $query = $this->db->query("
            SELECT * FROM `mas_workflow_execution`
            WHERE `status` = 'scheduled'
            AND `scheduled_at` <= NOW()
            ORDER BY `scheduled_at` ASC
            LIMIT 100
        ");

        $processed = [];
        foreach ($query->rows as $row) {
            try {
                $context = json_decode($row['context'], true);
                $result = $this->executeWorkflow($row['workflow_id'], $context);
                $processed[] = $result;
            } catch (\Exception $e) {
                $this->updateExecutionStatus($row['execution_id'], 'failed', ['error' => $e->getMessage()]);
                $this->log->write('MAS: Scheduled execution failed - ID: ' . $row['execution_id'] . ', Error: ' . $e->getMessage());
            }
        }

        return $processed;
    }

    /**
     * Gets workflow by ID.
     *
     * @param string $workflowId
     * @return array|null
     */
    public function getWorkflow(string $workflowId): ?array
    {
        // Check cache first
        $cached = $this->cache->get('mas_workflow_' . $workflowId);
        if ($cached) {
            return $cached;
        }

        // Load from database
        $query = $this->db->query("
            SELECT * FROM `mas_workflow`
            WHERE `workflow_id` = '" . $this->db->escape($workflowId) . "'
        ");

        if ($query->num_rows) {
            $row = $query->row;
            $workflow = [
                'workflow_id' => $row['workflow_id'],
                'name' => $row['name'],
                'description' => $row['description'],
                'type' => $row['type'],
                'status' => $row['status'],
                'nodes' => json_decode($row['definition'], true),
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ];

            // Cache workflow
            $this->cache->set('mas_workflow_' . $workflowId, $workflow, 3600);

            return $workflow;
        }

        return null;
    }

    /**
     * Gets execution by ID.
     *
     * @param string $executionId
     * @return array|null
     */
    public function getExecution(string $executionId): ?array
    {
        $query = $this->db->query("
            SELECT * FROM `mas_workflow_execution`
            WHERE `execution_id` = '" . $this->db->escape($executionId) . "'
        ");

        if ($query->num_rows) {
            $row = $query->row;
            return [
                'execution_id' => $row['execution_id'],
                'workflow_id' => $row['workflow_id'],
                'customer_id' => $row['customer_id'],
                'context' => json_decode($row['context'], true),
                'status' => $row['status'],
                'result' => $row['result'] ? json_decode($row['result'], true) : null,
                'started_at' => $row['started_at'],
                'completed_at' => $row['completed_at'],
                'scheduled_at' => $row['scheduled_at'],
                'created_at' => $row['created_at'],
            ];
        }

        return null;
    }

    /**
     * Gets all workflows.
     *
     * @param array $filters
     * @return array
     */
    public function getWorkflows(array $filters = []): array
    {
        $sql = "SELECT * FROM `mas_workflow`";
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
        $workflows = [];

        foreach ($query->rows as $row) {
            $workflows[] = [
                'workflow_id' => $row['workflow_id'],
                'name' => $row['name'],
                'description' => $row['description'],
                'type' => $row['type'],
                'status' => $row['status'],
                'nodes_count' => count(json_decode($row['definition'], true)),
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ];
        }

        return $workflows;
    }

    /**
     * Gets workflow executions.
     *
     * @param string|null $workflowId
     * @param array $filters
     * @return array
     */
    public function getExecutions(?string $workflowId = null, array $filters = []): array
    {
        $sql = "SELECT * FROM `mas_workflow_execution`";
        $conditions = [];

        if ($workflowId) {
            $conditions[] = "`workflow_id` = '" . $this->db->escape($workflowId) . "'";
        }

        if (!empty($filters['status'])) {
            $conditions[] = "`status` = '" . $this->db->escape($filters['status']) . "'";
        }

        if (!empty($filters['customer_id'])) {
            $conditions[] = "`customer_id` = '" . (int)$filters['customer_id'] . "'";
        }

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }

        $sql .= " ORDER BY `created_at` DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }

        $query = $this->db->query($sql);
        return $query->rows;
    }

    /**
     * Gets workflow statistics.
     *
     * @param string $workflowId
     * @return array
     */
    public function getWorkflowStatistics(string $workflowId): array
    {
        $query = $this->db->query("
            SELECT 
                COUNT(*) as total_executions,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_executions,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_executions,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_executions,
                SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled_executions
            FROM `mas_workflow_execution`
            WHERE `workflow_id` = '" . $this->db->escape($workflowId) . "'
        ");

        $stats = $query->row;
        $stats['success_rate'] = $stats['total_executions'] > 0 
            ? round(($stats['completed_executions'] / $stats['total_executions']) * 100, 2) 
            : 0;

        return $stats;
    }

    /**
     * Registers a node type.
     *
     * @param string $type
     * @param string $className
     * @return void
     */
    public function registerNodeType(string $type, string $className): void
    {
        if (!class_exists($className)) {
            throw new WorkflowException("Node class not found: {$className}");
        }

        if (!is_subclass_of($className, NodeInterface::class)) {
            throw new WorkflowException("Node class must implement NodeInterface: {$className}");
        }

        $this->nodeTypes[$type] = $className;
    }

    /**
     * Creates a node instance.
     *
     * @param string $type
     * @param array $config
     * @return NodeInterface
     * @throws WorkflowException
     */
    public function createNode(string $type, array $config = []): NodeInterface
    {
        if (!isset($this->nodeTypes[$type])) {
            throw new WorkflowException("Unknown node type: {$type}");
        }

        $className = $this->nodeTypes[$type];
        $node = new $className($this->container);
        $node->setConfig($config);

        return $node;
    }

    /**
     * Validates workflow data.
     *
     * @param array $workflowData
     * @return void
     * @throws ValidationException
     */
    protected function validateWorkflowData(array $workflowData): void
    {
        $errors = [];

        if (empty($workflowData['name'])) {
            $errors['name'] = 'Workflow name is required';
        }

        if (empty($workflowData['type'])) {
            $errors['type'] = 'Workflow type is required';
        }

        if (empty($workflowData['nodes']) || !is_array($workflowData['nodes'])) {
            $errors['nodes'] = 'Workflow must have at least one node';
        } elseif (count($workflowData['nodes']) > $this->maxNodesPerWorkflow) {
            $errors['nodes'] = "Workflow cannot have more than {$this->maxNodesPerWorkflow} nodes";
        }

        if (!empty($errors)) {
            throw new ValidationException('Workflow validation failed', $errors);
        }
    }

    /**
     * Validates workflow structure.
     *
     * @param array $definition
     * @return void
     * @throws WorkflowException
     */
    protected function validateWorkflowStructure(array $definition): void
    {
        $nodes = $definition['nodes'];
        $hasStartNode = false;

        foreach ($nodes as $node) {
            if ($node['type'] === 'trigger') {
                $hasStartNode = true;
                break;
            }
        }

        if (!$hasStartNode) {
            throw new WorkflowException('Workflow must have at least one trigger node');
        }

        // Validate node connections
        $this->validateNodeConnections($nodes);
    }

    /**
     * Validates node connections.
     *
     * @param array $nodes
     * @return void
     * @throws WorkflowException
     */
    protected function validateNodeConnections(array $nodes): void
    {
        $nodeIds = array_column($nodes, 'id');
        
        foreach ($nodes as $node) {
            if (!empty($node['next_nodes'])) {
                foreach ($node['next_nodes'] as $nextNodeId) {
                    if (!in_array($nextNodeId, $nodeIds)) {
                        throw new WorkflowException("Invalid node connection: {$nextNodeId} not found");
                    }
                }
            }
        }
    }

    /**
     * Builds workflow definition from input data.
     *
     * @param array $workflowData
     * @return array
     */
    protected function buildWorkflowDefinition(array $workflowData): array
    {
        return [
            'name' => $workflowData['name'],
            'description' => $workflowData['description'] ?? '',
            'type' => $workflowData['type'],
            'status' => $workflowData['status'] ?? 'active',
            'nodes' => $workflowData['nodes'],
            'settings' => $workflowData['settings'] ?? [],
            'created_at' => DateHelper::now(),
            'updated_at' => DateHelper::now(),
        ];
    }

    /**
     * Executes workflow nodes.
     *
     * @param array $execution
     * @param array $nodes
     * @return array
     * @throws WorkflowException
     */
    protected function executeNodes(array $execution, array $nodes): array
    {
        $results = [];
        $currentNodeId = $this->findStartNode($nodes);
        $executionStartTime = time();

        while ($currentNodeId) {
            // Check execution timeout
            if (time() - $executionStartTime > $this->executionTimeout) {
                throw new WorkflowException('Workflow execution timeout');
            }

            $node = $this->findNodeById($nodes, $currentNodeId);
            if (!$node) {
                throw new WorkflowException("Node not found: {$currentNodeId}");
            }

            // Create and execute node
            $nodeInstance = $this->createNode($node['type'], $node['config'] ?? []);
            $result = $nodeInstance->execute($execution['context']);

            $results[$currentNodeId] = $result;

            // Handle node result
            if (!$result['success']) {
                throw new WorkflowException("Node execution failed: {$result['error']}");
            }

            // Update execution context if needed
            if (isset($result['output'])) {
                $execution['context'] = array_merge($execution['context'], $result['output']);
            }

            // Determine next node
            $currentNodeId = $this->getNextNodeId($node, $result);

            // Handle delay nodes
            if ($node['type'] === 'delay' && !empty($node['config']['delay'])) {
                // For delay nodes, we would typically schedule continuation
                // For now, we'll just log the delay
                $this->log->write('MAS: Workflow delay - ' . $node['config']['delay'] . ' seconds');
            }
        }

        return $results;
    }

    /**
     * Finds the start node (trigger) in workflow.
     *
     * @param array $nodes
     * @return string|null
     */
    protected function findStartNode(array $nodes): ?string
    {
        foreach ($nodes as $node) {
            if ($node['type'] === 'trigger') {
                return $node['id'];
            }
        }
        return null;
    }

    /**
     * Finds a node by ID.
     *
     * @param array $nodes
     * @param string $nodeId
     * @return array|null
     */
    protected function findNodeById(array $nodes, string $nodeId): ?array
    {
        foreach ($nodes as $node) {
            if ($node['id'] === $nodeId) {
                return $node;
            }
        }
        return null;
    }

    /**
     * Gets the next node ID based on current node and result.
     *
     * @param array $node
     * @param array $result
     * @return string|null
     */
    protected function getNextNodeId(array $node, array $result): ?string
    {
        if (empty($node['next_nodes'])) {
            return null;
        }

        // For simple linear workflows, just return the first next node
        return $node['next_nodes'][0] ?? null;
    }

    /**
     * Creates a workflow execution record.
     *
     * @param string $executionId
     * @param string $workflowId
     * @param array $context
     * @return array
     */
    protected function createExecution(string $executionId, string $workflowId, array $context): array
    {
        $execution = [
            'execution_id' => $executionId,
            'workflow_id' => $workflowId,
            'context' => $context,
            'status' => 'running',
            'started_at' => DateHelper::now(),
        ];

        $this->db->query("
            INSERT INTO `mas_workflow_execution` SET
            `execution_id` = '" . $this->db->escape($executionId) . "',
            `workflow_id` = '" . $this->db->escape($workflowId) . "',
            `customer_id` = '" . (int)($context['customer_id'] ?? 0) . "',
            `context` = '" . $this->db->escape(json_encode($context)) . "',
            `status` = 'running',
            `started_at` = NOW(),
            `created_at` = NOW()
        ");

        return $execution;
    }

    /**
     * Updates execution status.
     *
     * @param string $executionId
     * @param string $status
     * @param array|null $result
     * @return void
     */
    protected function updateExecutionStatus(string $executionId, string $status, ?array $result = null): void
    {
        $sql = "UPDATE `mas_workflow_execution` SET `status` = '" . $this->db->escape($status) . "'";
        
        if ($result !== null) {
            $sql .= ", `result` = '" . $this->db->escape(json_encode($result)) . "'";
        }

        if (in_array($status, ['completed', 'failed', 'cancelled'])) {
            $sql .= ", `completed_at` = NOW()";
        }

        $sql .= " WHERE `execution_id` = '" . $this->db->escape($executionId) . "'";

        $this->db->query($sql);
    }

    /**
     * Saves workflow to database.
     *
     * @param string $workflowId
     * @param array $definition
     * @return void
     */
    protected function saveWorkflow(string $workflowId, array $definition): void
    {
        $this->db->query("
            INSERT INTO `mas_workflow` SET
            `workflow_id` = '" . $this->db->escape($workflowId) . "',
            `name` = '" . $this->db->escape($definition['name']) . "',
            `description` = '" . $this->db->escape($definition['description']) . "',
            `type` = '" . $this->db->escape($definition['type']) . "',
            `status` = '" . $this->db->escape($definition['status']) . "',
            `definition` = '" . $this->db->escape(json_encode($definition['nodes'])) . "',
            `settings` = '" . $this->db->escape(json_encode($definition['settings'])) . "',
            `created_at` = NOW(),
            `updated_at` = NOW()
            ON DUPLICATE KEY UPDATE
            `name` = VALUES(`name`),
            `description` = VALUES(`description`),
            `type` = VALUES(`type`),
            `status` = VALUES(`status`),
            `definition` = VALUES(`definition`),
            `settings` = VALUES(`settings`),
            `updated_at` = NOW()
        ");
    }

    /**
     * Caches workflow definition.
     *
     * @param string $workflowId
     * @param array $definition
     * @return void
     */
    protected function cacheWorkflow(string $workflowId, array $definition): void
    {
        $this->cache->set('mas_workflow_' . $workflowId, $definition, 3600);
    }

    /**
     * Checks if workflow exists.
     *
     * @param string $workflowId
     * @return bool
     */
    protected function workflowExists(string $workflowId): bool
    {
        $query = $this->db->query("
            SELECT COUNT(*) as count FROM `mas_workflow`
            WHERE `workflow_id` = '" . $this->db->escape($workflowId) . "'
        ");

        return $query->row['count'] > 0;
    }

    /**
     * Gets active executions for a workflow.
     *
     * @param string $workflowId
     * @return array
     */
    protected function getActiveExecutions(string $workflowId): array
    {
        $query = $this->db->query("
            SELECT * FROM `mas_workflow_execution`
            WHERE `workflow_id` = '" . $this->db->escape($workflowId) . "'
            AND `status` IN ('running', 'scheduled')
        ");

        return $query->rows;
    }

    /**
     * Checks execution limits for a customer.
     *
     * @param int $customerId
     * @return bool
     */
    protected function checkExecutionLimits(int $customerId): bool
    {
        $query = $this->db->query("
            SELECT COUNT(*) as count FROM `mas_workflow_execution`
            WHERE `customer_id` = '" . (int)$customerId . "'
            AND `status` IN ('running', 'scheduled')
        ");

        return $query->row['count'] < $this->maxActivePerCustomer;
    }

    /**
     * Generates a unique workflow ID.
     *
     * @return string
     */
    protected function generateWorkflowId(): string
    {
        return 'wf_' . uniqid() . '_' . time();
    }

    /**
     * Generates a unique execution ID.
     *
     * @return string
     */
    protected function generateExecutionId(): string
    {
        return 'exec_' . uniqid() . '_' . time();
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
        $this->maxNodesPerWorkflow = $masConfig['workflow']['max_nodes_per_workflow'] ?? 50;
        $this->maxActivePerCustomer = $masConfig['workflow']['max_active_per_customer'] ?? 10;
        $this->executionTimeout = $masConfig['workflow']['execution_timeout'] ?? 300;
    }

    /**
     * Registers default node types.
     *
     * @return void
     */
    protected function registerDefaultNodeTypes(): void
    {
        $this->registerNodeType('trigger', 'Opencart\Library\Mas\Workflow\Node\TriggerNode');
        $this->registerNodeType('action', 'Opencart\Library\Mas\Workflow\Node\ActionNode');
        $this->registerNodeType('delay', 'Opencart\Library\Mas\Workflow\Node\DelayNode');
        $this->registerNodeType('condition', 'Opencart\Library\Mas\Workflow\Node\ConditionNode');
    }

    /**
     * Initializes event listeners.
     *
     * @return void
     */
    protected function initializeEventListeners(): void
    {
        // Register event listeners for workflow triggers
        $this->addEventListener('order.complete', [$this, 'handleOrderComplete']);
        $this->addEventListener('cart.abandoned', [$this, 'handleCartAbandoned']);
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
     * Handles order complete event.
     *
     * @param array $data
     * @return void
     */
    public function handleOrderComplete(array $data): void
    {
        $this->triggerWorkflowsByEvent('order.complete', $data);
    }

    /**
     * Handles cart abandoned event.
     *
     * @param array $data
     * @return void
     */
    public function handleCartAbandoned(array $data): void
    {
        $this->triggerWorkflowsByEvent('cart.abandoned', $data);
    }

    /**
     * Handles customer register event.
     *
     * @param array $data
     * @return void
     */
    public function handleCustomerRegister(array $data): void
    {
        $this->triggerWorkflowsByEvent('customer.register', $data);
    }

    /**
     * Triggers workflows by event.
     *
     * @param string $event
     * @param array $data
     * @return void
     */
    protected function triggerWorkflowsByEvent(string $event, array $data): void
    {
        $workflows = $this->getWorkflowsByTrigger($event);
        
        foreach ($workflows as $workflow) {
            try {
                $this->executeWorkflow($workflow['workflow_id'], $data);
            } catch (\Exception $e) {
                $this->log->write('MAS: Auto-trigger failed - Workflow: ' . $workflow['workflow_id'] . ', Error: ' . $e->getMessage());
            }
        }
    }

    /**
     * Gets workflows by trigger event.
     *
     * @param string $event
     * @return array
     */
    protected function getWorkflowsByTrigger(string $event): array
    {
        $query = $this->db->query("
            SELECT * FROM `mas_workflow`
            WHERE `status` = 'active'
            AND JSON_EXTRACT(`definition`, '$[*].type') = 'trigger'
            AND JSON_EXTRACT(`definition`, '$[*].config.event') = '" . $this->db->escape($event) . "'
        ");

        return $query->rows;
    }

    /**
     * Cleans up old executions.
     *
     * @param int $daysOld
     * @return int
     */
    public function cleanupOldExecutions(int $daysOld = 30): int
    {
        $cutoffDate = DateHelper::nowObject()->modify("-{$daysOld} days")->format('Y-m-d H:i:s');
        
        $this->db->query("
            DELETE FROM `mas_workflow_execution`
            WHERE `status` IN ('completed', 'failed', 'cancelled')
            AND `completed_at` < '" . $this->db->escape($cutoffDate) . "'
        ");

        return $this->db->countAffected();
    }

    /**
     * Gets workflow performance metrics.
     *
     * @param string $workflowId
     * @param int $days
     * @return array
     */
    public function getWorkflowPerformance(string $workflowId, int $days = 30): array
    {
        $startDate = DateHelper::nowObject()->modify("-{$days} days")->format('Y-m-d H:i:s');
        
        $query = $this->db->query("
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as total_executions,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_executions,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_executions,
                AVG(TIMESTAMPDIFF(SECOND, started_at, completed_at)) as avg_duration
            FROM `mas_workflow_execution`
            WHERE `workflow_id` = '" . $this->db->escape($workflowId) . "'
            AND `created_at` >= '" . $this->db->escape($startDate) . "'
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");

        return $query->rows;
    }
}
