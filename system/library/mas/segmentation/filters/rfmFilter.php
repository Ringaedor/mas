<?php
/**
 * MAS - Marketing Automation Suite
 * RFM Filter
 *
 * Implements RFM (Recency, Frequency, Monetary) analysis for customer segmentation.
 * Analyzes customer behavior based on:
 * - Recency: How recently a customer made a purchase
 * - Frequency: How often a customer makes purchases
 * - Monetary: How much money a customer spends
 *
 * Supports flexible scoring methods, customizable thresholds, and various
 * segmentation strategies for targeted marketing campaigns.
 *
 * @copyright Copyright (c) 2025, Your Company
 * @license   Proprietary
 */

namespace Opencart\Library\Mas\Segmentation\Filter;

use Opencart\Library\Mas\Interfaces\SegmentFilterInterface;
use Opencart\Library\Mas\Exception\SegmentException;
use Opencart\Library\Mas\Helper\ArrayHelper;
use Opencart\Library\Mas\Helper\DateHelper;

class RfmFilter implements SegmentFilterInterface
{
    /**
     * @var array Filter configuration
     */
    protected array $config = [];

    /**
     * @var array RFM scoring methods
     */
    protected array $scoringMethods = [
        'quintile',
        'quartile',
        'percentile',
        'fixed_threshold',
        'standard_deviation',
    ];

    /**
     * @var array RFM segment definitions
     */
    protected array $segmentDefinitions = [
        'champions' => ['r' => [4, 5], 'f' => [4, 5], 'm' => [4, 5]],
        'loyal_customers' => ['r' => [2, 5], 'f' => [3, 5], 'm' => [3, 5]],
        'potential_loyalists' => ['r' => [3, 5], 'f' => [1, 3], 'm' => [1, 3]],
        'recent_customers' => ['r' => [4, 5], 'f' => [0, 1], 'm' => [0, 1]],
        'promising' => ['r' => [3, 4], 'f' => [0, 1], 'm' => [0, 1]],
        'customers_needing_attention' => ['r' => [2, 3], 'f' => [2, 3], 'm' => [2, 3]],
        'about_to_sleep' => ['r' => [2, 3], 'f' => [0, 2], 'm' => [0, 2]],
        'at_risk' => ['r' => [0, 2], 'f' => [2, 5], 'm' => [2, 5]],
        'cannot_lose_them' => ['r' => [0, 1], 'f' => [4, 5], 'm' => [4, 5]],
        'hibernating' => ['r' => [1, 2], 'f' => [1, 2], 'm' => [1, 2]],
        'lost' => ['r' => [0, 2], 'f' => [0, 2], 'm' => [0, 4]],
    ];

    /**
     * @var array Validation errors
     */
    protected array $validationErrors = [];

    /**
     * Returns the unique filter type.
     *
     * @return string
     */
    public static function getType(): string
    {
        return 'rfm';
    }

    /**
     * Returns a human-readable label for this filter.
     *
     * @return string
     */
    public static function getLabel(): string
    {
        return 'RFM Analysis';
    }

    /**
     * Returns a description of the filter's purpose.
     *
     * @return string
     */
    public static function getDescription(): string
    {
        return 'Segments customers based on Recency, Frequency, and Monetary value of their purchases';
    }

