<?php
/**
 * MAS - Marketing Automation Suite
 * Behavioural Filter
 *
 * Implements behavioural analysis for customer segmentation based on:
 * - Website browsing behavior (page views, session duration, bounce rate)
 * - Purchase behavior (categories, brands, timing patterns)
 * - Engagement behavior (email opens, clicks, social media interactions)
 * - Cart abandonment patterns
 * - Product interaction (reviews, wishlist, comparisons)
 * - Login frequency and device usage patterns
 *
 * Supports multiple behavioral scoring methods and flexible segmentation
 * strategies for personalized marketing campaigns.
 *
 * @copyright Copyright (c) 2025, Your Company
 * @license   Proprietary
 */

namespace Opencart\Library\Mas\Segmentation\Filters;

use Opencart\Library\Mas\Interfaces\SegmentFilterInterface;
use Opencart\Library\Mas\Exception\SegmentException;
use Opencart\Library\Mas\Helper\ArrayHelper;
use Opencart\Library\Mas\Helper\DateHelper;

class BehaviouralFilter implements SegmentFilterInterface
{
    /**
     * @var array Filter configuration
     */
    protected array $config = [];
    
    /**
     * @var array Supported behavioral metrics
     */
    protected array $behavioralMetrics = [
        'page_views',
        'session_duration',
        'bounce_rate',
        'cart_abandonment_rate',
        'email_engagement',
        'product_interactions',
        'category_affinity',
        'brand_loyalty',
        'purchase_timing',
        'device_preference',
        'login_frequency',
        'social_engagement',
        'review_activity',
        'support_interactions',
    ];
    
    /**
     * @var array Behavioral segment definitions
     */
    protected array $behavioralSegments = [
        'highly_engaged' => [
            'page_views' => ['min' => 50],
            'session_duration' => ['min' => 300],
            'email_engagement' => ['min' => 0.3],
        ],
        'browsers' => [
            'page_views' => ['min' => 20],
            'session_duration' => ['min' => 120],
            'purchase_rate' => ['max' => 0.1],
        ],
        'converters' => [
            'purchase_rate' => ['min' => 0.2],
            'cart_abandonment_rate' => ['max' => 0.3],
        ],
        'cart_abandoners' => [
            'cart_abandonment_rate' => ['min' => 0.7],
            'email_engagement' => ['max' => 0.2],
        ],
        'loyal_browsers' => [
            'login_frequency' => ['min' => 10],
            'brand_loyalty' => ['min' => 0.6],
        ],
        'mobile_users' => [
            'mobile_usage_rate' => ['min' => 0.8],
        ],
        'social_active' => [
            'social_engagement' => ['min' => 5],
        ],
        'review_contributors' => [
            'review_activity' => ['min' => 3],
        ],
        'category_specialists' => [
            'category_concentration' => ['min' => 0.7],
        ],
        'bargain_hunters' => [
            'discount_usage_rate' => ['min' => 0.5],
            'price_sensitivity' => ['min' => 0.8],
        ],
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
        return 'behavioral';
    }
    
    /**
     * Returns a human-readable label for this filter.
     *
     * @return string
     */
    public static function getLabel(): string
    {
        return 'Behavioral Analysis';
    }
    
