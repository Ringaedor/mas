<?php
/**
 * MAS - Marketing Automation Suite
 * CustomAction
 *
 * Generic action node allowing execution of custom PHP callbacks or service methods.
 * Enables developers to plug in arbitrary logic within a workflow, passing context and
 * receiving output to drive subsequent nodes.
 *
 * Path: system/library/mas/workflow/actions/CustomAction.php
 */

namespace Opencart\Library\Mas\Workflow\Action;

use Opencart\Library\Mas\Workflow\Node\AbstractNode;
use Opencart\Library\Mas\Exception\ValidationException;
use Opencart\Library\Mas\Exception\WorkflowException;

class CustomAction extends AbstractNode
{
    protected string $version = '1.0.0';

    public static function getType(): string
    {
        return 'action_custom';
    }

    public function getLabel(): string
    {
        return 'Custom Action';
    }

    public function getDescription(): string
    {
        return 'Executes a custom callback or service method defined by code';
    }

    public static function getConfigSchema(): array
    {
        return [
            'callback' => [
                'type'        => 'string',
                'required'    => true,
                'label'       => 'Callback',
                'description' => 'PHP callable or service method to invoke (e.g. \'MyClass::myMethod\' or \'service.id:method\')',
            ],
            'parameters' => [
                'type'        => 'array',
                'required'    => false,
                'label'       => 'Parameters',
                'description' => 'Key-value pairs to pass as arguments to the callback',
            ],
            'timeout_seconds' => [
                'type'        => 'integer',
                'required'    => false,
                'default'     => 30,
                'min'         => 1,
                'label'       => 'Timeout (s)',
                'description' => 'Max execution time for the custom callback',
            ],
            'enabled' => [
                'type'        => 'boolean',
                'required'    => false,
                'default'     => true,
                'label'       => 'Enabled',
                'description' => 'Enable or disable this custom action',
            ],
        ];
    }

    protected function executeNode(array $context): array
    {
        if (!$this->getConfigValue('enabled', true)) {
            return ['success' => true, 'skipped' => true, 'reason' => 'CustomAction disabled'];
        }

        $callback = $this->getConfigValue('callback');
        if (!$callback || !is_string($callback)) {
            throw new ValidationException('Callback is required and must be a string');
        }

        $params = $this->getConfigValue('parameters', []);
        if (!is_array($params)) {
            throw new ValidationException('Parameters must be an array');
        }

        // Prepare callable
        if (strpos($callback, ':') !== false) {
            // service lookup via container: 'service.id:method'
            list($serviceId, $method) = explode(':', $callback, 2);
            $service = $this->container->get($serviceId);
            if (!method_exists($service, $method)) {
                throw new WorkflowException("Service method not found: {$callback}");
            }
            $callable = [$service, $method];
        } else {
            if (strpos($callback, '::') !== false) {
                $callable = explode('::', $callback, 2);
            } else {
                // function name
                $callable = $callback;
            }
            if (!is_callable($callable)) {
                throw new WorkflowException("Invalid callable specified: {$callback}");
            }
        }

        // Merge context and parameters
        $args = array_merge(['context' => $context], $params);

        // Invoke with timeout
        $timeout = (int)$this->getConfigValue('timeout_seconds', 30);
        set_time_limit($timeout);

        try {
            $output = call_user_func($callable, $args);
        } catch (\Throwable $e) {
            throw new WorkflowException("CustomAction error: " . $e->getMessage());
        }

        return [
            'success' => true,
            'output'  => $output,
            'meta'    => ['callback' => $callback],
        ];
    }

    protected function validateCustom(): void
    {
        $callback = $this->getConfigValue('callback');
        if (empty($callback)) {
            $this->addValidationError('Callback must be defined');
        }
        $timeout = $this->getConfigValue('timeout_seconds', 0);
        if ($timeout < 1) {
            $this->addValidationError('Timeout must be at least 1 second');
        }
    }
}