    /**
     * Returns the schema for this filter's configuration.
     *
     * @return array
     */
    public static function getConfigSchema(): array
    {
        return [
            'analysis_period_days' => [
                'type' => 'integer',
                'required' => false,
                'default' => 365,
                'min' => 30,
                'max' => 1825,
                'label' => 'Analysis Period (Days)',
                'description' => 'Number of days to analyze for RFM calculation',
            ],
            'scoring_method' => [
                'type' => 'select',
                'required' => false,
                'default' => 'quintile',
                'label' => 'Scoring Method',
                'description' => 'Method to calculate RFM scores',
                'options' => [
                    'quintile' => 'Quintile (1-5 scale)',
                    'quartile' => 'Quartile (1-4 scale)',
                    'percentile' => 'Percentile (1-100 scale)',
                    'fixed_threshold' => 'Fixed Thresholds',
                    'standard_deviation' => 'Standard Deviation',
                ],
            ],
            'segment_type' => [
                'type' => 'select',
                'required' => false,
                'default' => 'predefined',
                'label' => 'Segment Type',
                'description' => 'Type of segmentation to apply',
                'options' => [
                    'predefined' => 'Predefined Segments',
                    'custom' => 'Custom Scoring',
                    'combined_score' => 'Combined RFM Score',
                ],
            ],
            'target_segment' => [
                'type' => 'select',
                'required' => false,
                'label' => 'Target Segment',
                'description' => 'Specific segment to target (for predefined segments)',
                'options' => [
                    'champions' => 'Champions',
                    'loyal_customers' => 'Loyal Customers',
                    'potential_loyalists' => 'Potential Loyalists',
                    'recent_customers' => 'Recent Customers',
                    'promising' => 'Promising',
                    'customers_needing_attention' => 'Customers Needing Attention',
                    'about_to_sleep' => 'About to Sleep',
                    'at_risk' => 'At Risk',
                    'cannot_lose_them' => 'Cannot Lose Them',
                    'hibernating' => 'Hibernating',
                    'lost' => 'Lost',
                ],
            ],
            'recency_threshold' => [
                'type' => 'integer',
                'required' => false,
                'default' => 90,
                'min' => 1,
                'max' => 365,
                'label' => 'Recency Threshold (Days)',
                'description' => 'Maximum days since last purchase for high recency score',
            ],
            'frequency_threshold' => [
                'type' => 'integer',
                'required' => false,
                'default' => 3,
                'min' => 1,
                'max' => 100,
                'label' => 'Frequency Threshold',
                'description' => 'Minimum number of orders for high frequency score',
            ],
            'monetary_threshold' => [
                'type' => 'float',
                'required' => false,
                'default' => 250.00,
                'min' => 0,
                'label' => 'Monetary Threshold',
                'description' => 'Minimum total spent for high monetary score',
            ],
            'min_recency_score' => [
                'type' => 'integer',
                'required' => false,
                'min' => 1,
                'max' => 5,
                'label' => 'Minimum Recency Score',
                'description' => 'Minimum recency score for inclusion',
            ],
            'max_recency_score' => [
                'type' => 'integer',
                'required' => false,
                'min' => 1,
                'max' => 5,
                'label' => 'Maximum Recency Score',
                'description' => 'Maximum recency score for inclusion',
            ],
            'min_frequency_score' => [
                'type' => 'integer',
                'required' => false,
                'min' => 1,
                'max' => 5,
                'label' => 'Minimum Frequency Score',
                'description' => 'Minimum frequency score for inclusion',
            ],
            'max_frequency_score' => [
                'type' => 'integer',
                'required' => false,
                'min' => 1,
                'max' => 5,
                'label' => 'Maximum Frequency Score',
                'description' => 'Maximum frequency score for inclusion',
            ],
            'min_monetary_score' => [
                'type' => 'integer',
                'required' => false,
                'min' => 1,
                'max' => 5,
                'label' => 'Minimum Monetary Score',
                'description' => 'Minimum monetary score for inclusion',
            ],
            'max_monetary_score' => [
                'type' => 'integer',
                'required' => false,
                'min' => 1,
                'max' => 5,
                'label' => 'Maximum Monetary Score',
                'description' => 'Maximum monetary score for inclusion',
            ],
            'combined_score_min' => [
                'type' => 'integer',
                'required' => false,
                'min' => 3,
                'max' => 15,
                'label' => 'Minimum Combined Score',
                'description' => 'Minimum combined RFM score (R+F+M)',
            ],
            'combined_score_max' => [
                'type' => 'integer',
                'required' => false,
                'min' => 3,
                'max' => 15,
                'label' => 'Maximum Combined Score',
                'description' => 'Maximum combined RFM score (R+F+M)',
            ],
            'exclude_inactive' => [
                'type' => 'boolean',
                'required' => false,
                'default' => true,
                'label' => 'Exclude Inactive Customers',
                'description' => 'Exclude customers with no orders in analysis period',
            ],
            'include_guest_orders' => [
                'type' => 'boolean',
                'required' => false,
                'default' => false,
                'label' => 'Include Guest Orders',
                'description' => 'Include orders from guest customers',
            ],
            'order_statuses' => [
                'type' => 'array',
                'required' => false,
                'label' => 'Order Statuses',
                'description' => 'Order statuses to include in analysis',
                'default' => ['complete', 'shipped', 'delivered'],
            ],
            'currency_code' => [
                'type' => 'string',
                'required' => false,
                'label' => 'Currency Code',
                'description' => 'Currency code for monetary calculations',
            ],
            'weight_recency' => [
                'type' => 'float',
                'required' => false,
                'default' => 1.0,
                'min' => 0.1,
                'max' => 5.0,
                'label' => 'Recency Weight',
                'description' => 'Weight for recency score in combined calculations',
            ],
            'weight_frequency' => [
                'type' => 'float',
                'required' => false,
                'default' => 1.0,
                'min' => 0.1,
                'max' => 5.0,
                'label' => 'Frequency Weight',
                'description' => 'Weight for frequency score in combined calculations',
            ],
            'weight_monetary' => [
                'type' => 'float',
                'required' => false,
                'default' => 1.0,
                'min' => 0.1,
                'max' => 5.0,
                'label' => 'Monetary Weight',
                'description' => 'Weight for monetary score in combined calculations',
            ],
        ];
    }