    /**
     * Returns a description of the filter's purpose.
     *
     * @return string
     */
    public static function getDescription(): string
    {
        return 'Segments customers based on their behavioral patterns, engagement, and interaction history';
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
                'default' => 90,
                'min' => 7,
                'max' => 365,
                'label' => 'Analysis Period (Days)',
                'description' => 'Number of days to analyze for behavioral patterns',
            ],
            'segment_type' => [
                'type' => 'select',
                'required' => false,
                'default' => 'predefined',
                'label' => 'Segment Type',
                'description' => 'Type of behavioral segmentation to apply',
                'options' => [
                    'predefined' => 'Predefined Behavioral Segments',
                    'custom_metrics' => 'Custom Behavioral Metrics',
                    'engagement_score' => 'Engagement Score Based',
                    'activity_level' => 'Activity Level Based',
                ],
            ],
            'target_segment' => [
                'type' => 'select',
                'required' => false,
                'label' => 'Target Behavioral Segment',
                'description' => 'Specific behavioral segment to target',
                'options' => [
                    'highly_engaged' => 'Highly Engaged',
                    'browsers' => 'Browsers (High Traffic, Low Conversion)',
                    'converters' => 'Converters (High Purchase Rate)',
                    'cart_abandoners' => 'Cart Abandoners',
                    'loyal_browsers' => 'Loyal Browsers',
                    'mobile_users' => 'Mobile Dominant Users',
                    'social_active' => 'Social Media Active',
                    'review_contributors' => 'Review Contributors',
                    'category_specialists' => 'Category Specialists',
                    'bargain_hunters' => 'Bargain Hunters',
                ],
            ],
            'min_page_views' => [
                'type' => 'integer',
                'required' => false,
                'min' => 1,
                'label' => 'Minimum Page Views',
                'description' => 'Minimum number of page views in analysis period',
            ],
            'max_page_views' => [
                'type' => 'integer',
                'required' => false,
                'min' => 1,
                'label' => 'Maximum Page Views',
                'description' => 'Maximum number of page views in analysis period',
            ],
            'min_session_duration' => [
                'type' => 'integer',
                'required' => false,
                'min' => 0,
                'label' => 'Minimum Session Duration (seconds)',
                'description' => 'Minimum average session duration',
            ],
            'max_session_duration' => [
                'type' => 'integer',
                'required' => false,
                'min' => 0,
                'label' => 'Maximum Session Duration (seconds)',
                'description' => 'Maximum average session duration',
            ],
            'min_email_engagement' => [
                'type' => 'float',
                'required' => false,
                'min' => 0,
                'max' => 1,
                'label' => 'Minimum Email Engagement Rate',
                'description' => 'Minimum email open/click rate (0-1)',
            ],
            'max_email_engagement' => [
                'type' => 'float',
                'required' => false,
                'min' => 0,
                'max' => 1,
                'label' => 'Maximum Email Engagement Rate',
                'description' => 'Maximum email open/click rate (0-1)',
            ],
            'min_cart_abandonment' => [
                'type' => 'float',
                'required' => false,
                'min' => 0,
                'max' => 1,
                'label' => 'Minimum Cart Abandonment Rate',
                'description' => 'Minimum cart abandonment rate (0-1)',
            ],
            'max_cart_abandonment' => [
                'type' => 'float',
                'required' => false,
                'min' => 0,
                'max' => 1,
                'label' => 'Maximum Cart Abandonment Rate',
                'description' => 'Maximum cart abandonment rate (0-1)',
            ],
            'min_login_frequency' => [
                'type' => 'integer',
                'required' => false,
                'min' => 0,
                'label' => 'Minimum Login Frequency',
                'description' => 'Minimum number of logins in analysis period',
            ],
            'max_login_frequency' => [
                'type' => 'integer',
                'required' => false,
                'min' => 0,
                'label' => 'Maximum Login Frequency',
                'description' => 'Maximum number of logins in analysis period',
            ],
            'min_purchase_rate' => [
                'type' => 'float',
                'required' => false,
                'min' => 0,
                'max' => 1,
                'label' => 'Minimum Purchase Rate',
                'description' => 'Minimum purchase conversion rate (0-1)',
            ],
            'max_purchase_rate' => [
                'type' => 'float',
                'required' => false,
                'min' => 0,
                'max' => 1,
                'label' => 'Maximum Purchase Rate',
                'description' => 'Maximum purchase conversion rate (0-1)',
            ],
            'device_preference' => [
                'type' => 'select',
                'required' => false,
                'label' => 'Device Preference',
                'description' => 'Filter by primary device usage',
                'options' => [
                    'mobile' => 'Mobile Dominant',
                    'desktop' => 'Desktop Dominant',
                    'tablet' => 'Tablet Dominant',
                    'mixed' => 'Mixed Usage',
                ],
            ],
            'preferred_categories' => [
                'type' => 'array',
                'required' => false,
                'label' => 'Preferred Categories',
                'description' => 'Filter by preferred product categories',
            ],
            'min_category_concentration' => [
                'type' => 'float',
                'required' => false,
                'min' => 0,
                'max' => 1,
                'label' => 'Minimum Category Concentration',
                'description' => 'Minimum concentration in specific categories (0-1)',
            ],
            'min_brand_loyalty' => [
                'type' => 'float',
                'required' => false,
                'min' => 0,
                'max' => 1,
                'label' => 'Minimum Brand Loyalty',
                'description' => 'Minimum brand loyalty score (0-1)',
            ],
            'min_social_engagement' => [
                'type' => 'integer',
                'required' => false,
                'min' => 0,
                'label' => 'Minimum Social Engagement',
                'description' => 'Minimum social media interactions',
            ],
            'min_review_activity' => [
                'type' => 'integer',
                'required' => false,
                'min' => 0,
                'label' => 'Minimum Review Activity',
                'description' => 'Minimum number of reviews written',
            ],
            'time_preference' => [
                'type' => 'select',
                'required' => false,
                'label' => 'Time Preference',
                'description' => 'Filter by preferred activity time',
                'options' => [
                    'morning' => 'Morning Active (6-12)',
                    'afternoon' => 'Afternoon Active (12-18)',
                    'evening' => 'Evening Active (18-24)',
                    'night' => 'Night Active (0-6)',
                    'business_hours' => 'Business Hours',
                    'weekends' => 'Weekend Active',
                ],
            ],
            'engagement_score_min' => [
                'type' => 'integer',
                'required' => false,
                'min' => 0,
                'max' => 100,
                'label' => 'Minimum Engagement Score',
                'description' => 'Minimum overall engagement score (0-100)',
            ],
            'engagement_score_max' => [
                'type' => 'integer',
                'required' => false,
                'min' => 0,
                'max' => 100,
                'label' => 'Maximum Engagement Score',
                'description' => 'Maximum overall engagement score (0-100)',
            ],
            'activity_level' => [
                'type' => 'select',
                'required' => false,
                'label' => 'Activity Level',
                'description' => 'Filter by overall activity level',
                'options' => [
                    'very_high' => 'Very High Activity',
                    'high' => 'High Activity',
                    'medium' => 'Medium Activity',
                    'low' => 'Low Activity',
                    'very_low' => 'Very Low Activity',
                ],
            ],
            'include_anonymous' => [
                'type' => 'boolean',
                'required' => false,
                'default' => false,
                'label' => 'Include Anonymous Users',
                'description' => 'Include users without accounts in analysis',
            ],
            'exclude_staff' => [
                'type' => 'boolean',
                'required' => false,
                'default' => true,
                'label' => 'Exclude Staff',
                'description' => 'Exclude staff/admin accounts from analysis',
            ],
            'min_data_points' => [
                'type' => 'integer',
                'required' => false,
                'default' => 5,
                'min' => 1,
                'label' => 'Minimum Data Points',
                'description' => 'Minimum number of behavioral data points required',
            ],
            'weight_browsing' => [
                'type' => 'float',
                'required' => false,
                'default' => 1.0,
                'min' => 0.1,
                'max' => 5.0,
                'label' => 'Browsing Behavior Weight',
                'description' => 'Weight for browsing behavior in scoring',
            ],
            'weight_engagement' => [
                'type' => 'float',
                'required' => false,
                'default' => 1.0,
                'min' => 0.1,
                'max' => 5.0,
                'label' => 'Engagement Weight',
                'description' => 'Weight for engagement behavior in scoring',
            ],
            'weight_purchase' => [
                'type' => 'float',
                'required' => false,
                'default' => 1.0,
                'min' => 0.1,
                'max' => 5.0,
                'label' => 'Purchase Behavior Weight',
                'description' => 'Weight for purchase behavior in scoring',
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
        $analysisPeriod = ArrayHelper::get($this->config, 'analysis_period_days', 90);
        if ($analysisPeriod < 7 || $analysisPeriod > 365) {
            $this->validationErrors[] = 'Analysis period must be between 7 and 365 days';
        }
        
        // Validate segment type
        $segmentType = ArrayHelper::get($this->config, 'segment_type', 'predefined');
        if (!in_array($segmentType, ['predefined', 'custom_metrics', 'engagement_score', 'activity_level'])) {
            $this->validationErrors[] = 'Invalid segment type';
        }
        
        // Validate target segment for predefined segments
        if ($segmentType === 'predefined') {
            $targetSegment = ArrayHelper::get($this->config, 'target_segment');
            if (!$targetSegment || !isset($this->behavioralSegments[$targetSegment])) {
                $this->validationErrors[] = 'Target segment is required for predefined segments';
            }
        }
        
        // Validate numeric ranges
        $this->validateNumericRanges();
        
        // Validate weights
        $this->validateWeights();
        
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
        $analysisPeriod = ArrayHelper::get($this->config, 'analysis_period_days', 90);
        $segmentType = ArrayHelper::get($this->config, 'segment_type', 'predefined');
        
        // Calculate behavioral metrics for all customers
        $behavioralData = $this->calculateBehavioralMetrics($db, $analysisPeriod);
        
        // Apply segmentation based on type
        switch ($segmentType) {
            case 'predefined':
                return $this->applyPredefinedSegmentation($behavioralData);
            case 'custom_metrics':
                return $this->applyCustomMetricsSegmentation($behavioralData);
            case 'engagement_score':
                return $this->applyEngagementScoreSegmentation($behavioralData);
            case 'activity_level':
                return $this->applyActivityLevelSegmentation($behavioralData);
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
     * Calculates behavioral metrics for all customers.
     *
     * @param object $db
     * @param int $analysisPeriod
     * @return array
     */
    protected function calculateBehavioralMetrics($db, int $analysisPeriod): array
    {
        $endDate = DateHelper::now();
        $startDate = DateHelper::nowObject()->modify("-{$analysisPeriod} days")->format('Y-m-d H:i:s');
        
        $includeAnonymous = ArrayHelper::get($this->config, 'include_anonymous', false);
        $excludeStaff = ArrayHelper::get($this->config, 'exclude_staff', true);
        
        $behavioralData = [];
        
        // Get basic customer data
        $customers = $this->getCustomerBase($db, $includeAnonymous, $excludeStaff);
        
        foreach ($customers as $customer) {
            $customerId = $customer['customer_id'];
            
            $behavioralData[$customerId] = [
                'customer_id' => $customerId,
                'email' => $customer['email'],
                'page_views' => $this->calculatePageViews($db, $customerId, $startDate, $endDate),
                'session_duration' => $this->calculateSessionDuration($db, $customerId, $startDate, $endDate),
                'bounce_rate' => $this->calculateBounceRate($db, $customerId, $startDate, $endDate),
                'cart_abandonment_rate' => $this->calculateCartAbandonmentRate($db, $customerId, $startDate, $endDate),
                'email_engagement' => $this->calculateEmailEngagement($db, $customerId, $startDate, $endDate),
                'login_frequency' => $this->calculateLoginFrequency($db, $customerId, $startDate, $endDate),
                'purchase_rate' => $this->calculatePurchaseRate($db, $customerId, $startDate, $endDate),
                'category_concentration' => $this->calculateCategoryConcentration($db, $customerId, $startDate, $endDate),
                'brand_loyalty' => $this->calculateBrandLoyalty($db, $customerId, $startDate, $endDate),
                'mobile_usage_rate' => $this->calculateMobileUsageRate($db, $customerId, $startDate, $endDate),
                'social_engagement' => $this->calculateSocialEngagement($db, $customerId, $startDate, $endDate),
                'review_activity' => $this->calculateReviewActivity($db, $customerId, $startDate, $endDate),
                'discount_usage_rate' => $this->calculateDiscountUsageRate($db, $customerId, $startDate, $endDate),
                'price_sensitivity' => $this->calculatePriceSensitivity($db, $customerId, $startDate, $endDate),
            ];
            
            // Calculate derived metrics
            $behavioralData[$customerId]['engagement_score'] = $this->calculateEngagementScore($behavioralData[$customerId]);
            $behavioralData[$customerId]['activity_level'] = $this->calculateActivityLevel($behavioralData[$customerId]);
        }
        
        return $behavioralData;
    }
    
    /**
     * Gets customer base for analysis.
     *
     * @param object $db
     * @param bool $includeAnonymous
     * @param bool $excludeStaff
     * @return array
     */
    protected function getCustomerBase($db, bool $includeAnonymous, bool $excludeStaff): array
    {
        $conditions = [];
        
        if (!$includeAnonymous) {
            $conditions[] = "c.customer_id > 0";
        }
        
        if ($excludeStaff) {
            $conditions[] = "c.customer_group_id != 1"; // Assuming customer_group_id 1 is staff
        }
        
        $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
        
        $query = $db->query("
            SELECT c.customer_id, c.email, c.customer_group_id
            FROM `customer` c
            {$whereClause}
            AND c.status = 1
            ORDER BY c.customer_id
        ");
            
            return $query->rows;
    }
    
    /**
     * Calculates page views for a customer.
     *
     * @param object $db
     * @param int $customerId
     * @param string $startDate
     * @param string $endDate
     * @return int
     */
    protected function calculatePageViews($db, int $customerId, string $startDate, string $endDate): int
    {
        $query = $db->query("
            SELECT COUNT(*) as page_views
            FROM `mas_customer_activity`
            WHERE `customer_id` = '" . (int)$customerId . "'
            AND `activity_type` = 'page_view'
            AND `created_at` >= '" . $db->escape($startDate) . "'
            AND `created_at` <= '" . $db->escape($endDate) . "'
        ");
        
        return (int)($query->row['page_views'] ?? 0);
    }
    
    /**
     * Calculates average session duration for a customer.
     *
     * @param object $db
     * @param int $customerId
     * @param string $startDate
     * @param string $endDate
     * @return int
     */
    protected function calculateSessionDuration($db, int $customerId, string $startDate, string $endDate): int
    {
        $query = $db->query("
            SELECT AVG(TIMESTAMPDIFF(SECOND, session_start, session_end)) as avg_duration
            FROM `mas_customer_sessions`
            WHERE `customer_id` = '" . (int)$customerId . "'
            AND `session_start` >= '" . $db->escape($startDate) . "'
            AND `session_start` <= '" . $db->escape($endDate) . "'
            AND `session_end` IS NOT NULL
        ");
        
        return (int)($query->row['avg_duration'] ?? 0);
    }
    
    /**
     * Calculates bounce rate for a customer.
     *
     * @param object $db
     * @param int $customerId
     * @param string $startDate
     * @param string $endDate
     * @return float
     */
    protected function calculateBounceRate($db, int $customerId, string $startDate, string $endDate): float
    {
        $query = $db->query("
            SELECT
                COUNT(*) as total_sessions,
                SUM(CASE WHEN page_views = 1 THEN 1 ELSE 0 END) as bounce_sessions
            FROM `mas_customer_sessions`
            WHERE `customer_id` = '" . (int)$customerId . "'
            AND `session_start` >= '" . $db->escape($startDate) . "'
            AND `session_start` <= '" . $db->escape($endDate) . "'
        ");
        
        $totalSessions = (int)($query->row['total_sessions'] ?? 0);
        $bounceSessions = (int)($query->row['bounce_sessions'] ?? 0);
        
        return $totalSessions > 0 ? $bounceSessions / $totalSessions : 0;
    }
    
    /**
     * Calculates cart abandonment rate for a customer.
     *
     * @param object $db
     * @param int $customerId
     * @param string $startDate
     * @param string $endDate
     * @return float
     */
    protected function calculateCartAbandonmentRate($db, int $customerId, string $startDate, string $endDate): float
    {
        $query = $db->query("
            SELECT
                COUNT(DISTINCT CASE WHEN activity_type = 'cart_add' THEN session_id END) as carts_created,
                COUNT(DISTINCT CASE WHEN activity_type = 'order_complete' THEN session_id END) as orders_completed
            FROM `mas_customer_activity`
            WHERE `customer_id` = '" . (int)$customerId . "'
            AND `created_at` >= '" . $db->escape($startDate) . "'
            AND `created_at` <= '" . $db->escape($endDate) . "'
        ");
        
        $cartsCreated = (int)($query->row['carts_created'] ?? 0);
        $ordersCompleted = (int)($query->row['orders_completed'] ?? 0);
        
        return $cartsCreated > 0 ? 1 - ($ordersCompleted / $cartsCreated) : 0;
    }
    
    /**
     * Calculates email engagement rate for a customer.
     *
     * @param object $db
     * @param int $customerId
     * @param string $startDate
     * @param string $endDate
     * @return float
     */
    protected function calculateEmailEngagement($db, int $customerId, string $startDate, string $endDate): float
    {
        $query = $db->query("
            SELECT
                COUNT(*) as emails_sent,
                SUM(CASE WHEN opened = 1 THEN 1 ELSE 0 END) as emails_opened,
                SUM(CASE WHEN clicked = 1 THEN 1 ELSE 0 END) as emails_clicked
            FROM `mas_email_analytics`
            WHERE `customer_id` = '" . (int)$customerId . "'
            AND `sent_at` >= '" . $db->escape($startDate) . "'
            AND `sent_at` <= '" . $db->escape($endDate) . "'
        ");
        
        $emailsSent = (int)($query->row['emails_sent'] ?? 0);
        $emailsOpened = (int)($query->row['emails_opened'] ?? 0);
        $emailsClicked = (int)($query->row['emails_clicked'] ?? 0);
        
        return $emailsSent > 0 ? ($emailsOpened + $emailsClicked) / ($emailsSent * 2) : 0;
    }
    
    /**
     * Calculates login frequency for a customer.
     *
     * @param object $db
     * @param int $customerId
     * @param string $startDate
     * @param string $endDate
     * @return int
     */
    protected function calculateLoginFrequency($db, int $customerId, string $startDate, string $endDate): int
    {
        $query = $db->query("
            SELECT COUNT(*) as login_count
            FROM `customer_login`
            WHERE `customer_id` = '" . (int)$customerId . "'
            AND `date_added` >= '" . $db->escape($startDate) . "'
            AND `date_added` <= '" . $db->escape($endDate) . "'
        ");
        
        return (int)($query->row['login_count'] ?? 0);
    }
    
    /**
     * Calculates purchase conversion rate for a customer.
     *
     * @param object $db
     * @param int $customerId
     * @param string $startDate
     * @param string $endDate
     * @return float
     */
    protected function calculatePurchaseRate($db, int $customerId, string $startDate, string $endDate): float
    {
        $query = $db->query("
            SELECT
                COUNT(DISTINCT DATE(created_at)) as active_days,
                COUNT(DISTINCT CASE WHEN activity_type = 'order_complete' THEN DATE(created_at) END) as purchase_days
            FROM `mas_customer_activity`
            WHERE `customer_id` = '" . (int)$customerId . "'
            AND `created_at` >= '" . $db->escape($startDate) . "'
            AND `created_at` <= '" . $db->escape($endDate) . "'
        ");
        
        $activeDays = (int)($query->row['active_days'] ?? 0);
        $purchaseDays = (int)($query->row['purchase_days'] ?? 0);
        
        return $activeDays > 0 ? $purchaseDays / $activeDays : 0;
    }
    
    /**
     * Calculates category concentration for a customer.
     *
     * @param object $db
     * @param int $customerId
     * @param string $startDate
     * @param string $endDate
     * @return float
     */
    protected function calculateCategoryConcentration($db, int $customerId, string $startDate, string $endDate): float
    {
        $query = $db->query("
            SELECT
                category_id,
                COUNT(*) as category_orders,
                (SELECT COUNT(*) FROM `order` o2 WHERE o2.customer_id = o.customer_id
                 AND o2.date_added >= '" . $db->escape($startDate) . "'
                 AND o2.date_added <= '" . $db->escape($endDate) . "') as total_orders
            FROM `order` o
            JOIN `order_product` op ON o.order_id = op.order_id
            JOIN `product_to_category` ptc ON op.product_id = ptc.product_id
            WHERE o.customer_id = '" . (int)$customerId . "'
            AND o.date_added >= '" . $db->escape($startDate) . "'
            AND o.date_added <= '" . $db->escape($endDate) . "'
            GROUP BY category_id, o.customer_id
            ORDER BY category_orders DESC
            LIMIT 1
        ");
        
        if ($query->num_rows > 0) {
            $categoryOrders = (int)$query->row['category_orders'];
            $totalOrders = (int)$query->row['total_orders'];
            return $totalOrders > 0 ? $categoryOrders / $totalOrders : 0;
        }
        
        return 0;
    }
    
    /**
     * Calculates brand loyalty for a customer.
     *
     * @param object $db
     * @param int $customerId
     * @param string $startDate
     * @param string $endDate
     * @return float
     */
    protected function calculateBrandLoyalty($db, int $customerId, string $startDate, string $endDate): float
    {
        $query = $db->query("
            SELECT
                p.manufacturer_id,
                COUNT(*) as brand_orders,
                (SELECT COUNT(*) FROM `order` o2 WHERE o2.customer_id = o.customer_id
                 AND o2.date_added >= '" . $db->escape($startDate) . "'
                 AND o2.date_added <= '" . $db->escape($endDate) . "') as total_orders
            FROM `order` o
            JOIN `order_product` op ON o.order_id = op.order_id
            JOIN `product` p ON op.product_id = p.product_id
            WHERE o.customer_id = '" . (int)$customerId . "'
            AND o.date_added >= '" . $db->escape($startDate) . "'
            AND o.date_added <= '" . $db->escape($endDate) . "'
            AND p.manufacturer_id > 0
            GROUP BY p.manufacturer_id, o.customer_id
            ORDER BY brand_orders DESC
            LIMIT 1
        ");
        
        if ($query->num_rows > 0) {
            $brandOrders = (int)$query->row['brand_orders'];
            $totalOrders = (int)$query->row['total_orders'];
            return $totalOrders > 0 ? $brandOrders / $totalOrders : 0;
        }
        
        return 0;
    }
    
    /**
     * Calculates mobile usage rate for a customer.
     *
     * @param object $db
     * @param int $customerId
     * @param string $startDate
     * @param string $endDate
     * @return float
     */
    protected function calculateMobileUsageRate($db, int $customerId, string $startDate, string $endDate): float
    {
        $query = $db->query("
            SELECT
                COUNT(*) as total_sessions,
                SUM(CASE WHEN device_type = 'mobile' THEN 1 ELSE 0 END) as mobile_sessions
            FROM `mas_customer_sessions`
            WHERE `customer_id` = '" . (int)$customerId . "'
            AND `session_start` >= '" . $db->escape($startDate) . "'
            AND `session_start` <= '" . $db->escape($endDate) . "'
        ");
        
        $totalSessions = (int)($query->row['total_sessions'] ?? 0);
        $mobileSessions = (int)($query->row['mobile_sessions'] ?? 0);
        
        return $totalSessions > 0 ? $mobileSessions / $totalSessions : 0;
    }
    
    /**
     * Calculates social engagement for a customer.
     *
     * @param object $db
     * @param int $customerId
     * @param string $startDate
     * @param string $endDate
     * @return int
     */
    protected function calculateSocialEngagement($db, int $customerId, string $startDate, string $endDate): int
    {
        $query = $db->query("
            SELECT COUNT(*) as social_interactions
            FROM `mas_customer_activity`
            WHERE `customer_id` = '" . (int)$customerId . "'
            AND `activity_type` IN ('social_share', 'social_like', 'social_comment')
            AND `created_at` >= '" . $db->escape($startDate) . "'
            AND `created_at` <= '" . $db->escape($endDate) . "'
        ");
        
        return (int)($query->row['social_interactions'] ?? 0);
    }
    
    /**
     * Calculates review activity for a customer.
     *
     * @param object $db
     * @param int $customerId
     * @param string $startDate
     * @param string $endDate
     * @return int
     */
    protected function calculateReviewActivity($db, int $customerId, string $startDate, string $endDate): int
    {
        $query = $db->query("
            SELECT COUNT(*) as review_count
            FROM `review`
            WHERE `customer_id` = '" . (int)$customerId . "'
            AND `date_added` >= '" . $db->escape($startDate) . "'
            AND `date_added` <= '" . $db->escape($endDate) . "'
            AND `status` = 1
        ");
        
        return (int)($query->row['review_count'] ?? 0);
    }
    
    /**
     * Calculates discount usage rate for a customer.
     *
     * @param object $db
     * @param int $customerId
     * @param string $startDate
     * @param string $endDate
     * @return float
     */
    protected function calculateDiscountUsageRate($db, int $customerId, string $startDate, string $endDate): float
    {
        $query = $db->query("
            SELECT
                COUNT(*) as total_orders,
                SUM(CASE WHEN o.total < o.order_total THEN 1 ELSE 0 END) as discount_orders
            FROM `order` o
            WHERE o.customer_id = '" . (int)$customerId . "'
            AND o.date_added >= '" . $db->escape($startDate) . "'
            AND o.date_added <= '" . $db->escape($endDate) . "'
            AND o.order_status_id IN (2, 3, 5)
        ");
        
        $totalOrders = (int)($query->row['total_orders'] ?? 0);
        $discountOrders = (int)($query->row['discount_orders'] ?? 0);
        
        return $totalOrders > 0 ? $discountOrders / $totalOrders : 0;
    }
    
    /**
     * Calculates price sensitivity for a customer.
     *
     * @param object $db
     * @param int $customerId
     * @param string $startDate
     * @param string $endDate
     * @return float
     */
    protected function calculatePriceSensitivity($db, int $customerId, string $startDate, string $endDate): float
    {
        $query = $db->query("
            SELECT
                AVG(op.price) as avg_price,
                (SELECT AVG(p.price) FROM `product` p WHERE p.status = 1) as market_avg_price
            FROM `order` o
            JOIN `order_product` op ON o.order_id = op.order_id
            WHERE o.customer_id = '" . (int)$customerId . "'
            AND o.date_added >= '" . $db->escape($startDate) . "'
            AND o.date_added <= '" . $db->escape($endDate) . "'
        ");
        
        $avgPrice = (float)($query->row['avg_price'] ?? 0);
        $marketAvgPrice = (float)($query->row['market_avg_price'] ?? 0);
        
        return $marketAvgPrice > 0 ? 1 - ($avgPrice / $marketAvgPrice) : 0;
    }
    
    /**
     * Calculates overall engagement score.
     *
     * @param array $metrics
     * @return int
     */
    protected function calculateEngagementScore(array $metrics): int
    {
        $weightBrowsing = ArrayHelper::get($this->config, 'weight_browsing', 1.0);
        $weightEngagement = ArrayHelper::get($this->config, 'weight_engagement', 1.0);
        $weightPurchase = ArrayHelper::get($this->config, 'weight_purchase', 1.0);
        
        $browsingScore = min(100, ($metrics['page_views'] / 10) + ($metrics['session_duration'] / 60));
        $engagementScore = ($metrics['email_engagement'] * 50) + ($metrics['social_engagement'] * 5);
        $purchaseScore = ($metrics['purchase_rate'] * 50) + (1 - $metrics['cart_abandonment_rate']) * 50;
        
        $totalScore = ($browsingScore * $weightBrowsing) +
        ($engagementScore * $weightEngagement) +
        ($purchaseScore * $weightPurchase);
        
        $totalWeight = $weightBrowsing + $weightEngagement + $weightPurchase;
        
        return min(100, (int)($totalScore / $totalWeight));
    }
    
    /**
     * Calculates activity level.
     *
     * @param array $metrics
     * @return string
     */
    protected function calculateActivityLevel(array $metrics): string
    {
        $score = $metrics['engagement_score'];
        
        if ($score >= 80) return 'very_high';
        if ($score >= 60) return 'high';
        if ($score >= 40) return 'medium';
        if ($score >= 20) return 'low';
        return 'very_low';
    }
    
    /**
     * Applies predefined segmentation.
     *
     * @param array $behavioralData
     * @return array
     */
    protected function applyPredefinedSegmentation(array $behavioralData): array
    {
        $targetSegment = ArrayHelper::get($this->config, 'target_segment');
        if (!$targetSegment || !isset($this->behavioralSegments[$targetSegment])) {
            return [];
        }
        
        $segmentDef = $this->behavioralSegments[$targetSegment];
        $matchingCustomers = [];
        
        foreach ($behavioralData as $customerId => $data) {
            if ($this->matchesBehavioralDefinition($data, $segmentDef)) {
                $matchingCustomers[] = $data['customer_id'];
            }
        }
        
        return array_filter($matchingCustomers, function($id) { return $id > 0; });
    }
    
    /**
     * Applies custom metrics segmentation.
     *
     * @param array $behavioralData
     * @return array
     */
    protected function applyCustomMetricsSegmentation(array $behavioralData): array
    {
        $matchingCustomers = [];
        
        foreach ($behavioralData as $customerId => $data) {
            if ($this->matchesCustomCriteria($data)) {
                $matchingCustomers[] = $data['customer_id'];
            }
        }
        
        return array_filter($matchingCustomers, function($id) { return $id > 0; });
    }
    
    /**
     * Applies engagement score segmentation.
     *
     * @param array $behavioralData
     * @return array
     */
    protected function applyEngagementScoreSegmentation(array $behavioralData): array
    {
        $minScore = ArrayHelper::get($this->config, 'engagement_score_min', 0);
        $maxScore = ArrayHelper::get($this->config, 'engagement_score_max', 100);
        $matchingCustomers = [];
        
        foreach ($behavioralData as $customerId => $data) {
            if ($data['engagement_score'] >= $minScore && $data['engagement_score'] <= $maxScore) {
                $matchingCustomers[] = $data['customer_id'];
            }
        }
        
        return array_filter($matchingCustomers, function($id) { return $id > 0; });
    }
    
    /**
     * Applies activity level segmentation.
     *
     * @param array $behavioralData
     * @return array
     */
    protected function applyActivityLevelSegmentation(array $behavioralData): array
    {
        $targetLevel = ArrayHelper::get($this->config, 'activity_level');
        if (!$targetLevel) {
            return [];
        }
        
        $matchingCustomers = [];
        
        foreach ($behavioralData as $customerId => $data) {
            if ($data['activity_level'] === $targetLevel) {
                $matchingCustomers[] = $data['customer_id'];
            }
        }
        
        return array_filter($matchingCustomers, function($id) { return $id > 0; });
    }
    
    /**
     * Checks if data matches behavioral definition.
     *
     * @param array $data
     * @param array $definition
     * @return bool
     */
    protected function matchesBehavioralDefinition(array $data, array $definition): bool
    {
        foreach ($definition as $metric => $criteria) {
            $value = $data[$metric] ?? 0;
            
            if (isset($criteria['min']) && $value < $criteria['min']) {
                return false;
            }
            
            if (isset($criteria['max']) && $value > $criteria['max']) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Checks if data matches custom criteria.
     *
     * @param array $data
     * @return bool
     */
    protected function matchesCustomCriteria(array $data): bool
    {
        $criteria = [
            'page_views' => ['min_page_views', 'max_page_views'],
            'session_duration' => ['min_session_duration', 'max_session_duration'],
            'email_engagement' => ['min_email_engagement', 'max_email_engagement'],
            'cart_abandonment_rate' => ['min_cart_abandonment', 'max_cart_abandonment'],
            'login_frequency' => ['min_login_frequency', 'max_login_frequency'],
            'purchase_rate' => ['min_purchase_rate', 'max_purchase_rate'],
            'category_concentration' => ['min_category_concentration', null],
            'brand_loyalty' => ['min_brand_loyalty', null],
            'social_engagement' => ['min_social_engagement', null],
            'review_activity' => ['min_review_activity', null],
        ];
        
        foreach ($criteria as $metric => [$minKey, $maxKey]) {
            $value = $data[$metric] ?? 0;
            
            $min = ArrayHelper::get($this->config, $minKey);
            if ($min !== null && $value < $min) {
                return false;
            }
            
            if ($maxKey) {
                $max = ArrayHelper::get($this->config, $maxKey);
                if ($max !== null && $value > $max) {
                    return false;
                }
            }
        }
        
        return true;
    }
    
    /**
     * Validates numeric ranges.
     *
     * @return void
     */
    protected function validateNumericRanges(): void
    {
        $ranges = [
            ['min_page_views', 'max_page_views'],
            ['min_session_duration', 'max_session_duration'],
            ['min_email_engagement', 'max_email_engagement'],
            ['min_cart_abandonment', 'max_cart_abandonment'],
            ['min_login_frequency', 'max_login_frequency'],
            ['min_purchase_rate', 'max_purchase_rate'],
            ['engagement_score_min', 'engagement_score_max'],
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
     * Validates weights.
     *
     * @return void
     */
    protected function validateWeights(): void
    {
        $weights = ['weight_browsing', 'weight_engagement', 'weight_purchase'];
        
        foreach ($weights as $weight) {
            $value = ArrayHelper::get($this->config, $weight);
            if ($value !== null && ($value < 0.1 || $value > 5.0)) {
                $this->validationErrors[] = "Weight {$weight} must be between 0.1 and 5.0";
            }
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
