<?php
/**
 * MAS - Marketing Automation Suite
 * OpenAI Provider
 *
 * AI provider that integrates OpenAI’s REST API for text generation, chat completion,
 * embeddings and content moderation. Supports multiple models with individual
 * configuration schemas and runtime switching.
 *
 * Dependencies:
 *   - guzzlehttp/guzzle ^7.8
 *
 * NOTE: Install Guzzle in OpenCart root via composer or include it in your extension package:
 *   composer require guzzlehttp/guzzle:^7.8
 */

namespace Opencart\Library\Mas\Provider\Ai;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Opencart\Library\Mas\Provider\AbstractProvider;
use Opencart\Library\Mas\Exception\ProviderException;
use Opencart\Library\Mas\Helper\ArrayHelper;

class OpenAIProvider extends AbstractProvider
{
    public const VERSION = '1.0.0';

    /** OpenAI API base URL */
    private const BASE_URL = 'https://api.openai.com/v1';

    /** @var Client|null */
    protected ?Client $http = null;

    /** @var string */
    protected string $defaultModel = 'gpt-3.5-turbo';

    /** {@inheritdoc} */
    public static function getName(): string
    {
        return 'OpenAI';
    }

    /** {@inheritdoc} */
    public static function getDescription(): string
    {
        return 'OpenAI REST API provider for ChatGPT, GPT-4 and embeddings';
    }

    /** {@inheritdoc} */
    public static function getType(): string
    {
        return self::TYPE_AI;
    }

    /** {@inheritdoc} */
    public static function getCapabilities(): array
    {
        return ['text_generate', 'chat', 'embedding', 'moderation'];
    }

    /** {@inheritdoc} */
    public static function getSetupSchema(): array
    {
        return [
            'provider' => [
                'name'        => static::getName(),
                'type'        => static::getType(),
                'version'     => static::getVersion(),
                'description' => static::getDescription(),
            ],
            'models' => [
                'gpt-3.5-turbo' => [
                    'label'  => 'GPT-3.5 Turbo',
                    'schema' => [
                        'temperature' => [
                            'type'        => 'float',
                            'required'    => false,
                            'default'     => 1.0,
                            'min'         => 0,
                            'max'         => 2,
                            'description' => 'Creativity level',
                        ],
                        'max_tokens' => [
                            'type'        => 'integer',
                            'required'    => false,
                            'default'     => 1,024,
                            'min'         => 1,
                            'max'         => 4,096,
                            'description' => 'Maximum tokens generated',
                        ],
                    ],
                ],
                'gpt-4o' => [
                    'label'  => 'GPT-4o',
                    'schema' => [
                        'temperature' => [
                            'type'        => 'float',
                            'required'    => false,
                            'default'     => 0.7,
                            'min'         => 0,
                            'max'         => 2,
                            'description' => 'Creativity level',
                        ],
                        'max_tokens' => [
                            'type'        => 'integer',
                            'required'    => false,
                            'default'     => 8,192,
                            'min'         => 1,
                            'max'         => 8,192,
                            'description' => 'Maximum tokens generated',
                        ],
                        'tools' => [
                            'type'        => 'array',
                            'required'    => false,
                            'allowed'     => ['code_interpreter', 'data_browser'],
                            'description' => 'Optional GPT-4o tools',
                        ],
                    ],
                ],
            ],
            'schema' => [
                'api_key' => [
                    'type'        => 'password',
                    'required'    => true,
                    'label'       => 'OpenAI API Key',
                    'description' => 'Secret key obtained from your OpenAI dashboard',
                ],
                'default_model' => [
                    'type'        => 'select',
                    'required'    => false,
                    'default'     => 'gpt-3.5-turbo',
                    'options'     => ['gpt-3.5-turbo', 'gpt-4o'],
                    'label'       => 'Default Model',
                    'description' => 'Model to use when not specified at runtime',
                ],
                'timeout' => [
                    'type'        => 'integer',
                    'required'    => false,
                    'default'     => 20,
                    'min'         => 5,
                    'max'         => 120,
                    'label'       => 'HTTP Timeout',
                    'description' => 'Request timeout in seconds',
                ],
            ],
            'capabilities' => static::getCapabilities(),
        ];
    }

    /** {@inheritdoc} */
    public function authenticate(array $config): bool
    {
        if (empty($config['api_key'])) {
            $this->lastError = 'API key missing';
            return false;
        }

        $this->setConfig($config);
        $this->defaultModel = $config['default_model'] ?? $this->defaultModel;
        $this->authenticated = true;
        $this->lastError = null;

        return true;
    }