    /**
     * Sets the configuration array for this filter instance.
     *
     * @param array $config
     * @return void
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    /**
     * Gets this filter instance's configuration.
     *
     * @return array
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Checks whether the configuration set for this filter is valid.
     *
     * @return bool
     */
    public function validate(): bool
    {
        $this->validationErrors = [];

        // Validate analysis period
        $analysisPeriod = ArrayHelper::get($this->config, 'analysis_period_days', 365);
        if ($analysisPeriod < 30 || $analysisPeriod > 1825) {
            $this->validationErrors[] = 'Analysis period must be between 30 and 1825 days';
        }

        // Validate scoring method
        $scoringMethod = ArrayHelper::get($this->config, 'scoring_method', 'quintile');
        if (!in_array($scoringMethod, $this->scoringMethods)) {
            $this->validationErrors[] = 'Invalid scoring method';
        }

        // Validate segment type
        $segmentType = ArrayHelper::get($this->config, 'segment_type', 'predefined');
        if (!in_array($segmentType, ['predefined', 'custom', 'combined_score'])) {
            $this->validationErrors[] = 'Invalid segment type';
        }

        // Validate target segment for predefined segments
        if ($segmentType === 'predefined') {
            $targetSegment = ArrayHelper::get($this->config, 'target_segment');
            if (!$targetSegment || !isset($this->segmentDefinitions[$targetSegment])) {
                $this->validationErrors[] = 'Target segment is required for predefined segments';
            }
        }

        // Validate custom scoring ranges
        if ($segmentType === 'custom') {
            $this->validateCustomScoring();
        }

        // Validate combined score ranges
        if ($segmentType === 'combined_score') {
            $this->validateCombinedScoring();
        }

        // Validate thresholds
        $this->validateThresholds();

        return empty($this->validationErrors);
    }

