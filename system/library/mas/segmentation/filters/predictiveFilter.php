<?php
/**
 * MAS - Marketing Automation Suite
 * PredictiveFilter
 *
 * Advanced predictive segment filter that leverages AI/ML models
 * and statistical rules to identify customers with high probability
 * of conversion, churn, or target behavior. Integrates with MAS AI
 * suggestors, supports dynamic scoring, and is extensible for
 * different predictive scenarios (e.g., "likely to buy", "at risk",
 * "responds to promo").
 *
 * @copyright Copyright (c) 2025, Your Company
 * @license   Proprietary
 */

namespace Opencart\Library\Mas\Segmentation\Filters;

use Opencart\Library\Mas\Interfaces\SegmentFilterInterface;
use Opencart\Library\Mas\ServiceContainer;
use Opencart\Library\Mas\Exception\SegmentException;
use Opencart\Library\Mas\Helper\ArrayHelper;

class PredictiveFilter implements SegmentFilterInterface
{
    /**
     * @var array Filter configuration
     */
    protected array $config = [];

    /**
     * @var ServiceContainer
     */
    protected ServiceContainer $container;

    /**
     * @var string AI suggestor provider code
     */
    protected string $suggestorCode = 'segment_predictor';

    /**
     * @var string Filter version
     */
    protected string $version = '1.0.0';

    /**
     * Constructor.
     *
     * @param ServiceContainer|null $container
     * @param array $config
     */
    public function __construct(?ServiceContainer $container = null, array $config = [])
    {
        if ($container) {
            $this->container = $container;
        }
        if (!empty($config)) {
            $this->setConfig($config);
        }
    }

    /**
     * Returns the unique filter type.
     *
     * @return string
     */
    public static function getType(): string
    {
        return 'predictive';
    }

    /**
     * Returns a human-readable label for this filter.
     *
     * @return string
     */
    public static function getLabel(): string
    {
        return 'Predictive (AI/ML)';
    }

    /**
     * Returns a description of the filter.
     *
     * @return string
     */
    public static function getDescription(): string
    {
        return 'Selects customers based on AI/ML prediction of conversion, churn, or custom predictive signals.';
    }

    /**
     * Returns the schema for this filter's configuration.
     *
     * @return array
     */
    public static function getConfigSchema(): array
    {
        return [
            'predictive_goal' => [
                'type' => 'select',
                'required' => true,
                'default' => 'likely_to_buy',
                'label' => 'Prediction Goal',
                'description' => 'Target predictive scenario',
                'options' => [
                    'likely_to_buy' => 'Likely to Convert',
                    'likely_to_churn' => 'Likely to Churn',
                    'responds_to_promo' => 'Responds to Promotions',
                    'custom' => 'Custom/Advanced',
                ],
            ],
            'min_score' => [
                'type' => 'float',
                'required' => false,
                'default' => 0.7,
                'min' => 0,
                'max' => 1,
                'label' => 'Min. Predicted Score',
                'description' => 'Threshold for predicted probability/confidence',
            ],
            'max_results' => [
                'type' => 'integer',
                'required' => false,
                'default' => 0,
                'label' => 'Max Results',
                'description' => 'Maximum customers to return (0 = unlimited)',
            ],
            'last_purchase_days' => [
                'type' => 'integer',
                'required' => false,
                'default' => 365,
                'label' => 'Only Customers Active in Last X Days',
            ],
            'custom_params' => [
                'type' => 'array',
                'required' => false,
                'label' => 'Custom AI Params',
                'description' => 'Parameters for advanced predictive use-cases',
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
        $goal = $this->config['predictive_goal'] ?? null;
        $score = $this->config['min_score'] ?? null;

        if (!$goal || !in_array($goal, array_keys(self::getConfigSchema()['predictive_goal']['options']))) {
            return false;
        }
        if ($score !== null && ($score < 0 || $score > 1)) {
            return false;
        }
        return true;
    }

    /**
     * Applies the filter and returns the array of matching customer IDs.
     *
     * @param array $context Context data (may include DB, dataset, etc.)
     * @return array
     * @throws SegmentException
     */
    public function apply(array $context): array
    {
        if (!$this->validate()) {
            throw new SegmentException('Invalid predictive filter configuration');
        }

        // Example: invoke AI suggestor for prediction results
        /** @var \Opencart\Library\Mas\Interfaces\AiSuggestorInterface $suggestor */
        $suggestor = $this->container
            ? $this->container->get('mas.segment_suggestor')
            : null;

        if (!$suggestor) {
            throw new SegmentException('Predictive suggestor not configured/available');
        }

        // Prepare the input for prediction
        $predictionInput = [
            'goal'   => $this->config['predictive_goal'],
            'params' => $this->config['custom_params'] ?? [],
            'min_score' => $this->config['min_score'] ?? 0.7,
            'max_results' => $this->config['max_results'] ?? 0,
            'last_purchase_days' => $this->config['last_purchase_days'] ?? 365,
            'context' => $context,
        ];

        $result = $suggestor->suggest($predictionInput);

        if (!($result['success'] ?? false)) {
            throw new SegmentException('AI prediction failed: ' . ($result['message'] ?? ''));
        }

        // Assume result['suggestion'] contains array of customer IDs with predicted scores
        $matches = [];
        foreach ($result['suggestion'] as $row) {
            if (($row['score'] ?? 0) >= $predictionInput['min_score']) {
                $matches[] = $row['customer_id'];
            }
        }

        if ($predictionInput['max_results'] > 0) {
            $matches = array_slice($matches, 0, $predictionInput['max_results']);
        }

        return $matches;
    }

    /**
     * Serializes the filter to an array for storage.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'type'   => self::getType(),
            'config' => $this->config,
            'version'=> $this->version,
        ];
    }

    /**
     * Creates a filter instance from an array (for deserialization).
     *
     * @param array $data
     * @return static
     */
    public static function fromArray(array $data): self
    {
        $instance = new static();
        $instance->setConfig($data['config'] ?? []);
        $instance->version = $data['version'] ?? '1.0.0';
        return $instance;
    }
}
