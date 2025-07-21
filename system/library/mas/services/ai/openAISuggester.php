<?php
/**
 * MAS - Marketing Automation Suite
 * OpenAISuggester
 *
 * AI segment suggestor implementation using OpenAI API.
 * Leverages chat completions and embeddings to generate segment
 * names, descriptions, characteristics, and recommended actions.
 *
 * Path: system/library/mas/services/ai/OpenAISuggester.php
 *
 * © 2025 Your Company – Proprietary
 */

namespace Opencart\Library\Mas\Services\Ai;

use Opencart\Library\Mas\Interfaces\AiSuggestorInterface;
use Opencart\Library\Mas\Exception\AiSuggestorException;
use Opencart\Library\Mas\Helper\ArrayHelper;
use Opencart\Library\Mas\Helper\DateHelper;
use Opencart\Library\Mas\ServiceContainer;
use Opencart\Library\Mas\Exception\ProviderException;

class OpenAISuggester implements AiSuggestorInterface
{
    protected ServiceContainer $container;
    protected array $config;
    protected string $model;
    protected float $temperature;
    protected int $maxTokens;
    protected string $systemPrompt;
    protected string $userPromptTemplate;

    public const VERSION = '1.0.0';

    public function __construct(ServiceContainer $container, array $config = [])
    {
        $this->container = $container;
        $this->config = $config;

        // Load defaults or override from config
        $this->model       = $config['model']       ?? 'gpt-4';
        $this->temperature = $config['temperature'] ?? 0.3;
        $this->maxTokens   = $config['max_tokens']  ?? 2000;

        // System prompt guiding segment suggestion
        $this->systemPrompt = $config['system_prompt'] ?? 
            "You are a marketing automation assistant. Analyze customer data and suggest segments.";

        // User prompt template; placeholders: {{goal}}, {{data_sample}}
        $this->userPromptTemplate = $config['user_prompt_template'] ??
            "Goal: {{goal}}\nCustomer Data Sample: {{data_sample}}\n\nProvide JSON:\n" .
            "[\n  {\n    \"id\": \"string\",\n    \"name\": \"string\",\n    \"description\": \"string\",\n    \"characteristics\": [\"string\"],\n    \"actions\": [\"string\"]\n  }\n]";
    }

    public static function getType(): string
    {
        return 'openai_suggester';
    }

    public static function getLabel(): string
    {
        return 'OpenAI Segment Suggestor';
    }

    public static function getDescription(): string
    {
        return 'Uses OpenAI chat completions to suggest customer segments.';
    }

    public static function getConfigSchema(): array
    {
        return [
            'model' => [
                'type' => 'string',
                'required' => false,
                'default' => 'gpt-4',
                'label' => 'OpenAI Model',
            ],
            'temperature' => [
                'type' => 'float',
                'required' => false,
                'default' => 0.3,
                'min' => 0,
                'max' => 1,
                'label' => 'Temperature',
            ],
            'max_tokens' => [
                'type' => 'integer',
                'required' => false,
                'default' => 2000,
                'min' => 100,
                'max' => 8000,
                'label' => 'Max Tokens',
            ],
            'system_prompt' => [
                'type' => 'string',
                'required' => false,
                'label' => 'System Prompt',
            ],
            'user_prompt_template' => [
                'type' => 'string',
                'required' => false,
                'label' => 'User Prompt Template',
            ],
        ];
    }

    public function setConfig(array $config): void
    {
        $this->__construct($this->container, $config);
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function validate(): bool
    {
        if (!is_string($this->model) || $this->model === '') {
            return false;
        }
        if ($this->temperature < 0.0 || $this->temperature > 1.0) {
            return false;
        }
        if ($this->maxTokens < 100) {
            return false;
        }
        return true;
    }

    /**
     * @param array $input  ['goal'=>string, 'data'=>array]
     * @return array
     * @throws AiSuggestorException
     */
    public function suggest(array $input): array
    {
        if (!$this->validate()) {
            throw new AiSuggestorException('Invalid OpenAISuggester configuration');
        }

        $goal = ArrayHelper::get($input, 'goal', 'general segment discovery');
        $data = ArrayHelper::get($input, 'data', []);
        $sample = array_slice($data, 0, 10);

        // Build messages
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt],
            ['role' => 'user',   'content' => strtr($this->userPromptTemplate, [
                '{{goal}}'        => $goal,
                '{{data_sample}}' => json_encode($sample),
            ])],
        ];

        try {
            /** @var \Opencart\Library\Mas\Interfaces\AiProviderInterface $provider */
            $aiGateway = $this->container->get('mas.ai_gateway');
            $response = $aiGateway->chat($prompt, [
                'model' => $this->model,
                'temperature' => $this->temperature,
                'max_tokens' => $this->maxTokens
            ]);

            $response = $provider->send('chat', [
                'model'       => $this->model,
                'messages'    => $messages,
                'temperature' => $this->temperature,
                'max_tokens'  => $this->maxTokens,
            ]);

            $content = $response['output']['choices'][0]['message']['content'] ?? '';
            $suggestions = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            return [
                'success'    => true,
                'provider'   => 'openai',
                'model'      => $this->model,
                'suggestion' => $suggestions,
                'metadata'   => [
                    'usage'    => $response['output']['usage'] ?? null,
                    'timestamp'=> DateHelper::now(),
                ],
            ];

        } catch (ProviderException $e) {
            throw new AiSuggestorException('OpenAI provider error: ' . $e->getMessage(), 0, [], $e);
        } catch (\JsonException $e) {
            throw new AiSuggestorException('Invalid JSON from OpenAI: ' . $e->getMessage(), 0, [], $e);
        }
    }

    public function toArray(): array
    {
        return [
            'type'      => self::getType(),
            'version'   => self::VERSION,
            'config'    => $this->config,
        ];
    }

    public static function fromArray(array $data): self
    {
        $inst = new self(
            ServiceContainer::getInstance(),
            $data['config'] ?? []
        );
        return $inst;
    }
}