    /**
     * Applies the filter and returns the array of matching customer IDs.
     *
     * @param array $context
     * @return array
     */
    public function apply(array $context): array
    {
        $db = $context['db'];
        $analysisPeriod = ArrayHelper::get($this->config, 'analysis_period_days', 365);
        $segmentType = ArrayHelper::get($this->config, 'segment_type', 'predefined');

        // Calculate RFM metrics for all customers
        $rfmData = $this->calculateRfmMetrics($db, $analysisPeriod);

        // Calculate RFM scores
        $rfmScores = $this->calculateRfmScores($rfmData);

        // Apply segmentation based on type
        switch ($segmentType) {
            case 'predefined':
                return $this->applyPredefinedSegmentation($rfmScores);
            case 'custom':
                return $this->applyCustomSegmentation($rfmScores);
            case 'combined_score':
                return $this->applyCombinedScoreSegmentation($rfmScores);
            default:
                throw new SegmentException("Unknown segment type: {$segmentType}");
        }
    }

    /**
     * Serializes the filter to an array for storage.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'type' => static::getType(),
            'config' => $this->config,
        ];
    }

    /**
     * Creates a filter instance from an array.
     *
     * @param array $data
     * @return static
     */
    public static function fromArray(array $data): self
    {
        $filter = new static();
        $filter->setConfig($data['config'] ?? []);
        return $filter;
    }

    /**
     * Calculates RFM metrics for all customers.
     *
     * @param object $db
     * @param int $analysisPeriod
     * @return array
     */
    protected function calculateRfmMetrics($db, int $analysisPeriod): array
    {
        $endDate = DateHelper::now();
        $startDate = DateHelper::nowObject()->modify("-{$analysisPeriod} days")->format('Y-m-d H:i:s');
        
        $orderStatuses = ArrayHelper::get($this->config, 'order_statuses', ['complete', 'shipped', 'delivered']);
        $statusPlaceholders = str_repeat('?,', count($orderStatuses) - 1) . '?';
        
        $includeGuests = ArrayHelper::get($this->config, 'include_guest_orders', false);
        $customerCondition = $includeGuests ? '' : 'AND o.customer_id > 0';
        
        $currencyCode = ArrayHelper::get($this->config, 'currency_code');
        $currencyCondition = $currencyCode ? 'AND o.currency_code = ?' : '';
        
        $sql = "
            SELECT 
                COALESCE(o.customer_id, 0) as customer_id,
                COALESCE(o.email, '') as email,
                DATEDIFF(NOW(), MAX(o.date_added)) as recency,
                COUNT(DISTINCT o.order_id) as frequency,
                SUM(o.total) as monetary
            FROM `order` o
            WHERE o.date_added >= ?
            AND o.date_added <= ?
            AND o.order_status_id IN ({$statusPlaceholders})
            {$customerCondition}
            {$currencyCondition}
            GROUP BY COALESCE(o.customer_id, 0), COALESCE(o.email, '')
            HAVING frequency > 0
        ";
        
        $params = [$startDate, $endDate, ...$orderStatuses];
        if ($currencyCode) {
            $params[] = $currencyCode;
        }
        
        $query = $db->query($sql, $params);
        
        $rfmData = [];
        foreach ($query->rows as $row) {
            $customerId = $row['customer_id'] > 0 ? $row['customer_id'] : 'guest_' . md5($row['email']);
            $rfmData[$customerId] = [
                'customer_id' => $row['customer_id'],
                'email' => $row['email'],
                'recency' => (int)$row['recency'],
                'frequency' => (int)$row['frequency'],
                'monetary' => (float)$row['monetary'],
            ];
        }
        
        return $rfmData;
    }

    /**
     * Calculates RFM scores based on the selected scoring method.
     *
     * @param array $rfmData
     * @return array
     */
    protected function calculateRfmScores(array $rfmData): array
    {
        if (empty($rfmData)) {
            return [];
        }

        $scoringMethod = ArrayHelper::get($this->config, 'scoring_method', 'quintile');
        
        switch ($scoringMethod) {
            case 'quintile':
                return $this->calculateQuintileScores($rfmData);
            case 'quartile':
                return $this->calculateQuartileScores($rfmData);
            case 'percentile':
                return $this->calculatePercentileScores($rfmData);
            case 'fixed_threshold':
                return $this->calculateFixedThresholdScores($rfmData);
            case 'standard_deviation':
                return $this->calculateStandardDeviationScores($rfmData);
            default:
                return $this->calculateQuintileScores($rfmData);
        }
    }

