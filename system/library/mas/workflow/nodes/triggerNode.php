<?php
/**
 * MAS - Marketing Automation Suite
 * TriggerNode
 *
 * Trigger node for workflow initiation based on events like order completion,
 * cart abandonment, customer registration, and other system events.
 * Handles event matching, condition evaluation, and workflow initialization.
 *
 * @copyright Copyright (c) 2025, Your Company
 * @license   Proprietary
 */

namespace Opencart\Library\Mas\Workflow\Node;

use Opencart\Library\Mas\Workflow\Node\AbstractNode;
use Opencart\Library\Mas\Exception\WorkflowException;
use Opencart\Library\Mas\Helper\ArrayHelper;
use Opencart\Library\Mas\Helper\DateHelper;

class TriggerNode extends AbstractNode
{
    /**
     * @var string Node version
     */
    protected string $version = '1.0.0';

    /**
     * @var array Supported trigger events
     */
    protected array $supportedEvents = [
        'order.complete',
        'order.cancel',
        'order.refund',
        'cart.abandoned',
        'cart.recovered',
        'customer.register',
        'customer.login',
        'customer.logout',
        'customer.birthday',
        'customer.anniversary',
        'product.viewed',
        'product.added_to_cart',
        'product.removed_from_cart',
        'product.added_to_wishlist',
        'product.review_added',
        'newsletter.subscribed',
        'newsletter.unsubscribed',
        'coupon.used',
        'reward.earned',
        'reward.redeemed',
        'scheduled.daily',
        'scheduled.weekly',
        'scheduled.monthly',
        'custom.event',
    ];

    /**
     * @var array Event data for matching
     */
    protected array $eventData = [];

    /**
     * @var bool Whether trigger conditions are met
     */
    protected bool $conditionsMet = false;

    /**
     * @var array Evaluation results
     */
    protected array $evaluationResults = [];

    /**
     * Returns the node type.
     *
     * @return string
     */
    public static function getType(): string
    {
        return 'trigger';
    }

    /**
     * Returns a human-readable label for the node.
     *
     * @return string
     */
    public function getLabel(): string
    {
        $event = $this->getConfigValue('event', 'Unknown Event');
        return 'Trigger: ' . ucfirst(str_replace('.', ' ', $event));
    }

    /**
     * Returns a description of the node.
     *
     * @return string
     */
    public function getDescription(): string
    {
        $event = $this->getConfigValue('event');
        $description = $this->getConfigValue('description', '');
        
        if ($description) {
            return $description;
        }
        
        return $event ? "Triggers when {$event} occurs" : 'Event trigger node';
    }

