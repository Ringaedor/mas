<?php
/**
 * MAS - Marketing Automation Suite
 * ActionNode
 *
 * Executes an action within a workflow (send email, SMS, push, AI task, HTTP request, etc.).
 * Integrates with ProviderManager to send messages through the selected provider,
 * performs merge-tag replacement, supports conditional execution, attachments,
 * and logs detailed execution results for reporting.
 *
 * Path: system/library/mas/workflow/nodes/ActionNode.php
 */

namespace Opencart\Library\Mas\Workflow\Node;

use Opencart\Library\Mas\Workflow\Node\AbstractNode;
use Opencart\Library\Mas\Exception\ProviderException;
use Opencart\Library\Mas\Exception\ValidationException;
use Opencart\Library\Mas\Helper\ArrayHelper;
use Opencart\Library\Mas\Helper\DateHelper;

class ActionNode extends AbstractNode
{
    protected string $version = '1.0.0';

    /* -------- Node metadata -------- */
    public static function getType(): string
    {
        return 'action';
    }

    public function getLabel(): string
    {
        return 'Action: ' . ucfirst($this->getConfigValue('action_type', 'send'));
    }

    public function getDescription(): string
    {
        return $this->getConfigValue('description', 'Executes an action via provider');
    }

    /* -------- Configuration schema -------- */
    public static function getConfigSchema(): array
    {
        return [
            'action_type' => [
                'type'        => 'string',
                'required'    => true,
                'label'       => 'Action Type',
                'description' => 'The type of action to execute',
                'options'     => [
                    'send'          => 'Send Message',
                    'http_request'  => 'HTTP Request',
                    'ai_task'       => 'AI Task',
                ],
            ],
            'provider_code' => [
                'type'        => 'string',
                'required'    => false,
                'label'       => 'Provider Code',
                'description' => 'Provider code to handle the action (e.g. smtp, twilio, onesignal, openai)',
            ],
            'channel' => [
                'type'        => 'string',
                'required'    => false,
                'label'       => 'Channel',
                'description' => 'Channel (email, sms, push, ai)',
                'options'     => ['email', 'sms', 'push', 'ai'],
            ],
            /* Message settings */
            'template_id' => [
                'type'        => 'string',
                'required'    => false,
                'label'       => 'Template ID',
                'description' => 'ID of the pre-saved template to render and send',
            ],
            'subject' => [
                'type'        => 'string',
                'required'    => false,
                'label'       => 'Subject / Title',
            ],
            'body_html' => [
                'type'        => 'string',
                'required'    => false,
                'label'       => 'HTML Body',
                'description' => 'HTML content (merge-tags allowed)',
            ],
            'body_text' => [
                'type'        => 'string',
                'required'    => false,
                'label'       => 'Plain-Text Body',
            ],
            'attachments' => [
                'type'        => 'array',
                'required'    => false,
                'label'       => 'Attachments',
            ],
            /* HTTP request */
            'http_url' => [
                'type'        => 'url',
                'required'    => false,
                'label'       => 'HTTP URL',
            ],
            'http_method' => [
                'type'        => 'select',
                'required'    => false,
                'default'     => 'POST',
                'options'     => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
                'label'       => 'HTTP Method',
            ],
            'http_headers' => [
                'type'        => 'array',
                'required'    => false,
                'label'       => 'HTTP Headers',
            ],
            'http_body' => [
                'type'        => 'string',
                'required'    => false,
                'label'       => 'HTTP Body (JSON allowed)',
            ],
            /* AI task */
            'ai_prompt' => [
                'type'        => 'string',
                'required'    => false,
                'label'       => 'AI Prompt',
            ],
            /* Generic options */
            'retry_attempts' => [
                'type'        => 'integer',
                'required'    => false,
                'default'     => 0,
                'min'         => 0,
                'max'         => 5,
                'label'       => 'Retry Attempts',
            ],
            'timeout_seconds' => [
                'type'        => 'integer',
                'required'    => false,
                'default'     => 30,
                'min'         => 5,
                'max'         => 300,
                'label'       => 'Timeout (s)',
            ],
            'enabled' => [
                'type'        => 'boolean',
                'required'    => false,
                'default'     => true,
                'label'       => 'Enabled',
            ],
        ];
    }

