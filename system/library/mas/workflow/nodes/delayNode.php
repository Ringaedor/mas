<?php
/**
 * MAS - Marketing Automation Suite
 * DelayNode
 *
 * Implements delay functionality in workflows, supporting various delay types:
 * fixed delays, dynamic delays based on context, scheduled delays, and timezone-aware delays.
 * Handles delay persistence, scheduling, and integration with the queue system.
 *
 * @copyright Copyright (c) 2025, Your Company
 * @license   Proprietary
 */

namespace Opencart\Library\Mas\Workflow\Node;

use Opencart\Library\Mas\Workflow\Node\AbstractNode;
use Opencart\Library\Mas\Exception\WorkflowException;
use Opencart\Library\Mas\Exception\ValidationException;
use Opencart\Library\Mas\Helper\ArrayHelper;
use Opencart\Library\Mas\Helper\DateHelper;
use DateTimeZone;
use DateTime;

class DelayNode extends AbstractNode
{
    /**
     * @var string Node version
     */
    protected string $version = '1.0.0';

    /**
     * @var array Supported delay types
     */
    protected array $supportedDelayTypes = [
        'fixed',
        'dynamic',
        'scheduled',
        'conditional',
        'random',
    ];

    /**
     * @var array Supported time units
     */
    protected array $supportedTimeUnits = [
        'seconds',
        'minutes',
        'hours',
        'days',
        'weeks',
        'months',
    ];

    /**
     * @var array Supported schedule types
     */
    protected array $supportedScheduleTypes = [
        'specific_time',
        'next_day',
        'next_week',
        'next_month',
        'business_hours',
        'optimal_time',
    ];

    /**
     * @var DateTime|null Calculated delay end time
     */
    protected ?DateTime $delayEndTime = null;

    /**
     * @var array Delay calculation metadata
     */
    protected array $delayMetadata = [];

    /**
     * Returns the node type.
     *
     * @return string
     */
    public static function getType(): string
    {
        return 'delay';
    }

    /**
     * Returns a human-readable label for the node.
     *
     * @return string
     */
    public function getLabel(): string
    {
        $delayType = $this->getConfigValue('delay_type', 'fixed');
        $amount = $this->getConfigValue('delay_amount', 0);
        $unit = $this->getConfigValue('delay_unit', 'minutes');
        
        switch ($delayType) {
            case 'fixed':
                return "Delay: {$amount} {$unit}";
            case 'dynamic':
                return "Delay: Dynamic based on context";
            case 'scheduled':
                return "Delay: Until scheduled time";
            case 'conditional':
                return "Delay: Conditional based on conditions";
            case 'random':
                return "Delay: Random delay";
            default:
                return "Delay: {$delayType}";
        }
    }

    /**
     * Returns a description of the node.
     *
     * @return string
     */
    public function getDescription(): string
    {
        $description = $this->getConfigValue('description');
        if ($description) {
            return $description;
        }

        $delayType = $this->getConfigValue('delay_type', 'fixed');
        return "Pauses workflow execution with {$delayType} delay";
    }