    /**
     * Returns the configuration schema for the trigger node.
     *
     * @return array
     */
    public static function getConfigSchema(): array
    {
        return [
            'event' => [
                'type' => 'string',
                'required' => true,
                'label' => 'Event Type',
                'description' => 'The event that triggers this workflow',
                'options' => [
                    'order.complete' => 'Order Completed',
                    'order.cancel' => 'Order Cancelled',
                    'order.refund' => 'Order Refunded',
                    'cart.abandoned' => 'Cart Abandoned',
                    'cart.recovered' => 'Cart Recovered',
                    'customer.register' => 'Customer Registration',
                    'customer.login' => 'Customer Login',
                    'customer.logout' => 'Customer Logout',
                    'customer.birthday' => 'Customer Birthday',
                    'customer.anniversary' => 'Customer Anniversary',
                    'product.viewed' => 'Product Viewed',
                    'product.added_to_cart' => 'Product Added to Cart',
                    'product.removed_from_cart' => 'Product Removed from Cart',
                    'product.added_to_wishlist' => 'Product Added to Wishlist',
                    'product.review_added' => 'Product Review Added',
                    'newsletter.subscribed' => 'Newsletter Subscribed',
                    'newsletter.unsubscribed' => 'Newsletter Unsubscribed',
                    'coupon.used' => 'Coupon Used',
                    'reward.earned' => 'Reward Points Earned',
                    'reward.redeemed' => 'Reward Points Redeemed',
                    'scheduled.daily' => 'Daily Schedule',
                    'scheduled.weekly' => 'Weekly Schedule',
                    'scheduled.monthly' => 'Monthly Schedule',
                    'custom.event' => 'Custom Event',
                ],
            ],
            'description' => [
                'type' => 'string',
                'required' => false,
                'label' => 'Description',
                'description' => 'Optional description of this trigger',
                'max_length' => 255,
            ],
            'conditions' => [
                'type' => 'array',
                'required' => false,
                'label' => 'Conditions',
                'description' => 'Optional conditions that must be met for the trigger to activate',
            ],
            'delay_seconds' => [
                'type' => 'integer',
                'required' => false,
                'default' => 0,
                'min' => 0,
                'max' => 86400,
                'label' => 'Delay (seconds)',
                'description' => 'Optional delay before triggering the workflow',
            ],
            'once_per_customer' => [
                'type' => 'boolean',
                'required' => false,
                'default' => false,
                'label' => 'Once per Customer',
                'description' => 'Only trigger once per customer for this event',
            ],
            'cooldown_hours' => [
                'type' => 'integer',
                'required' => false,
                'default' => 0,
                'min' => 0,
                'max' => 8760,
                'label' => 'Cooldown (hours)',
                'description' => 'Minimum hours between triggers for the same customer',
            ],
            'active_days' => [
                'type' => 'array',
                'required' => false,
                'label' => 'Active Days',
                'description' => 'Days of the week when this trigger is active',
                'options' => [
                    'monday' => 'Monday',
                    'tuesday' => 'Tuesday',
                    'wednesday' => 'Wednesday',
                    'thursday' => 'Thursday',
                    'friday' => 'Friday',
                    'saturday' => 'Saturday',
                    'sunday' => 'Sunday',
                ],
            ],
            'active_hours' => [
                'type' => 'object',
                'required' => false,
                'label' => 'Active Hours',
                'description' => 'Time range when this trigger is active',
                'properties' => [
                    'start' => [
                        'type' => 'string',
                        'pattern' => '/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/',
                        'label' => 'Start Time',
                        'placeholder' => '09:00',
                    ],
                    'end' => [
                        'type' => 'string',
                        'pattern' => '/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/',
                        'label' => 'End Time',
                        'placeholder' => '17:00',
                    ],
                ],
            ],
            'timezone' => [
                'type' => 'string',
                'required' => false,
                'default' => 'UTC',
                'label' => 'Timezone',
                'description' => 'Timezone for time-based conditions',
            ],
            'max_executions' => [
                'type' => 'integer',
                'required' => false,
                'default' => 0,
                'min' => 0,
                'label' => 'Max Executions',
                'description' => 'Maximum number of times this trigger can execute (0 = unlimited)',
            ],
            'enabled' => [
                'type' => 'boolean',
                'required' => false,
                'default' => true,
                'label' => 'Enabled',
                'description' => 'Whether this trigger is enabled',
            ],
            'priority' => [
                'type' => 'integer',
                'required' => false,
                'default' => 0,
                'min' => -100,
                'max' => 100,
                'label' => 'Priority',
                'description' => 'Trigger priority (higher numbers execute first)',
            ],
            'custom_event_name' => [
                'type' => 'string',
                'required' => false,
                'label' => 'Custom Event Name',
                'description' => 'Name for custom events',
                'max_length' => 100,
            ],
            'schedule_time' => [
                'type' => 'string',
                'required' => false,
                'label' => 'Schedule Time',
                'description' => 'Time for scheduled triggers (HH:MM format)',
                'pattern' => '/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/',
            ],
            'schedule_day' => [
                'type' => 'integer',
                'required' => false,
                'min' => 1,
                'max' => 31,
                'label' => 'Schedule Day',
                'description' => 'Day of month for monthly scheduled triggers',
            ],
            'schedule_weekday' => [
                'type' => 'string',
                'required' => false,
                'label' => 'Schedule Weekday',
                'description' => 'Day of week for weekly scheduled triggers',
                'options' => [
                    'monday' => 'Monday',
                    'tuesday' => 'Tuesday',
                    'wednesday' => 'Wednesday',
                    'thursday' => 'Thursday',
                    'friday' => 'Friday',
                    'saturday' => 'Saturday',
                    'sunday' => 'Sunday',
                ],
            ],
        ];
    }

