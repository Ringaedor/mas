<?php
/**
 * MAS - Marketing Automation Suite
 * SendEmailAction
 *
 * Action node that sends an email via a configured email provider.
 * Integrates with ProviderManager to retrieve and use the specified provider.
 * Supports merge-tags, HTML/text bodies, attachments and CC/BCC.
 *
 * Path: system/library/mas/workflow/actions/SendEmailAction.php
 */

namespace Opencart\Library\Mas\Workflow\Action;

use Opencart\Library\Mas\Workflow\Node\AbstractNode;
use Opencart\Library\Mas\Exception\ValidationException;
use Opencart\Library\Mas\Exception\ProviderException;
use Opencart\Library\Mas\Helper\ArrayHelper;

class SendEmailAction extends AbstractNode
{
    protected string $version = '1.0.0';

    public static function getType(): string
    {
        return 'action_send_email';
    }

    public function getLabel(): string
    {
        return 'Send Email';
    }

    public function getDescription(): string
    {
        return 'Sends an email using the configured email provider';
    }

    public static function getConfigSchema(): array
    {
        return [
            'provider_code' => [
                'type'     => 'string',
                'required' => true,
                'label'    => 'Email Provider',
                'description' => 'Code of the email provider to use (e.g. smtp, sendgrid)',
            ],
            'to' => [
                'type'     => 'string',
                'required' => true,
                'label'    => 'To',
                'description' => 'Recipient email address or comma-separated list',
            ],
            'cc' => [
                'type'     => 'string',
                'required' => false,
                'label'    => 'CC',
                'description' => 'CC email addresses (comma-separated)',
            ],
            'bcc' => [
                'type'     => 'string',
                'required' => false,
                'label'    => 'BCC',
                'description' => 'BCC email addresses (comma-separated)',
            ],
            'subject' => [
                'type'     => 'string',
                'required' => true,
                'label'    => 'Subject',
            ],
            'body_html' => [
                'type'     => 'string',
                'required' => false,
                'label'    => 'HTML Body',
            ],
            'body_text' => [
                'type'     => 'string',
                'required' => false,
                'label'    => 'Plain Text Body',
            ],
            'attachments' => [
                'type'     => 'array',
                'required' => false,
                'label'    => 'Attachments',
                'description' => 'Array of file paths or [' . "'path'=>'', 'name'=>''". ']',
            ],
            'merge_tags' => [
                'type'     => 'array',
                'required' => false,
                'label'    => 'Merge Tags',
                'description' => 'Key => placeholder mapping for merge-tags',
            ],
            'retry_attempts' => [
                'type'     => 'integer',
                'required' => false,
                'default'  => 0,
                'min'      => 0,
                'label'    => 'Retry Attempts',
            ],
            'timeout_seconds' => [
                'type'     => 'integer',
                'required' => false,
                'default'  => 30,
                'min'      => 5,
                'label'    => 'Timeout (s)',
            ],
            'enabled' => [
                'type'     => 'boolean',
                'required' => false,
                'default'  => true,
                'label'    => 'Enabled',
            ],
        ];
    }

    protected function executeNode(array $context): array
    {
        if (!$this->getConfigValue('enabled', true)) {
            return ['success'=>true, 'skipped'=>true, 'reason'=>'Action disabled'];
        }

        $providerCode = $this->getConfigValue('provider_code');
        if (!$providerCode) {
            throw new ValidationException('provider_code is required');
        }

        $provider = $this->getProvider($providerCode);
        if (!method_exists($provider, 'send')) {
            throw new ProviderException('Provider does not support send()', $providerCode, self::getType());
        }

        // Build payload
        $payload = $this->buildPayload($context);

        $attempts = 0;
        $maxAttempts = (int)$this->getConfigValue('retry_attempts', 0);
        $timeout     = (int)$this->getConfigValue('timeout_seconds', 30);

        do {
            try {
                $response = $provider->send($payload + ['timeout'=>$timeout]);
                return [
                    'success'=>true,
                    'meta'=>$response,
                    'output'=>['provider'=>$providerCode,'message_id'=>$response['message_id']??null],
                ];
            } catch (ProviderException $e) {
                $attempts++;
                if ($attempts > $maxAttempts) {
                    throw $e;
                }
                sleep(1);
            }
        } while ($attempts <= $maxAttempts);

        return ['success'=>false,'error'=>'Max retries exceeded'];
    }

    protected function buildPayload(array $context): array
    {
        $render = function($value) use($context) {
            if (!is_string($value)) return $value;
            return preg_replace_callback('/\{\{([\w\.]+)\}\}/', 
                fn($m)=>ArrayHelper::get($context,$m[1],''), 
                $value
            );
        };

        $to = array_map('trim', explode(',', $this->getConfigValue('to')));
        $cc = $this->getConfigValue('cc') ? array_map('trim',explode(',',$this->getConfigValue('cc'))) : [];
        $bcc= $this->getConfigValue('bcc')? array_map('trim',explode(',',$this->getConfigValue('bcc'))):[];

        return [
            'to'         => $to,
            'cc'         => $cc,
            'bcc'        => $bcc,
            'subject'    => $render($this->getConfigValue('subject')),
            'html_body'  => $render($this->getConfigValue('body_html')),
            'text_body'  => $render($this->getConfigValue('body_text')),
            'attachments'=> $this->getConfigValue('attachments') ?? [],
        ];
    }
}