    /**
     * Calculates quintile-based RFM scores (1-5 scale).
     *
     * @param array $rfmData
     * @return array
     */
    protected function calculateQuintileScores(array $rfmData): array
    {
        $recencyValues = array_column($rfmData, 'recency');
        $frequencyValues = array_column($rfmData, 'frequency');
        $monetaryValues = array_column($rfmData, 'monetary');
        
        sort($recencyValues);
        rsort($frequencyValues); // Higher frequency = better score
        rsort($monetaryValues); // Higher monetary = better score
        
        $recencyQuintiles = $this->calculateQuintiles($recencyValues, true); // Lower recency = better score
        $frequencyQuintiles = $this->calculateQuintiles($frequencyValues, false);
        $monetaryQuintiles = $this->calculateQuintiles($monetaryValues, false);
        
        $scores = [];
        foreach ($rfmData as $customerId => $data) {
            $scores[$customerId] = [
                'customer_id' => $data['customer_id'],
                'email' => $data['email'],
                'recency' => $data['recency'],
                'frequency' => $data['frequency'],
                'monetary' => $data['monetary'],
                'r_score' => $this->getQuintileScore($data['recency'], $recencyQuintiles, true),
                'f_score' => $this->getQuintileScore($data['frequency'], $frequencyQuintiles, false),
                'm_score' => $this->getQuintileScore($data['monetary'], $monetaryQuintiles, false),
            ];
        }
        
        return $scores;
    }

    /**
     * Calculates quartile-based RFM scores (1-4 scale).
     *
     * @param array $rfmData
     * @return array
     */
    protected function calculateQuartileScores(array $rfmData): array
    {
        $recencyValues = array_column($rfmData, 'recency');
        $frequencyValues = array_column($rfmData, 'frequency');
        $monetaryValues = array_column($rfmData, 'monetary');
        
        sort($recencyValues);
        rsort($frequencyValues);
        rsort($monetaryValues);
        
        $recencyQuartiles = $this->calculateQuartiles($recencyValues, true);
        $frequencyQuartiles = $this->calculateQuartiles($frequencyValues, false);
        $monetaryQuartiles = $this->calculateQuartiles($monetaryValues, false);
        
        $scores = [];
        foreach ($rfmData as $customerId => $data) {
            $scores[$customerId] = [
                'customer_id' => $data['customer_id'],
                'email' => $data['email'],
                'recency' => $data['recency'],
                'frequency' => $data['frequency'],
                'monetary' => $data['monetary'],
                'r_score' => $this->getQuartileScore($data['recency'], $recencyQuartiles, true),
                'f_score' => $this->getQuartileScore($data['frequency'], $frequencyQuartiles, false),
                'm_score' => $this->getQuartileScore($data['monetary'], $monetaryQuartiles, false),
            ];
        }
        
        return $scores;
    }

    /**
     * Calculates fixed threshold-based RFM scores.
     *
     * @param array $rfmData
     * @return array
     */
    protected function calculateFixedThresholdScores(array $rfmData): array
    {
        $recencyThreshold = ArrayHelper::get($this->config, 'recency_threshold', 90);
        $frequencyThreshold = ArrayHelper::get($this->config, 'frequency_threshold', 3);
        $monetaryThreshold = ArrayHelper::get($this->config, 'monetary_threshold', 250.00);
        
        $scores = [];
        foreach ($rfmData as $customerId => $data) {
            $scores[$customerId] = [
                'customer_id' => $data['customer_id'],
                'email' => $data['email'],
                'recency' => $data['recency'],
                'frequency' => $data['frequency'],
                'monetary' => $data['monetary'],
                'r_score' => $this->getFixedThresholdScore($data['recency'], $recencyThreshold, true),
                'f_score' => $this->getFixedThresholdScore($data['frequency'], $frequencyThreshold, false),
                'm_score' => $this->getFixedThresholdScore($data['monetary'], $monetaryThreshold, false),
            ];
        }
        
        return $scores;
    }