    /**
     * Executes the trigger node logic.
     *
     * @param array $context
     * @return array
     */
    protected function executeNode(array $context): array
    {
        $this->eventData = $context;
        
        // Check if trigger is enabled
        if (!$this->getConfigValue('enabled', true)) {
            return [
                'success' => false,
                'error' => 'Trigger is disabled',
                'triggered' => false,
            ];
        }
        
        // Check if event matches
        if (!$this->matchesEvent($context)) {
            return [
                'success' => true,
                'triggered' => false,
                'reason' => 'Event does not match trigger configuration',
            ];
        }
        
        // Check execution limits
        if (!$this->checkExecutionLimits($context)) {
            return [
                'success' => true,
                'triggered' => false,
                'reason' => 'Execution limits reached',
            ];
        }
        
        // Check cooldown
        if (!$this->checkCooldown($context)) {
            return [
                'success' => true,
                'triggered' => false,
                'reason' => 'Cooldown period active',
            ];
        }
        
        // Check time restrictions
        if (!$this->checkTimeRestrictions()) {
            return [
                'success' => true,
                'triggered' => false,
                'reason' => 'Outside active time window',
            ];
        }
        
        // Evaluate conditions
        if (!$this->evaluateConditions($context)) {
            return [
                'success' => true,
                'triggered' => false,
                'reason' => 'Trigger conditions not met',
                'condition_results' => $this->evaluationResults,
            ];
        }
        
        // Handle delay
        $delay = $this->getConfigValue('delay_seconds', 0);
        if ($delay > 0) {
            $this->scheduleDelayedTrigger($context, $delay);
            return [
                'success' => true,
                'triggered' => true,
                'delayed' => true,
                'delay_seconds' => $delay,
                'scheduled_at' => DateHelper::nowObject()->modify("+{$delay} seconds")->format('Y-m-d H:i:s'),
            ];
        }
        
        // Record trigger execution
        $this->recordTriggerExecution($context);
        
        // Trigger is successful
        $this->conditionsMet = true;
        
        return [
            'success' => true,
            'triggered' => true,
            'event' => $this->getConfigValue('event'),
            'customer_id' => $context['customer_id'] ?? null,
            'timestamp' => DateHelper::now(),
            'output' => $this->prepareTriggerOutput($context),
        ];
    }

    /**
     * Checks if the current event matches the trigger configuration.
     *
     * @param array $context
     * @return bool
     */
    protected function matchesEvent(array $context): bool
    {
        $triggerEvent = $this->getConfigValue('event');
        $contextEvent = $context['event'] ?? '';
        
        // Handle custom events
        if ($triggerEvent === 'custom.event') {
            $customEventName = $this->getConfigValue('custom_event_name');
            return $contextEvent === $customEventName;
        }
        
        // Handle scheduled events
        if (strpos($triggerEvent, 'scheduled.') === 0) {
            return $this->matchesScheduledEvent($triggerEvent, $context);
        }
        
        // Direct event match
        return $contextEvent === $triggerEvent;
    }

    /**
     * Checks if scheduled event matches current time.
     *
     * @param string $triggerEvent
     * @param array $context
     * @return bool
     */
    protected function matchesScheduledEvent(string $triggerEvent, array $context): bool
    {
        $timezone = $this->getConfigValue('timezone', 'UTC');
        $now = DateHelper::nowObject(new \DateTimeZone($timezone));
        
        switch ($triggerEvent) {
            case 'scheduled.daily':
                $scheduleTime = $this->getConfigValue('schedule_time', '09:00');
                return $now->format('H:i') === $scheduleTime;
                
            case 'scheduled.weekly':
                $scheduleTime = $this->getConfigValue('schedule_time', '09:00');
                $scheduleWeekday = $this->getConfigValue('schedule_weekday', 'monday');
                return $now->format('H:i') === $scheduleTime && 
                       strtolower($now->format('l')) === $scheduleWeekday;
                
            case 'scheduled.monthly':
                $scheduleTime = $this->getConfigValue('schedule_time', '09:00');
                $scheduleDay = $this->getConfigValue('schedule_day', 1);
                return $now->format('H:i') === $scheduleTime && 
                       $now->format('j') == $scheduleDay;
        }
        
        return false;
    }

    /**
     * Checks execution limits.
     *
     * @param array $context
     * @return bool
     */
    protected function checkExecutionLimits(array $context): bool
    {
        $maxExecutions = $this->getConfigValue('max_executions', 0);
        if ($maxExecutions === 0) {
            return true; // No limit
        }
        
        $currentExecutions = $this->getTriggerExecutionCount();
        return $currentExecutions < $maxExecutions;
    }

    /**
     * Checks cooldown period.
     *
     * @param array $context
     * @return bool
     */
    protected function checkCooldown(array $context): bool
    {
        $cooldownHours = $this->getConfigValue('cooldown_hours', 0);
        if ($cooldownHours === 0) {
            return true; // No cooldown
        }
        
        $customerId = $context['customer_id'] ?? null;
        if (!$customerId) {
            return true; // No customer to check cooldown for
        }
        
        $oncePerCustomer = $this->getConfigValue('once_per_customer', false);
        if ($oncePerCustomer) {
            return !$this->hasTriggeredForCustomer($customerId);
        }
        
        $lastExecution = $this->getLastTriggerExecution($customerId);
        if (!$lastExecution) {
            return true; // No previous execution
        }
        
        $cooldownEnd = DateHelper::add($lastExecution, "+{$cooldownHours} hours");
        return DateHelper::nowObject() >= $cooldownEnd;
    }

