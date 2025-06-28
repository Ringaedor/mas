<?php
namespace Opencart\System\Library\Mas\Core;

use Opencart\System\Engine\Registry;

/**
 * Workflow - Manages the automation flows of the marketing automation suite.
 *
 * Allows the creation, modification and management of automation workflows.
 */
class Workflow {
    /** @var Registry $registry */
    protected $registry;

    /** @var array $workflows */
    protected $workflows = [];

    /**
     * Constructor.
     *
     * @param Registry $registry
     */
    public function __construct(Registry $registry) {
        $this->registry = $registry;
    }

    /**
     * Creates a new workflow.
     *
     * @param string $id
     * @param string $name
     * @param array $steps
     * @return array
     */
    public function createWorkflow(string $id, string $name, array $steps = []) {
        $this->workflows[$id] = [
            'id'    => $id,
            'name'  => $name,
            'steps' => $steps
        ];
        return $this->workflows[$id];
    }

    /**
     * Updates an existing workflow.
     *
     * @param string $id
     * @param array $data
     * @return bool
     */
    public function updateWorkflow(string $id, array $data) {
        if (!isset($this->workflows[$id])) {
            return false;
        }
        $this->workflows[$id] = array_merge($this->workflows[$id], $data);
        return true;
    }

    /**
     * Deletes a workflow.
     *
     * @param string $id
     * @return bool
     */
    public function deleteWorkflow(string $id) {
        if (!isset($this->workflows[$id])) {
            return false;
        }
        unset($this->workflows[$id]);
        return true;
    }

    /**
     * Returns a workflow by its ID.
     *
     * @param string $id
     * @return array|null
     */
    public function getWorkflow(string $id) {
        return $this->workflows[$id] ?? null;
    }

    /**
     * Returns all workflows.
     *
     * @return array
     */
    public function getAllWorkflows() {
        return $this->workflows;
    }

    /**
     * Adds a step to a workflow.
     *
     * @param string $workflowId
     * @param array $step
     * @return bool
     */
    public function addStep(string $workflowId, array $step) {
        if (!isset($this->workflows[$workflowId])) {
            return false;
        }
        $this->workflows[$workflowId]['steps'][] = $step;
        return true;
    }

    /**
     * Removes a step from a workflow.
     *
     * @param string $workflowId
     * @param int $stepIndex
     * @return bool
     */
    public function removeStep(string $workflowId, int $stepIndex) {
        if (!isset($this->workflows[$workflowId]) || !isset($this->workflows[$workflowId]['steps'][$stepIndex])) {
            return false;
        }
        array_splice($this->workflows[$workflowId]['steps'], $stepIndex, 1);
        return true;
    }

    /**
     * Gets all steps of a workflow.
     *
     * @param string $workflowId
     * @return array
     */
    public function getSteps(string $workflowId) {
        return $this->workflows[$workflowId]['steps'] ?? [];
    }

    /**
     * Updates a step in a workflow.
     *
     * @param string $workflowId
     * @param int $stepIndex
     * @param array $stepData
     * @return bool
     */
    public function updateStep(string $workflowId, int $stepIndex, array $stepData) {
        if (!isset($this->workflows[$workflowId]) || !isset($this->workflows[$workflowId]['steps'][$stepIndex])) {
            return false;
        }
        $this->workflows[$workflowId]['steps'][$stepIndex] = array_merge($this->workflows[$workflowId]['steps'][$stepIndex], $stepData);
        return true;
    }

    /**
     * Executes a workflow.
     *
     * @param string $workflowId
     * @param array $context
     * @return array
     */
    public function executeWorkflow(string $workflowId, array $context = []) {
        $workflow = $this->getWorkflow($workflowId);
        if (!$workflow) {
            return ['success' => false, 'error' => 'Workflow not found'];
        }

        $results = [];
        foreach ($workflow['steps'] as $step) {
            $results[] = $this->executeStep($step, $context);
        }

        return [
            'success' => true,
            'results' => $results
        ];
    }

    /**
     * Executes a single workflow step.
     *
     * @param array $step
     * @param array $context
     * @return array
     */
    protected function executeStep(array $step, array $context) {
        // Example: here you could use a provider or a custom action
        // For now, just return the step and context
        return [
            'step'    => $step,
            'context' => $context,
            'result'  => 'Step executed'
        ];
    }
}