    /**
     * Calculates standard deviation-based RFM scores.
     *
     * @param array $rfmData
     * @return array
     */
    protected function calculateStandardDeviationScores(array $rfmData): array
    {
        $recencyValues = array_column($rfmData, 'recency');
        $frequencyValues = array_column($rfmData, 'frequency');
        $monetaryValues = array_column($rfmData, 'monetary');
        
        $recencyMean = array_sum($recencyValues) / count($recencyValues);
        $frequencyMean = array_sum($frequencyValues) / count($frequencyValues);
        $monetaryMean = array_sum($monetaryValues) / count($monetaryValues);
        
        $recencyStdDev = $this->calculateStandardDeviation($recencyValues, $recencyMean);
        $frequencyStdDev = $this->calculateStandardDeviation($frequencyValues, $frequencyMean);
        $monetaryStdDev = $this->calculateStandardDeviation($monetaryValues, $monetaryMean);
        
        $scores = [];
        foreach ($rfmData as $customerId => $data) {
            $scores[$customerId] = [
                'customer_id' => $data['customer_id'],
                'email' => $data['email'],
                'recency' => $data['recency'],
                'frequency' => $data['frequency'],
                'monetary' => $data['monetary'],
                'r_score' => $this->getStandardDeviationScore($data['recency'], $recencyMean, $recencyStdDev, true),
                'f_score' => $this->getStandardDeviationScore($data['frequency'], $frequencyMean, $frequencyStdDev, false),
                'm_score' => $this->getStandardDeviationScore($data['monetary'], $monetaryMean, $monetaryStdDev, false),
            ];
        }
        
        return $scores;
    }

    /**
     * Applies predefined segmentation.
     *
     * @param array $rfmScores
     * @return array
     */
    protected function applyPredefinedSegmentation(array $rfmScores): array
    {
        $targetSegment = ArrayHelper::get($this->config, 'target_segment');
        if (!$targetSegment || !isset($this->segmentDefinitions[$targetSegment])) {
            return [];
        }
        
        $segmentDef = $this->segmentDefinitions[$targetSegment];
        $matchingCustomers = [];
        
        foreach ($rfmScores as $customerId => $scores) {
            if ($this->matchesSegmentDefinition($scores, $segmentDef)) {
                $matchingCustomers[] = $scores['customer_id'];
            }
        }
        
        return array_filter($matchingCustomers, function($id) { return $id > 0; });
    }

    /**
     * Applies custom segmentation based on score ranges.
     *
     * @param array $rfmScores
     * @return array
     */
    protected function applyCustomSegmentation(array $rfmScores): array
    {
        $minR = ArrayHelper::get($this->config, 'min_recency_score', 1);
        $maxR = ArrayHelper::get($this->config, 'max_recency_score', 5);
        $minF = ArrayHelper::get($this->config, 'min_frequency_score', 1);
        $maxF = ArrayHelper::get($this->config, 'max_frequency_score', 5);
        $minM = ArrayHelper::get($this->config, 'min_monetary_score', 1);
        $maxM = ArrayHelper::get($this->config, 'max_monetary_score', 5);
        
        $matchingCustomers = [];
        
        foreach ($rfmScores as $customerId => $scores) {
            if ($scores['r_score'] >= $minR && $scores['r_score'] <= $maxR &&
                $scores['f_score'] >= $minF && $scores['f_score'] <= $maxF &&
                $scores['m_score'] >= $minM && $scores['m_score'] <= $maxM) {
                $matchingCustomers[] = $scores['customer_id'];
            }
        }
        
        return array_filter($matchingCustomers, function($id) { return $id > 0; });
    }