    /**
     * Checks time restrictions.
     *
     * @return bool
     */
    protected function checkTimeRestrictions(): bool
    {
        // Check active days
        $activeDays = $this->getConfigValue('active_days', []);
        if (!empty($activeDays)) {
            $timezone = $this->getConfigValue('timezone', 'UTC');
            $now = DateHelper::nowObject(new \DateTimeZone($timezone));
            $currentDay = strtolower($now->format('l'));
            
            if (!in_array($currentDay, $activeDays)) {
                return false;
            }
        }
        
        // Check active hours
        $activeHours = $this->getConfigValue('active_hours', []);
        if (!empty($activeHours['start']) && !empty($activeHours['end'])) {
            $timezone = $this->getConfigValue('timezone', 'UTC');
            $now = DateHelper::nowObject(new \DateTimeZone($timezone));
            $currentTime = $now->format('H:i');
            
            if ($currentTime < $activeHours['start'] || $currentTime > $activeHours['end']) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Evaluates trigger conditions.
     *
     * @param array $context
     * @return bool
     */
    protected function evaluateConditions(array $context): bool
    {
        $conditions = $this->getConfigValue('conditions', []);
        if (empty($conditions)) {
            return true; // No conditions = always true
        }
        
        $this->evaluationResults = [];
        
        foreach ($conditions as $condition) {
            $result = $this->evaluateCondition($condition, $context);
            $this->evaluationResults[] = [
                'condition' => $condition,
                'result' => $result,
            ];
            
            if (!$result) {
                return false; // All conditions must be true
            }
        }
        
        return true;
    }

    /**
     * Evaluates a single condition.
     *
     * @param array $condition
     * @param array $context
     * @return bool
     */
    protected function evaluateCondition(array $condition, array $context): bool
    {
        $field = $condition['field'] ?? '';
        $operator = $condition['operator'] ?? '=';
        $value = $condition['value'] ?? '';
        
        $contextValue = ArrayHelper::get($context, $field);
        
        switch ($operator) {
            case '=':
            case '==':
                return $contextValue == $value;
                
            case '!=':
            case '<>':
                return $contextValue != $value;
                
            case '>':
                return $contextValue > $value;
                
            case '>=':
                return $contextValue >= $value;
                
            case '<':
                return $contextValue < $value;
                
            case '<=':
                return $contextValue <= $value;
                
            case 'contains':
                return strpos((string)$contextValue, (string)$value) !== false;
                
            case 'not_contains':
                return strpos((string)$contextValue, (string)$value) === false;
                
            case 'in':
                return in_array($contextValue, (array)$value);
                
            case 'not_in':
                return !in_array($contextValue, (array)$value);
                
            case 'regex':
                return preg_match($value, (string)$contextValue);
                
            case 'empty':
                return empty($contextValue);
                
            case 'not_empty':
                return !empty($contextValue);
                
            default:
                return false;
        }
    }

    /**
     * Schedules a delayed trigger.
     *
     * @param array $context
     * @param int $delay
     * @return void
     */
    protected function scheduleDelayedTrigger(array $context, int $delay): void
    {
        $scheduleTime = DateHelper::nowObject()->modify("+{$delay} seconds")->format('Y-m-d H:i:s');
        
        // Insert scheduled trigger execution
        $this->registry->get('db')->query("
            INSERT INTO `mas_trigger_schedule` SET
            `workflow_id` = '" . $this->registry->get('db')->escape($context['workflow_id'] ?? '') . "',
            `trigger_id` = '" . $this->registry->get('db')->escape($this->id) . "',
            `customer_id` = '" . (int)($context['customer_id'] ?? 0) . "',
            `event` = '" . $this->registry->get('db')->escape($this->getConfigValue('event')) . "',
            `context` = '" . $this->registry->get('db')->escape(json_encode($context)) . "',
            `scheduled_at` = '" . $this->registry->get('db')->escape($scheduleTime) . "',
            `status` = 'scheduled',
            `created_at` = NOW()
        ");
    }

    /**
     * Records trigger execution.
     *
     * @param array $context
     * @return void
     */
    protected function recordTriggerExecution(array $context): void
    {
        $this->registry->get('db')->query("
            INSERT INTO `mas_trigger_execution` SET
            `trigger_id` = '" . $this->registry->get('db')->escape($this->id) . "',
            `workflow_id` = '" . $this->registry->get('db')->escape($context['workflow_id'] ?? '') . "',
            `customer_id` = '" . (int)($context['customer_id'] ?? 0) . "',
            `event` = '" . $this->registry->get('db')->escape($this->getConfigValue('event')) . "',
            `context` = '" . $this->registry->get('db')->escape(json_encode($context)) . "',
            `executed_at` = NOW(),
            `created_at` = NOW()
        ");
    }

    /**
     * Prepares trigger output data.
     *
     * @param array $context
     * @return array
     */
    protected function prepareTriggerOutput(array $context): array
    {
        $output = [
            'trigger_event' => $this->getConfigValue('event'),
            'trigger_id' => $this->id,
            'triggered_at' => DateHelper::now(),
        ];
        
        // Add relevant context data
        if (isset($context['customer_id'])) {
            $output['customer_id'] = $context['customer_id'];
        }
        
        if (isset($context['order_id'])) {
            $output['order_id'] = $context['order_id'];
        }
        
        if (isset($context['product_id'])) {
            $output['product_id'] = $context['product_id'];
        }
        
        if (isset($context['cart_id'])) {
            $output['cart_id'] = $context['cart_id'];
        }
        
        return $output;
    }

    /**
     * Gets trigger execution count.
     *
     * @return int
     */
    protected function getTriggerExecutionCount(): int
    {
        $query = $this->registry->get('db')->query("
            SELECT COUNT(*) as count
            FROM `mas_trigger_execution`
            WHERE `trigger_id` = '" . $this->registry->get('db')->escape($this->id) . "'
        ");
        
        return (int)$query->row['count'];
    }

    /**
     * Checks if trigger has been executed for a customer.
     *
     * @param int $customerId
     * @return bool
     */
    protected function hasTriggeredForCustomer(int $customerId): bool
    {
        $query = $this->registry->get('db')->query("
            SELECT COUNT(*) as count
            FROM `mas_trigger_execution`
            WHERE `trigger_id` = '" . $this->registry->get('db')->escape($this->id) . "'
            AND `customer_id` = '" . (int)$customerId . "'
        ");
        
        return $query->row['count'] > 0;
    }

    /**
     * Gets last trigger execution time for a customer.
     *
     * @param int $customerId
     * @return \DateTime|null
     */
    protected function getLastTriggerExecution(int $customerId): ?\DateTime
    {
        $query = $this->registry->get('db')->query("
            SELECT `executed_at`
            FROM `mas_trigger_execution`
            WHERE `trigger_id` = '" . $this->registry->get('db')->escape($this->id) . "'
            AND `customer_id` = '" . (int)$customerId . "'
            ORDER BY `executed_at` DESC
            LIMIT 1
        ");
        
        if ($query->num_rows) {
            return DateHelper::parse($query->row['executed_at']);
        }
        
        return null;
    }

    /**
     * Custom validation for trigger node.
     *
     * @return void
     */
    protected function validateCustom(): void
    {
        $event = $this->getConfigValue('event');
        
        // Validate event is supported
        if (!in_array($event, $this->supportedEvents)) {
            $this->addValidationError("Unsupported event: {$event}");
        }
        
        // Validate custom event name
        if ($event === 'custom.event') {
            $customEventName = $this->getConfigValue('custom_event_name');
            if (empty($customEventName)) {
                $this->addValidationError('Custom event name is required for custom events');
            }
        }
        
        // Validate scheduled event configuration
        if (strpos($event, 'scheduled.') === 0) {
            $scheduleTime = $this->getConfigValue('schedule_time');
            if (empty($scheduleTime)) {
                $this->addValidationError('Schedule time is required for scheduled events');
            }
            
            if ($event === 'scheduled.weekly') {
                $scheduleWeekday = $this->getConfigValue('schedule_weekday');
                if (empty($scheduleWeekday)) {
                    $this->addValidationError('Schedule weekday is required for weekly scheduled events');
                }
            }
            
            if ($event === 'scheduled.monthly') {
                $scheduleDay = $this->getConfigValue('schedule_day');
                if (empty($scheduleDay)) {
                    $this->addValidationError('Schedule day is required for monthly scheduled events');
                }
            }
        }
        
        // Validate active hours
        $activeHours = $this->getConfigValue('active_hours', []);
        if (!empty($activeHours['start']) && !empty($activeHours['end'])) {
            if ($activeHours['start'] >= $activeHours['end']) {
                $this->addValidationError('Active hours end time must be after start time');
            }
        }
        
        // Validate conditions
        $conditions = $this->getConfigValue('conditions', []);
        foreach ($conditions as $index => $condition) {
            if (empty($condition['field'])) {
                $this->addValidationError("Condition {$index}: field is required");
            }
            
            if (empty($condition['operator'])) {
                $this->addValidationError("Condition {$index}: operator is required");
            }
            
            if (!isset($condition['value'])) {
                $this->addValidationError("Condition {$index}: value is required");
            }
        }
    }

    /**
     * Checks if trigger conditions are met.
     *
     * @return bool
     */
    public function areConditionsMet(): bool
    {
        return $this->conditionsMet;
    }

    /**
     * Gets evaluation results.
     *
     * @return array
     */
    public function getEvaluationResults(): array
    {
        return $this->evaluationResults;
    }

    /**
     * Gets supported events.
     *
     * @return array
     */
    public function getSupportedEvents(): array
    {
        return $this->supportedEvents;
    }

    /**
     * Checks if event is supported.
     *
     * @param string $event
     * @return bool
     */
    public function isEventSupported(string $event): bool
    {
        return in_array($event, $this->supportedEvents);
    }

    /**
     * Processes scheduled triggers.
     *
     * @return array
     */
    public static function processScheduledTriggers(): array
    {
        $db = Registry::getInstance()->get('db');
        $processed = [];
        
        $query = $db->query("
            SELECT * FROM `mas_trigger_schedule`
            WHERE `status` = 'scheduled'
            AND `scheduled_at` <= NOW()
            ORDER BY `scheduled_at` ASC
            LIMIT 100
        ");
        
        foreach ($query->rows as $row) {
            try {
                $context = json_decode($row['context'], true);
                $context['scheduled_trigger'] = true;
                $context['original_schedule_id'] = $row['id'];
                
                // Execute workflow with the scheduled context
                $workflowManager = MAS::getInstance()->getContainer()->get('mas.workflow_manager');
                $result = $workflowManager->executeWorkflow($row['workflow_id'], $context);
                
                // Update schedule status
                $db->query("
                    UPDATE `mas_trigger_schedule`
                    SET `status` = 'completed', `completed_at` = NOW()
                    WHERE `id` = '" . (int)$row['id'] . "'
                ");
                
                $processed[] = $result;
                
            } catch (\Exception $e) {
                // Update schedule status to failed
                $db->query("
                    UPDATE `mas_trigger_schedule`
                    SET `status` = 'failed', `error_message` = '" . $db->escape($e->getMessage()) . "', `completed_at` = NOW()
                    WHERE `id` = '" . (int)$row['id'] . "'
                ");
                
                $processed[] = [
                    'success' => false,
                    'schedule_id' => $row['id'],
                    'error' => $e->getMessage(),
                ];
            }
        }
        
        return $processed;
    }

    /**
     * Cleans up old trigger executions.
     *
     * @param int $daysOld
     * @return int
     */
    public static function cleanupOldExecutions(int $daysOld = 90): int
    {
        $db = Registry::getInstance()->get('db');
        $cutoffDate = DateHelper::nowObject()->modify("-{$daysOld} days")->format('Y-m-d H:i:s');
        
        $db->query("
            DELETE FROM `mas_trigger_execution`
            WHERE `executed_at` < '" . $db->escape($cutoffDate) . "'
        ");
        
        return $db->countAffected();
    }

    /**
     * Gets trigger statistics.
     *
     * @param string $triggerId
     * @param int $days
     * @return array
     */
    public static function getTriggerStatistics(string $triggerId, int $days = 30): array
    {
        $db = Registry::getInstance()->get('db');
        $startDate = DateHelper::nowObject()->modify("-{$days} days")->format('Y-m-d H:i:s');
        
        $query = $db->query("
            SELECT 
                COUNT(*) as total_executions,
                COUNT(DISTINCT customer_id) as unique_customers,
                DATE(executed_at) as date,
                event
            FROM `mas_trigger_execution`
            WHERE `trigger_id` = '" . $db->escape($triggerId) . "'
            AND `executed_at` >= '" . $db->escape($startDate) . "'
            GROUP BY DATE(executed_at), event
            ORDER BY date DESC
        ");
        
        return $query->rows;
    }
}