    /** {@inheritdoc} */
    public function testConnection(): bool
    {
        if (!$this->isAuthenticated()) {
            $this->lastError = 'Provider not authenticated';
            return false;
        }

        // A lightweight models list call for validation
        try {
            $response = $this->http()->get(self::BASE_URL . '/models');
            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            $this->lastError = 'Connection failed: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Sends a request to OpenAI.
     *
     * Payload structure example:
     * [
     *   'type'   => 'chat', // chat|completion|embedding|moderation
     *   'model'  => 'gpt-3.5-turbo',
     *   'prompt' => '...',
     *   ...additional OpenAI parameters
     * ]
     *
     * {@inheritdoc}
     */
    public function send(array $payload): array
    {
        if (!$this->isAuthenticated()) {
            throw new ProviderException('OpenAI provider is not authenticated', self::getType(), self::getName());
        }

        $type  = strtolower($payload['type'] ?? 'chat');
        $model = $payload['model'] ?? $this->defaultModel;

        try {
            switch ($type) {
                case 'chat':
                    $endpoint = '/chat/completions';
                    $body     = $this->buildChatBody($model, $payload);
                    break;

                case 'completion':
                case 'text_generate':
                    $endpoint = '/completions';
                    $body     = $this->buildCompletionBody($model, $payload);
                    break;

                case 'embedding':
                    $endpoint = '/embeddings';
                    $body     = $this->buildEmbeddingBody($model, $payload);
                    break;

                case 'moderation':
                    $endpoint = '/moderations';
                    $body     = ['input' => $payload['input'] ?? ''];
                    break;

                default:
                    throw new ProviderException("Unsupported request type: {$type}", self::getType(), self::getName());
            }

            $response = $this->http()->post(self::BASE_URL . $endpoint, ['json' => $body]);
            $data     = json_decode($response->getBody()->getContents(), true);

            return [
                'success'    => true,
                'message_id' => $data['id'] ?? null,
                'output'     => $data,
                'meta'       => [
                    'model'    => $model,
                    'type'     => $type,
                    'prompt_tokens'      => $data['usage']['prompt_tokens']  ?? null,
                    'completion_tokens'  => $data['usage']['completion_tokens'] ?? null,
                    'total_tokens'       => $data['usage']['total_tokens'] ?? null,
                ],
            ];
        } catch (GuzzleException $e) {
            throw new ProviderException('OpenAI request failed: ' . $e->getMessage(), self::getType(), self::getName(), 0, [], $e);
        }
    }

    /**
     * Builds body for Chat Completions endpoint.
     *
     * @param string $model
     * @param array  $payload
     * @return array
     */
    protected function buildChatBody(string $model, array $payload): array
    {
        $messages = $payload['messages'] ?? [['role' => 'user', 'content' => $payload['prompt'] ?? '']];
        return [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => $payload['temperature'] ?? 1.0,
            'max_tokens'  => $payload['max_tokens']  ?? 1,024,
            'tools'       => $payload['tools']       ?? null,
        ];
    }

    /**
     * Builds body for Text Completions endpoint.
     *
     * @param string $model
     * @param array  $payload
     * @return array
     */
    protected function buildCompletionBody(string $model, array $payload): array
    {
        return [
            'model'       => $model,
            'prompt'      => $payload['prompt'] ?? '',
            'temperature' => $payload['temperature'] ?? 1.0,
            'max_tokens'  => $payload['max_tokens']  ?? 256,
        ];
    }

    /**
     * Builds body for Embeddings endpoint.
     *
     * @param string $model
     * @param array  $payload
     * @return array
     */
    protected function buildEmbeddingBody(string $model, array $payload): array
    {
        return [
            'model' => $model,
            'input' => $payload['input'] ?? '',
        ];
    }

    /**
     * Creates and returns a configured Guzzle client.
     *
     * @return Client
     */
    protected function http(): Client
    {
        if ($this->http === null) {
            $timeout = (int)ArrayHelper::get($this->config, 'timeout', 20);

            $this->http = new Client([
                'base_uri' => self::BASE_URL,
                'timeout'  => $timeout,
                'headers'  => [
                    'Authorization' => 'Bearer ' . $this->config['api_key'],
                    'Content-Type'  => 'application/json',
                    'X-Client-Info' => 'MAS/' . static::VERSION,
                ],
            ]);
        }

        return $this->http;
    }

    /** {@inheritdoc} */
    public function getSanitisedConfig(array $fieldsToMask = null): array
    {
        $mask = $fieldsToMask ?: ['api_key'];
        return parent::getSanitisedConfig($mask);
    }
}