    /**
     * Returns the configuration schema for the delay node.
     *
     * @return array
     */
    public static function getConfigSchema(): array
    {
        return [
            'delay_type' => [
                'type' => 'select',
                'required' => true,
                'default' => 'fixed',
                'label' => 'Delay Type',
                'description' => 'Type of delay to apply',
                'options' => [
                    'fixed' => 'Fixed Delay',
                    'dynamic' => 'Dynamic Delay',
                    'scheduled' => 'Scheduled Delay',
                    'conditional' => 'Conditional Delay',
                    'random' => 'Random Delay',
                ],
            ],
            'delay_amount' => [
                'type' => 'integer',
                'required' => false,
                'default' => 5,
                'min' => 0,
                'max' => 99999,
                'label' => 'Delay Amount',
                'description' => 'Amount of time to delay',
            ],
            'delay_unit' => [
                'type' => 'select',
                'required' => false,
                'default' => 'minutes',
                'label' => 'Time Unit',
                'description' => 'Unit of time for the delay',
                'options' => [
                    'seconds' => 'Seconds',
                    'minutes' => 'Minutes',
                    'hours' => 'Hours',
                    'days' => 'Days',
                    'weeks' => 'Weeks',
                    'months' => 'Months',
                ],
            ],
            'min_delay_amount' => [
                'type' => 'integer',
                'required' => false,
                'default' => 1,
                'min' => 0,
                'label' => 'Minimum Delay (for random)',
                'description' => 'Minimum delay amount for random delays',
            ],
            'max_delay_amount' => [
                'type' => 'integer',
                'required' => false,
                'default' => 10,
                'min' => 1,
                'label' => 'Maximum Delay (for random)',
                'description' => 'Maximum delay amount for random delays',
            ],
            'dynamic_formula' => [
                'type' => 'string',
                'required' => false,
                'label' => 'Dynamic Formula',
                'description' => 'Formula for calculating dynamic delays (e.g., "customer.order_count * 2")',
                'max_length' => 500,
            ],
            'schedule_type' => [
                'type' => 'select',
                'required' => false,
                'default' => 'specific_time',
                'label' => 'Schedule Type',
                'description' => 'Type of scheduled delay',
                'options' => [
                    'specific_time' => 'Specific Time',
                    'next_day' => 'Next Day',
                    'next_week' => 'Next Week',
                    'next_month' => 'Next Month',
                    'business_hours' => 'Business Hours Only',
                    'optimal_time' => 'Optimal Send Time',
                ],
            ],
            'schedule_time' => [
                'type' => 'string',
                'required' => false,
                'label' => 'Schedule Time',
                'description' => 'Time for scheduled delays (HH:MM format)',
                'pattern' => '/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/',
                'placeholder' => '09:00',
            ],
            'schedule_date' => [
                'type' => 'date',
                'required' => false,
                'label' => 'Schedule Date',
                'description' => 'Specific date for scheduled delays',
            ],
            'timezone' => [
                'type' => 'select',
                'required' => false,
                'default' => 'UTC',
                'label' => 'Timezone',
                'description' => 'Timezone for delay calculations',
                'options' => [
                    'UTC' => 'UTC',
                    'America/New_York' => 'Eastern Time',
                    'America/Chicago' => 'Central Time',
                    'America/Denver' => 'Mountain Time',
                    'America/Los_Angeles' => 'Pacific Time',
                    'Europe/London' => 'GMT',
                    'Europe/Paris' => 'Central European Time',
                    'Europe/Berlin' => 'Central European Time',
                    'Asia/Tokyo' => 'Japan Standard Time',
                    'Asia/Shanghai' => 'China Standard Time',
                    'Australia/Sydney' => 'Australian Eastern Time',
                ],
            ],
            'business_hours_start' => [
                'type' => 'string',
                'required' => false,
                'default' => '09:00',
                'label' => 'Business Hours Start',
                'description' => 'Start time for business hours',
                'pattern' => '/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/',
            ],
            'business_hours_end' => [
                'type' => 'string',
                'required' => false,
                'default' => '17:00',
                'label' => 'Business Hours End',
                'description' => 'End time for business hours',
                'pattern' => '/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/',
            ],
            'business_days' => [
                'type' => 'array',
                'required' => false,
                'default' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
                'label' => 'Business Days',
                'description' => 'Days considered as business days',
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
            'conditions' => [
                'type' => 'array',
                'required' => false,
                'label' => 'Conditions',
                'description' => 'Conditions for conditional delays',
            ],
            'skip_weekends' => [
                'type' => 'boolean',
                'required' => false,
                'default' => false,
                'label' => 'Skip Weekends',
                'description' => 'Skip weekends when calculating delays',
            ],
            'skip_holidays' => [
                'type' => 'boolean',
                'required' => false,
                'default' => false,
                'label' => 'Skip Holidays',
                'description' => 'Skip holidays when calculating delays',
            ],
            'holiday_list' => [
                'type' => 'array',
                'required' => false,
                'label' => 'Holiday List',
                'description' => 'List of holiday dates to skip (YYYY-MM-DD format)',
            ],
            'max_delay_days' => [
                'type' => 'integer',
                'required' => false,
                'default' => 30,
                'min' => 1,
                'max' => 365,
                'label' => 'Maximum Delay Days',
                'description' => 'Maximum number of days to delay (safety limit)',
            ],
            'retry_failed_schedule' => [
                'type' => 'boolean',
                'required' => false,
                'default' => true,
                'label' => 'Retry Failed Schedule',
                'description' => 'Retry if scheduled time calculation fails',
            ],
            'context_variable' => [
                'type' => 'string',
                'required' => false,
                'label' => 'Context Variable',
                'description' => 'Context variable to use for dynamic delays',
                'max_length' => 100,
            ],
            'description' => [
                'type' => 'string',
                'required' => false,
                'label' => 'Description',
                'description' => 'Optional description of this delay node',
                'max_length' => 255,
            ],
            'enabled' => [
                'type' => 'boolean',
                'required' => false,
                'default' => true,
                'label' => 'Enabled',
                'description' => 'Whether this delay node is enabled',
            ],
        ];
    }

    /**
     * Executes the delay node logic.
     *
     * @param array $context
     * @return array
     */
    protected function executeNode(array $context): array
    {
        if (!$this->getConfigValue('enabled', true)) {
            return [
                'success' => true,
                'delayed' => false,
                'reason' => 'Delay node is disabled',
            ];
        }

        $delayType = $this->getConfigValue('delay_type', 'fixed');

        try {
            // Calculate delay end time
            $this->delayEndTime = $this->calculateDelayEndTime($delayType, $context);

            // Check if delay is immediate (no delay needed)
            if ($this->delayEndTime <= DateHelper::nowObject()) {
                return [
                    'success' => true,
                    'delayed' => false,
                    'reason' => 'No delay needed',
                    'calculated_end_time' => $this->delayEndTime->format('Y-m-d H:i:s'),
                ];
            }

            // Schedule continuation of workflow
            $this->scheduleWorkflowContinuation($context);

            return [
                'success' => true,
                'delayed' => true,
                'delay_type' => $delayType,
                'delay_end_time' => $this->delayEndTime->format('Y-m-d H:i:s'),
                'delay_seconds' => $this->delayEndTime->getTimestamp() - time(),
                'metadata' => $this->delayMetadata,
                'output' => [
                    'delayed_until' => $this->delayEndTime->format('Y-m-d H:i:s'),
                    'delay_node_id' => $this->id,
                ],
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'delayed' => false,
            ];
        }
    }

    /**
     * Calculates the delay end time based on delay type and context.
     *
     * @param string $delayType
     * @param array $context
     * @return DateTime
     * @throws WorkflowException
     */
    protected function calculateDelayEndTime(string $delayType, array $context): DateTime
    {
        $timezone = new DateTimeZone($this->getConfigValue('timezone', 'UTC'));
        $now = DateHelper::nowObject($timezone);

        switch ($delayType) {
            case 'fixed':
                return $this->calculateFixedDelay($now);

            case 'dynamic':
                return $this->calculateDynamicDelay($now, $context);

            case 'scheduled':
                return $this->calculateScheduledDelay($now, $context);

            case 'conditional':
                return $this->calculateConditionalDelay($now, $context);

            case 'random':
                return $this->calculateRandomDelay($now);

            default:
                throw new WorkflowException("Unsupported delay type: {$delayType}");
        }
    }

    /**
     * Calculates fixed delay.
     *
     * @param DateTime $now
     * @return DateTime
     */
    protected function calculateFixedDelay(DateTime $now): DateTime
    {
        $amount = $this->getConfigValue('delay_amount', 5);
        $unit = $this->getConfigValue('delay_unit', 'minutes');

        $delayEnd = clone $now;
        
        switch ($unit) {
            case 'seconds':
                $delayEnd->modify("+{$amount} seconds");
                break;
            case 'minutes':
                $delayEnd->modify("+{$amount} minutes");
                break;
            case 'hours':
                $delayEnd->modify("+{$amount} hours");
                break;
            case 'days':
                $delayEnd->modify("+{$amount} days");
                break;
            case 'weeks':
                $delayEnd->modify("+{$amount} weeks");
                break;
            case 'months':
                $delayEnd->modify("+{$amount} months");
                break;
        }

        $this->delayMetadata = [
            'type' => 'fixed',
            'amount' => $amount,
            'unit' => $unit,
            'calculated_at' => $now->format('Y-m-d H:i:s'),
        ];

        return $this->applyBusinessRules($delayEnd);
    }

    /**
     * Calculates dynamic delay based on context.
     *
     * @param DateTime $now
     * @param array $context
     * @return DateTime
     * @throws WorkflowException
     */
    protected function calculateDynamicDelay(DateTime $now, array $context): DateTime
    {
        $formula = $this->getConfigValue('dynamic_formula');
        $contextVariable = $this->getConfigValue('context_variable');
        $unit = $this->getConfigValue('delay_unit', 'minutes');

        $amount = 0;

        if ($formula) {
            $amount = $this->evaluateFormula($formula, $context);
        } elseif ($contextVariable) {
            $amount = ArrayHelper::get($context, $contextVariable, 0);
        }

        // Ensure amount is positive and within limits
        $amount = max(0, min($amount, 99999));

        $delayEnd = clone $now;
        $delayEnd->modify("+{$amount} {$unit}");

        $this->delayMetadata = [
            'type' => 'dynamic',
            'formula' => $formula,
            'context_variable' => $contextVariable,
            'calculated_amount' => $amount,
            'unit' => $unit,
            'calculated_at' => $now->format('Y-m-d H:i:s'),
        ];

        return $this->applyBusinessRules($delayEnd);
    }

    /**
     * Calculates scheduled delay.
     *
     * @param DateTime $now
     * @param array $context
     * @return DateTime
     * @throws WorkflowException
     */
    protected function calculateScheduledDelay(DateTime $now, array $context): DateTime
    {
        $scheduleType = $this->getConfigValue('schedule_type', 'specific_time');
        $scheduleTime = $this->getConfigValue('schedule_time', '09:00');
        $scheduleDate = $this->getConfigValue('schedule_date');

        switch ($scheduleType) {
            case 'specific_time':
                if ($scheduleDate) {
                    $delayEnd = new DateTime($scheduleDate . ' ' . $scheduleTime, $now->getTimezone());
                } else {
                    $delayEnd = clone $now;
                    $delayEnd->setTime(...explode(':', $scheduleTime));
                    
                    // If time has passed today, schedule for tomorrow
                    if ($delayEnd <= $now) {
                        $delayEnd->modify('+1 day');
                    }
                }
                break;

            case 'next_day':
                $delayEnd = clone $now;
                $delayEnd->modify('+1 day');
                $delayEnd->setTime(...explode(':', $scheduleTime));
                break;

            case 'next_week':
                $delayEnd = clone $now;
                $delayEnd->modify('next monday');
                $delayEnd->setTime(...explode(':', $scheduleTime));
                break;

            case 'next_month':
                $delayEnd = clone $now;
                $delayEnd->modify('first day of next month');
                $delayEnd->setTime(...explode(':', $scheduleTime));
                break;

            case 'business_hours':
                $delayEnd = $this->calculateNextBusinessTime($now);
                break;

            case 'optimal_time':
                $delayEnd = $this->calculateOptimalSendTime($now, $context);
                break;

            default:
                throw new WorkflowException("Unsupported schedule type: {$scheduleType}");
        }

        $this->delayMetadata = [
            'type' => 'scheduled',
            'schedule_type' => $scheduleType,
            'schedule_time' => $scheduleTime,
            'schedule_date' => $scheduleDate,
            'calculated_at' => $now->format('Y-m-d H:i:s'),
        ];

        return $this->applyBusinessRules($delayEnd);
    }

    /**
     * Calculates conditional delay.
     *
     * @param DateTime $now
     * @param array $context
     * @return DateTime
     * @throws WorkflowException
     */
    protected function calculateConditionalDelay(DateTime $now, array $context): DateTime
    {
        $conditions = $this->getConfigValue('conditions', []);
        $defaultAmount = $this->getConfigValue('delay_amount', 5);
        $unit = $this->getConfigValue('delay_unit', 'minutes');

        $amount = $defaultAmount;

        foreach ($conditions as $condition) {
            if ($this->evaluateCondition($condition, $context)) {
                $amount = $condition['delay_amount'] ?? $defaultAmount;
                break;
            }
        }

        $delayEnd = clone $now;
        $delayEnd->modify("+{$amount} {$unit}");

        $this->delayMetadata = [
            'type' => 'conditional',
            'conditions_evaluated' => count($conditions),
            'calculated_amount' => $amount,
            'unit' => $unit,
            'calculated_at' => $now->format('Y-m-d H:i:s'),
        ];

        return $this->applyBusinessRules($delayEnd);
    }

    /**
     * Calculates random delay.
     *
     * @param DateTime $now
     * @return DateTime
     */
    protected function calculateRandomDelay(DateTime $now): DateTime
    {
        $minAmount = $this->getConfigValue('min_delay_amount', 1);
        $maxAmount = $this->getConfigValue('max_delay_amount', 10);
        $unit = $this->getConfigValue('delay_unit', 'minutes');

        $amount = random_int($minAmount, $maxAmount);

        $delayEnd = clone $now;
        $delayEnd->modify("+{$amount} {$unit}");

        $this->delayMetadata = [
            'type' => 'random',
            'min_amount' => $minAmount,
            'max_amount' => $maxAmount,
            'calculated_amount' => $amount,
            'unit' => $unit,
            'calculated_at' => $now->format('Y-m-d H:i:s'),
        ];

        return $this->applyBusinessRules($delayEnd);
    }

    /**
     * Calculates next business time.
     *
     * @param DateTime $now
     * @return DateTime
     */
    protected function calculateNextBusinessTime(DateTime $now): DateTime
    {
        $businessDays = $this->getConfigValue('business_days', ['monday', 'tuesday', 'wednesday', 'thursday', 'friday']);
        $businessStart = $this->getConfigValue('business_hours_start', '09:00');
        $businessEnd = $this->getConfigValue('business_hours_end', '17:00');

        $delayEnd = clone $now;
        $maxAttempts = 14; // Prevent infinite loops
        $attempts = 0;

        while ($attempts < $maxAttempts) {
            $dayName = strtolower($delayEnd->format('l'));
            
            if (in_array($dayName, $businessDays)) {
                $businessStartTime = clone $delayEnd;
                $businessStartTime->setTime(...explode(':', $businessStart));
                
                $businessEndTime = clone $delayEnd;
                $businessEndTime->setTime(...explode(':', $businessEnd));

                if ($delayEnd >= $businessStartTime && $delayEnd <= $businessEndTime) {
                    break; // Current time is within business hours
                } elseif ($delayEnd < $businessStartTime) {
                    $delayEnd = $businessStartTime;
                    break; // Schedule for business start time
                }
            }

            // Move to next day
            $delayEnd->modify('+1 day');
            $delayEnd->setTime(...explode(':', $businessStart));
            $attempts++;
        }

        return $delayEnd;
    }

    /**
     * Calculates optimal send time based on customer data.
     *
     * @param DateTime $now
     * @param array $context
     * @return DateTime
     */
    protected function calculateOptimalSendTime(DateTime $now, array $context): DateTime
    {
        $customerId = $context['customer_id'] ?? null;
        $optimalHour = 9; // Default to 9 AM

        if ($customerId) {
            // Get customer's optimal send time from analytics
            $optimalHour = $this->getCustomerOptimalSendTime($customerId);
        }

        $delayEnd = clone $now;
        $delayEnd->setTime($optimalHour, 0, 0);

        // If optimal time has passed today, schedule for tomorrow
        if ($delayEnd <= $now) {
            $delayEnd->modify('+1 day');
        }

        return $delayEnd;
    }

    /**
     * Applies business rules to delay end time.
     *
     * @param DateTime $delayEnd
     * @return DateTime
     */
    protected function applyBusinessRules(DateTime $delayEnd): DateTime
    {
        $maxDelayDays = $this->getConfigValue('max_delay_days', 30);
        $skipWeekends = $this->getConfigValue('skip_weekends', false);
        $skipHolidays = $this->getConfigValue('skip_holidays', false);

        // Apply maximum delay limit
        $maxDelayEnd = DateHelper::nowObject($delayEnd->getTimezone());
        $maxDelayEnd->modify("+{$maxDelayDays} days");

        if ($delayEnd > $maxDelayEnd) {
            $delayEnd = $maxDelayEnd;
        }

        // Skip weekends if configured
        if ($skipWeekends) {
            $delayEnd = $this->skipWeekends($delayEnd);
        }

        // Skip holidays if configured
        if ($skipHolidays) {
            $delayEnd = $this->skipHolidays($delayEnd);
        }

        return $delayEnd;
    }

    /**
     * Skips weekends in delay calculation.
     *
     * @param DateTime $delayEnd
     * @return DateTime
     */
    protected function skipWeekends(DateTime $delayEnd): DateTime
    {
        $dayOfWeek = $delayEnd->format('N'); // 1 = Monday, 7 = Sunday

        if ($dayOfWeek == 6) { // Saturday
            $delayEnd->modify('+2 days');
        } elseif ($dayOfWeek == 7) { // Sunday
            $delayEnd->modify('+1 day');
        }

        return $delayEnd;
    }

    /**
     * Skips holidays in delay calculation.
     *
     * @param DateTime $delayEnd
     * @return DateTime
     */
    protected function skipHolidays(DateTime $delayEnd): DateTime
    {
        $holidays = $this->getConfigValue('holiday_list', []);
        $dateString = $delayEnd->format('Y-m-d');

        if (in_array($dateString, $holidays)) {
            $delayEnd->modify('+1 day');
            // Recursively check if the next day is also a holiday
            return $this->skipHolidays($delayEnd);
        }

        return $delayEnd;
    }

    /**
     * Schedules workflow continuation after delay.
     *
     * @param array $context
     * @return void
     */
    protected function scheduleWorkflowContinuation(array $context): void
    {
        $this->registry->get('db')->query("
            INSERT INTO `mas_workflow_delay` SET
            `workflow_id` = '" . $this->registry->get('db')->escape($context['workflow_id'] ?? '') . "',
            `execution_id` = '" . $this->registry->get('db')->escape($context['execution_id'] ?? '') . "',
            `delay_node_id` = '" . $this->registry->get('db')->escape($this->id) . "',
            `customer_id` = '" . (int)($context['customer_id'] ?? 0) . "',
            `context` = '" . $this->registry->get('db')->escape(json_encode($context)) . "',
            `delay_type` = '" . $this->registry->get('db')->escape($this->getConfigValue('delay_type')) . "',
            `delay_end_time` = '" . $this->registry->get('db')->escape($this->delayEndTime->format('Y-m-d H:i:s')) . "',
            `metadata` = '" . $this->registry->get('db')->escape(json_encode($this->delayMetadata)) . "',
            `status` = 'scheduled',
            `created_at` = NOW()
        ");
    }

    /**
     * Evaluates a formula for dynamic delays.
     *
     * @param string $formula
     * @param array $context
     * @return int
     */
    protected function evaluateFormula(string $formula, array $context): int
    {
        // Simple formula evaluation - can be extended
        // For security, only allow basic math operations
        $allowedPattern = '/^[0-9\+\-\*\/\(\)\s\.]+$/';
        
        // Replace context variables
        $formula = preg_replace_callback('/(\w+\.\w+)/', function($matches) use ($context) {
            return ArrayHelper::get($context, $matches[1], 0);
        }, $formula);

        if (preg_match($allowedPattern, $formula)) {
            try {
                return (int)eval("return {$formula};");
            } catch (\Exception $e) {
                return 0;
            }
        }

        return 0;
    }

    /**
     * Evaluates a condition for conditional delays.
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
                return $contextValue == $value;
            case '!=':
                return $contextValue != $value;
            case '>':
                return $contextValue > $value;
            case '<':
                return $contextValue < $value;
            case '>=':
                return $contextValue >= $value;
            case '<=':
                return $contextValue <= $value;
            case 'contains':
                return strpos((string)$contextValue, (string)$value) !== false;
            case 'in':
                return in_array($contextValue, (array)$value);
            default:
                return false;
        }
    }

    /**
     * Gets customer's optimal send time from analytics.
     *
     * @param int $customerId
     * @return int
     */
    protected function getCustomerOptimalSendTime(int $customerId): int
    {
        // Query analytics data to find optimal send time
        $query = $this->registry->get('db')->query("
            SELECT AVG(HOUR(sent_at)) as optimal_hour
            FROM `mas_message_analytics`
            WHERE `customer_id` = '" . (int)$customerId . "'
            AND `opened` = 1
            AND `sent_at` >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");

        if ($query->num_rows && $query->row['optimal_hour']) {
            return (int)$query->row['optimal_hour'];
        }

        return 9; // Default to 9 AM
    }

    /**
     * Custom validation for delay node.
     *
     * @return void
     */
    protected function validateCustom(): void
    {
        $delayType = $this->getConfigValue('delay_type');

        if (!in_array($delayType, $this->supportedDelayTypes)) {
            $this->addValidationError("Unsupported delay type: {$delayType}");
        }

        $delayUnit = $this->getConfigValue('delay_unit');
        if ($delayUnit && !in_array($delayUnit, $this->supportedTimeUnits)) {
            $this->addValidationError("Unsupported time unit: {$delayUnit}");
        }

        // Validate specific delay type requirements
        switch ($delayType) {
            case 'fixed':
                if ($this->getConfigValue('delay_amount') <= 0) {
                    $this->addValidationError('Fixed delay amount must be greater than 0');
                }
                break;

            case 'dynamic':
                if (!$this->getConfigValue('dynamic_formula') && !$this->getConfigValue('context_variable')) {
                    $this->addValidationError('Dynamic delay requires either formula or context variable');
                }
                break;

            case 'scheduled':
                $scheduleType = $this->getConfigValue('schedule_type');
                if (!in_array($scheduleType, $this->supportedScheduleTypes)) {
                    $this->addValidationError("Unsupported schedule type: {$scheduleType}");
                }
                break;

            case 'random':
                $minAmount = $this->getConfigValue('min_delay_amount', 1);
                $maxAmount = $this->getConfigValue('max_delay_amount', 10);
                if ($minAmount >= $maxAmount) {
                    $this->addValidationError('Random delay minimum must be less than maximum');
                }
                break;
        }

        // Validate business hours
        $businessStart = $this->getConfigValue('business_hours_start', '09:00');
        $businessEnd = $this->getConfigValue('business_hours_end', '17:00');
        
        if ($businessStart >= $businessEnd) {
            $this->addValidationError('Business hours end time must be after start time');
        }

        // Validate schedule time format
        $scheduleTime = $this->getConfigValue('schedule_time');
        if ($scheduleTime && !preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $scheduleTime)) {
            $this->addValidationError('Schedule time must be in HH:MM format');
        }
    }

    /**
     * Processes scheduled delay continuations.
     *
     * @return array
     */
    public static function processScheduledDelays(): array
    {
        $db = Registry::getInstance()->get('db');
        $processed = [];

        $query = $db->query("
            SELECT * FROM `mas_workflow_delay`
            WHERE `status` = 'scheduled'
            AND `delay_end_time` <= NOW()
            ORDER BY `delay_end_time` ASC
            LIMIT 100
        ");

        foreach ($query->rows as $row) {
            try {
                $context = json_decode($row['context'], true);
                $context['delayed_node_id'] = $row['delay_node_id'];
                $context['delay_completed'] = true;

                // Continue workflow execution
                $workflowManager = MAS::getInstance()->getContainer()->get('mas.workflow_manager');
                $result = $workflowManager->continueWorkflow($row['workflow_id'], $context);

                // Update delay status
                $db->query("
                    UPDATE `mas_workflow_delay`
                    SET `status` = 'completed', `completed_at` = NOW()
                    WHERE `id` = '" . (int)$row['id'] . "'
                ");

                $processed[] = $result;

            } catch (\Exception $e) {
                // Update delay status to failed
                $db->query("
                    UPDATE `mas_workflow_delay`
                    SET `status` = 'failed', `error_message` = '" . $db->escape($e->getMessage()) . "', `completed_at` = NOW()
                    WHERE `id` = '" . (int)$row['id'] . "'
                ");

                $processed[] = [
                    'success' => false,
                    'delay_id' => $row['id'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $processed;
    }

    /**
     * Cleans up old delay records.
     *
     * @param int $daysOld
     * @return int
     */
    public static function cleanupOldDelays(int $daysOld = 30): int
    {
        $db = Registry::getInstance()->get('db');
        $cutoffDate = DateHelper::nowObject()->modify("-{$daysOld} days")->format('Y-m-d H:i:s');

        $db->query("
            DELETE FROM `mas_workflow_delay`
            WHERE `status` IN ('completed', 'failed')
            AND `completed_at` < '" . $db->escape($cutoffDate) . "'
        ");

        return $db->countAffected();
    }

    /**
     * Gets delay statistics.
     *
     * @param string $nodeId
     * @param int $days
     * @return array
     */
    public static function getDelayStatistics(string $nodeId, int $days = 30): array
    {
        $db = Registry::getInstance()->get('db');
        $startDate = DateHelper::nowObject()->modify("-{$days} days")->format('Y-m-d H:i:s');

        $query = $db->query("
            SELECT 
                COUNT(*) as total_delays,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_delays,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_delays,
                AVG(TIMESTAMPDIFF(SECOND, created_at, completed_at)) as avg_delay_duration,
                delay_type
            FROM `mas_workflow_delay`
            WHERE `delay_node_id` = '" . $db->escape($nodeId) . "'
            AND `created_at` >= '" . $db->escape($startDate) . "'
            GROUP BY delay_type
        ");

        return $query->rows;
    }

    /**
     * Gets the calculated delay end time.
     *
     * @return DateTime|null
     */
    public function getDelayEndTime(): ?DateTime
    {
        return $this->delayEndTime;
    }

    /**
     * Gets the delay metadata.
     *
     * @return array
     */
    public function getDelayMetadata(): array
    {
        return $this->delayMetadata;
    }

    /**
     * Gets supported delay types.
     *
     * @return array
     */
    public function getSupportedDelayTypes(): array
    {
        return $this->supportedDelayTypes;
    }

    /**
     * Gets supported time units.
     *
     * @return array
     */
    public function getSupportedTimeUnits(): array
    {
        return $this->supportedTimeUnits;
    }

    /**
     * Checks if delay type is supported.
     *
     * @param string $delayType
     * @return bool
     */
    public function isDelayTypeSupported(string $delayType): bool
    {
        return in_array($delayType, $this->supportedDelayTypes);
    }
}