    /**
     * Applies combined score segmentation.
     *
     * @param array $rfmScores
     * @return array
     */
    protected function applyCombinedScoreSegmentation(array $rfmScores): array
    {
        $minScore = ArrayHelper::get($this->config, 'combined_score_min', 3);
        $maxScore = ArrayHelper::get($this->config, 'combined_score_max', 15);
        $weightR = ArrayHelper::get($this->config, 'weight_recency', 1.0);
        $weightF = ArrayHelper::get($this->config, 'weight_frequency', 1.0);
        $weightM = ArrayHelper::get($this->config, 'weight_monetary', 1.0);
        
        $matchingCustomers = [];
        
        foreach ($rfmScores as $customerId => $scores) {
            $combinedScore = ($scores['r_score'] * $weightR) + 
                           ($scores['f_score'] * $weightF) + 
                           ($scores['m_score'] * $weightM);
            
            if ($combinedScore >= $minScore && $combinedScore <= $maxScore) {
                $matchingCustomers[] = $scores['customer_id'];
            }
        }
        
        return array_filter($matchingCustomers, function($id) { return $id > 0; });
    }

    /**
     * Calculates quintiles for a dataset.
     *
     * @param array $values
     * @param bool $reverse
     * @return array
     */
    protected function calculateQuintiles(array $values, bool $reverse = false): array
    {
        $count = count($values);
        if ($count === 0) return [];
        
        $quintiles = [];
        for ($i = 1; $i <= 5; $i++) {
            $index = (int)ceil(($i / 5) * $count) - 1;
            $quintiles[$i] = $values[$index];
        }
        
        return $reverse ? array_reverse($quintiles, true) : $quintiles;
    }

    /**
     * Calculates quartiles for a dataset.
     *
     * @param array $values
     * @param bool $reverse
     * @return array
     */
    protected function calculateQuartiles(array $values, bool $reverse = false): array
    {
        $count = count($values);
        if ($count === 0) return [];
        
        $quartiles = [];
        for ($i = 1; $i <= 4; $i++) {
            $index = (int)ceil(($i / 4) * $count) - 1;
            $quartiles[$i] = $values[$index];
        }
        
        return $reverse ? array_reverse($quartiles, true) : $quartiles;
    }

    /**
     * Gets quintile score for a value.
     *
     * @param mixed $value
     * @param array $quintiles
     * @param bool $reverse
     * @return int
     */
    protected function getQuintileScore($value, array $quintiles, bool $reverse = false): int
    {
        for ($i = 1; $i <= 5; $i++) {
            if ($reverse) {
                if ($value <= $quintiles[$i]) {
                    return 6 - $i; // Reverse scoring
                }
            } else {
                if ($value >= $quintiles[$i]) {
                    return $i;
                }
            }
        }
        
        return $reverse ? 1 : 5;
    }

    /**
     * Gets quartile score for a value.
     *
     * @param mixed $value
     * @param array $quartiles
     * @param bool $reverse
     * @return int
     */
    protected function getQuartileScore($value, array $quartiles, bool $reverse = false): int
    {
        for ($i = 1; $i <= 4; $i++) {
            if ($reverse) {
                if ($value <= $quartiles[$i]) {
                    return 5 - $i; // Reverse scoring
                }
            } else {
                if ($value >= $quartiles[$i]) {
                    return $i;
                }
            }
        }
        
        return $reverse ? 1 : 4;
    }

    /**
     * Gets fixed threshold score for a value.
     *
     * @param mixed $value
     * @param mixed $threshold
     * @param bool $reverse
     * @return int
     */
    protected function getFixedThresholdScore($value, $threshold, bool $reverse = false): int
    {
        if ($reverse) {
            return $value <= $threshold ? 5 : 1;
        } else {
            return $value >= $threshold ? 5 : 1;
        }
    }