    /* -------- Node execution -------- */
    protected function executeNode(array $context): array
    {
        if (!$this->getConfigValue('enabled', true)) {
            return ['success' => true, 'skipped' => true, 'reason' => 'Action disabled'];
        }

        $actionType = $this->getConfigValue('action_type', 'send');

        try {
            switch ($actionType) {
                case 'send':
                    return $this->executeSend($context);

                case 'http_request':
                    return $this->executeHttpRequest($context);

                case 'ai_task':
                    return $this->executeAiTask($context);

                default:
                    throw new WorkflowException("Unsupported action type: {$actionType}");
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /* -------- Send message via provider -------- */
    protected function executeSend(array $context): array
    {
        $providerCode = $this->getConfigValue('provider_code');
        if (!$providerCode) {
            throw new ValidationException('Provider code is required for send actions');
        }

        $provider = $this->getProvider($providerCode);

        /* Merge tags */
        $payload = $this->buildSendPayload($context);

        /* Retry logic */
        $attempts = 0;
        $maxAttempts = (int)$this->getConfigValue('retry_attempts', 0);
        $timeout = (int)$this->getConfigValue('timeout_seconds', 30);

        do {
            try {
                $response = $provider->send($payload + ['timeout' => $timeout]);
                return [
                    'success'   => true,
                    'meta'      => $response,
                    'output'    => ['provider' => $providerCode],
                ];
            } catch (ProviderException $e) {
                $attempts++;
                if ($attempts > $maxAttempts) {
                    throw $e;
                }
                sleep(1); // simple back-off
            }
        } while ($attempts <= $maxAttempts);

        return ['success' => false, 'error' => 'Max retry attempts exceeded'];
    }

    protected function buildSendPayload(array $context): array
    {
        /* Render merge tags {{tag}} using context */
        $render = function ($string) use ($context) {
            return preg_replace_callback('/\{\{([\w\.]+)\}\}/', function ($matches) use ($context) {
                return ArrayHelper::get($context, $matches[1], '');
            }, (string)$string);
        };

        return [
            'to'         => ArrayHelper::get($context, 'to'),
            'subject'    => $render($this->getConfigValue('subject')),
            'html_body'  => $render($this->getConfigValue('body_html')),
            'text_body'  => $render($this->getConfigValue('body_text')),
            'attachments'=> $this->getConfigValue('attachments'),
            'template_id'=> $this->getConfigValue('template_id'),
        ];
    }

    /* -------- HTTP request -------- */
    protected function executeHttpRequest(array $context): array
    {
        $url = $this->getConfigValue('http_url');
        if (!$url) {
            throw new ValidationException('HTTP URL is required');
        }

        $method  = strtoupper($this->getConfigValue('http_method', 'POST'));
        $headers = $this->getConfigValue('http_headers', []);
        $body    = $this->getConfigValue('http_body');
        $timeout = (int)$this->getConfigValue('timeout_seconds', 30);

        $client = new \GuzzleHttp\Client(['timeout' => $timeout]);

        $options = ['headers' => $headers];
        if (in_array($method, ['POST','PUT','PATCH'])) {
            $options['body'] = $body;
        }

        try {
            $response = $client->request($method, $url, $options);
            return [
                'success' => true,
                'meta'    => [
                    'status_code' => $response->getStatusCode(),
                    'body'        => $response->getBody()->getContents(),
                    'headers'     => $response->getHeaders(),
                ],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /* -------- AI task -------- */
    protected function executeAiTask(array $context): array
    {
        $providerCode = $this->getConfigValue('provider_code', 'openai');
        $prompt       = $this->getConfigValue('ai_prompt');

        if (!$prompt) {
            throw new ValidationException('AI prompt is required for AI tasks');
        }

        $provider = $this->getProvider($providerCode);

        $payload = [
            'type'   => 'chat',
            'prompt' => $prompt,
            'model'  => $this->getConfigValue('ai_model', 'gpt-3.5-turbo'),
        ];

        try {
            $response = $provider->send($payload);
            return [
                'success' => true,
                'output'  => ['ai_response' => $response['output'] ?? null],
                'meta'    => $response,
            ];
        } catch (ProviderException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /* -------- Custom validation for action node -------- */
    protected function validateCustom(): void
    {
        $actionType = $this->getConfigValue('action_type');

        if ($actionType === 'send' && !$this->getConfigValue('provider_code')) {
            $this->addValidationError('Provider code is required for send actions');
        }

        if ($actionType === 'http_request' && !$this->getConfigValue('http_url')) {
            $this->addValidationError('HTTP URL is required for HTTP request actions');
        }

        if ($actionType === 'ai_task' && !$this->getConfigValue('ai_prompt')) {
            $this->addValidationError('AI prompt is required for AI tasks');
        }
    }
}