    /**
     * Gets standard deviation score for a value.
     *
     * @param mixed $value
     * @param float $mean
     * @param float $stdDev
     * @param bool $reverse
     * @return int
     */
    protected function getStandardDeviationScore($value, float $mean, float $stdDev, bool $reverse = false): int
    {
        if ($stdDev == 0) return 3; // Neutral score if no deviation
        
        $zScore = ($value - $mean) / $stdDev;
        
        if ($reverse) {
            if ($zScore <= -2) return 5;
            if ($zScore <= -1) return 4;
            if ($zScore <= 0) return 3;
            if ($zScore <= 1) return 2;
            return 1;
        } else {
            if ($zScore >= 2) return 5;
            if ($zScore >= 1) return 4;
            if ($zScore >= 0) return 3;
            if ($zScore >= -1) return 2;
            return 1;
        }
    }

    /**
     * Calculates standard deviation.
     *
     * @param array $values
     * @param float $mean
     * @return float
     */
    protected function calculateStandardDeviation(array $values, float $mean): float
    {
        $count = count($values);
        if ($count <= 1) return 0;
        
        $variance = 0;
        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }
        
        return sqrt($variance / ($count - 1));
    }

    /**
     * Checks if scores match a segment definition.
     *
     * @param array $scores
     * @param array $segmentDef
     * @return bool
     */
    protected function matchesSegmentDefinition(array $scores, array $segmentDef): bool
    {
        return $scores['r_score'] >= $segmentDef['r'][0] && $scores['r_score'] <= $segmentDef['r'][1] &&
               $scores['f_score'] >= $segmentDef['f'][0] && $scores['f_score'] <= $segmentDef['f'][1] &&
               $scores['m_score'] >= $segmentDef['m'][0] && $scores['m_score'] <= $segmentDef['m'][1];
    }

    /**
     * Validates custom scoring configuration.
     *
     * @return void
     */
    protected function validateCustomScoring(): void
    {
        $ranges = [
            ['min_recency_score', 'max_recency_score'],
            ['min_frequency_score', 'max_frequency_score'],
            ['min_monetary_score', 'max_monetary_score'],
        ];
        
        foreach ($ranges as $range) {
            $min = ArrayHelper::get($this->config, $range[0]);
            $max = ArrayHelper::get($this->config, $range[1]);
            
            if ($min !== null && $max !== null && $min > $max) {
                $this->validationErrors[] = "Invalid range: {$range[0]} cannot be greater than {$range[1]}";
            }
        }
    }

    /**
     * Validates combined scoring configuration.
     *
     * @return void
     */
    protected function validateCombinedScoring(): void
    {
        $minScore = ArrayHelper::get($this->config, 'combined_score_min');
        $maxScore = ArrayHelper::get($this->config, 'combined_score_max');
        
        if ($minScore !== null && $maxScore !== null && $minScore > $maxScore) {
            $this->validationErrors[] = 'Combined score minimum cannot be greater than maximum';
        }
    }

    /**
     * Validates threshold configuration.
     *
     * @return void
     */
    protected function validateThresholds(): void
    {
        $recencyThreshold = ArrayHelper::get($this->config, 'recency_threshold');
        if ($recencyThreshold !== null && ($recencyThreshold < 1 || $recencyThreshold > 365)) {
            $this->validationErrors[] = 'Recency threshold must be between 1 and 365 days';
        }
        
        $frequencyThreshold = ArrayHelper::get($this->config, 'frequency_threshold');
        if ($frequencyThreshold !== null && ($frequencyThreshold < 1 || $frequencyThreshold > 100)) {
            $this->validationErrors[] = 'Frequency threshold must be between 1 and 100';
        }
        
        $monetaryThreshold = ArrayHelper::get($this->config, 'monetary_threshold');
        if ($monetaryThreshold !== null && $monetaryThreshold < 0) {
            $this->validationErrors[] = 'Monetary threshold must be non-negative';
        }
    }

    /**
     * Gets validation errors.
     *
     * @return array
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }
}
