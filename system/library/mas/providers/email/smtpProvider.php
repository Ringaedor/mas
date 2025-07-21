<?php
/**
 * MAS - Marketing Automation Suite
 * SMTP Provider
 *
 * Standard SMTP provider for email delivery supporting multiple authentication methods,
 * SSL/TLS encryption, and comprehensive error handling. Provides configuration schema
 * for common SMTP settings and supports both individual and bulk email sending.
 *
 * @copyright Copyright (c) 2025, Your Company
 * @license   Proprietary
 */

namespace Opencart\Library\Mas\Provider\Email;

use Opencart\Library\Mas\Provider\AbstractProvider;
use Opencart\Library\Mas\Exception\ProviderException;
use Opencart\Library\Mas\Helper\ArrayHelper;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class SmtpProvider extends AbstractProvider
{
    /**
     * @var string Provider version
     */
    public const VERSION = '1.0.0';

    /**
     * @var PHPMailer PHPMailer instance
     */
    protected ?PHPMailer $mailer = null;

    /**
     * @var array Last send result metadata
     */
    protected array $lastSendResult = [];

    /**
     * @var bool Whether to use persistent connections
     */
    protected bool $keepAlive = false;

    /**
     * Returns the unique provider name.
     *
     * @return string
     */
    public static function getName(): string
    {
        return 'SMTP Provider';
    }

    /**
     * Returns a short human-readable description.
     *
     * @return string
     */
    public static function getDescription(): string
    {
        return 'Standard SMTP email provider supporting SSL/TLS encryption and authentication';
    }

    /**
     * Returns the provider type.
     *
     * @return string
     */
    public static function getType(): string
    {
        return self::TYPE_EMAIL;
    }

    /**
     * Returns provider capabilities.
     *
     * @return string[]
     */
    public static function getCapabilities(): array
    {
        return [
            'send_single',
            'send_bulk',
            'html_email',
            'attachments',
            'ssl_encryption',
            'tls_encryption',
            'smtp_auth',
            'bounce_handling',
            'delivery_confirmation'
        ];
    }

    /**
     * Returns the full setup schema definition.
     *
     * @return array
     */
    public static function getSetupSchema(): array
    {
        return [
            'provider' => [
                'name' => static::getName(),
                'type' => static::getType(),
                'version' => static::getVersion(),
                'description' => static::getDescription(),
            ],
            'schema' => [
                'smtp_host' => [
                    'type' => 'string',
                    'required' => true,
                    'label' => 'SMTP Host',
                    'description' => 'SMTP server hostname or IP address',
                    'placeholder' => 'smtp.gmail.com',
                ],
                'smtp_port' => [
                    'type' => 'integer',
                    'required' => true,
                    'default' => 587,
                    'label' => 'SMTP Port',
                    'description' => 'SMTP server port (25, 465, 587)',
                    'validation' => ['min' => 1, 'max' => 65535],
                ],
                'smtp_username' => [
                    'type' => 'string',
                    'required' => true,
                    'label' => 'Username',
                    'description' => 'SMTP authentication username',
                ],
                'smtp_password' => [
                    'type' => 'password',
                    'required' => true,
                    'label' => 'Password',
                    'description' => 'SMTP authentication password',
                ],
                'smtp_encryption' => [
                    'type' => 'select',
                    'required' => false,
                    'default' => 'tls',
                    'options' => [
                        'none' => 'No encryption',
                        'ssl' => 'SSL',
                        'tls' => 'TLS',
                    ],
                    'label' => 'Encryption',
                    'description' => 'SMTP encryption method',
                ],
                'from_email' => [
                    'type' => 'email',
                    'required' => true,
                    'label' => 'From Email',
                    'description' => 'Default sender email address',
                ],
                'from_name' => [
                    'type' => 'string',
                    'required' => false,
                    'label' => 'From Name',
                    'description' => 'Default sender name',
                ],
                'reply_to_email' => [
                    'type' => 'email',
                    'required' => false,
                    'label' => 'Reply-To Email',
                    'description' => 'Reply-to email address',
                ],
                'smtp_timeout' => [
                    'type' => 'integer',
                    'required' => false,
                    'default' => 30,
                    'label' => 'Timeout (seconds)',
                    'description' => 'SMTP connection timeout',
                    'validation' => ['min' => 5, 'max' => 300],
                ],
                'smtp_debug' => [
                    'type' => 'boolean',
                    'required' => false,
                    'default' => false,
                    'label' => 'Debug Mode',
                    'description' => 'Enable SMTP debug logging',
                ],
                'keep_alive' => [
                    'type' => 'boolean',
                    'required' => false,
                    'default' => false,
                    'label' => 'Keep Connection Alive',
                    'description' => 'Maintain persistent SMTP connection for bulk sending',
                ],
                'charset' => [
                    'type' => 'string',
                    'required' => false,
                    'default' => 'UTF-8',
                    'label' => 'Character Set',
                    'description' => 'Email character encoding',
                ],
                'word_wrap' => [
                    'type' => 'integer',
                    'required' => false,
                    'default' => 76,
                    'label' => 'Word Wrap',
                    'description' => 'Text line length for word wrapping',
                    'validation' => ['min' => 50, 'max' => 998],
                ],
            ],
            'capabilities' => static::getCapabilities(),
        ];
    }

    /**
     * Sends an email using the SMTP provider.
     *
     * @param array $payload Email payload
     * @return array
     * @throws ProviderException
     */
    public function send(array $payload): array
    {
        if (!$this->isAuthenticated()) {
            throw new ProviderException('SMTP provider is not authenticated', 'email', 'smtp');
        }

        try {
            $this->initializeMailer();
            
            // Set recipients
            $this->setRecipients($payload);
            
            // Set email content
            $this->setEmailContent($payload);
            
            // Set attachments if any
            $this->setAttachments($payload);
            
            // Send the email
            $result = $this->mailer->send();
            
            if ($result) {
                $this->lastSendResult = [
                    'success' => true,
                    'message_id' => $this->generateMessageId(),
                    'recipients' => $this->getRecipientList($payload),
                    'timestamp' => date('Y-m-d H:i:s'),
                ];
                
                return [
                    'success' => true,
                    'message_id' => $this->lastSendResult['message_id'],
                    'meta' => [
                        'provider' => 'smtp',
                        'recipients_count' => count($this->lastSendResult['recipients']),
                        'timestamp' => $this->lastSendResult['timestamp'],
                    ],
                ];
            } else {
                throw new ProviderException('Failed to send email: ' . $this->mailer->ErrorInfo, 'email', 'smtp');
            }
            
        } catch (PHPMailerException $e) {
            throw new ProviderException('PHPMailer error: ' . $e->getMessage(), 'email', 'smtp', 0, [], $e);
        } catch (\Exception $e) {
            throw new ProviderException('Unexpected error: ' . $e->getMessage(), 'email', 'smtp', 0, [], $e);
        } finally {
            if (!$this->keepAlive) {
                $this->closeConnection();
            }
        }
    }

    /**
     * Authenticates the SMTP provider.
     *
     * @param array $config
     * @return bool
     */
    public function authenticate(array $config): bool
    {
        $this->setConfig($config);
        
        // Validate required configuration
        $required = ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'from_email'];
        foreach ($required as $field) {
            if (empty($config[$field])) {
                $this->lastError = "Missing required field: {$field}";
                return false;
            }
        }
        
        // Validate email format
        if (!filter_var($config['from_email'], FILTER_VALIDATE_EMAIL)) {
            $this->lastError = 'Invalid from_email format';
            return false;
        }
        
        if (!empty($config['reply_to_email']) && !filter_var($config['reply_to_email'], FILTER_VALIDATE_EMAIL)) {
            $this->lastError = 'Invalid reply_to_email format';
            return false;
        }
        
        // Validate port range
        $port = (int)$config['smtp_port'];
        if ($port < 1 || $port > 65535) {
            $this->lastError = 'Invalid SMTP port range';
            return false;
        }
        
        $this->authenticated = true;
        $this->keepAlive = ArrayHelper::get($config, 'keep_alive', false);
        $this->lastError = null;
        
        return true;
    }

    /**
     * Tests the SMTP connection.
     *
     * @return bool
     */
    public function testConnection(): bool
    {
        if (!$this->isAuthenticated()) {
            $this->lastError = 'Provider not authenticated';
            return false;
        }
        
        try {
            $this->initializeMailer();
            
            // Test SMTP connection
            if (!$this->mailer->smtpConnect()) {
                $this->lastError = 'Failed to connect to SMTP server: ' . $this->mailer->ErrorInfo;
                return false;
            }
            
            $this->mailer->smtpClose();
            return true;
            
        } catch (PHPMailerException $e) {
            $this->lastError = 'PHPMailer connection test failed: ' . $e->getMessage();
            return false;
        } catch (\Exception $e) {
            $this->lastError = 'Connection test failed: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Initializes the PHPMailer instance.
     *
     * @return void
     * @throws ProviderException
     */
    protected function initializeMailer(): void
    {
        if ($this->mailer === null || !$this->keepAlive) {
            $this->mailer = new PHPMailer(true);
            
            // Server settings
            $this->mailer->isSMTP();
            $this->mailer->Host = $this->config['smtp_host'];
            $this->mailer->Port = (int)$this->config['smtp_port'];
            $this->mailer->Username = $this->config['smtp_username'];
            $this->mailer->Password = $this->config['smtp_password'];
            $this->mailer->SMTPAuth = true;
            
            // Set encryption
            $encryption = ArrayHelper::get($this->config, 'smtp_encryption', 'tls');
            if ($encryption === 'ssl') {
                $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($encryption === 'tls') {
                $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }
            
            // Set timeout
            $this->mailer->Timeout = ArrayHelper::get($this->config, 'smtp_timeout', 30);
            
            // Set debug level
            if (ArrayHelper::get($this->config, 'smtp_debug', false)) {
                $this->mailer->SMTPDebug = SMTP::DEBUG_SERVER;
            }
            
            // Set character set
            $this->mailer->CharSet = ArrayHelper::get($this->config, 'charset', 'UTF-8');
            
            // Set word wrap
            $this->mailer->WordWrap = ArrayHelper::get($this->config, 'word_wrap', 76);
            
            // Set keep alive
            $this->mailer->SMTPKeepAlive = $this->keepAlive;
            
            // Set default from
            $this->mailer->setFrom(
                $this->config['from_email'],
                ArrayHelper::get($this->config, 'from_name', '')
            );
            
            // Set reply-to if configured
            if (!empty($this->config['reply_to_email'])) {
                $this->mailer->addReplyTo($this->config['reply_to_email']);
            }
        }
        
        // Clear previous recipients and attachments
        $this->mailer->clearAllRecipients();
        $this->mailer->clearAttachments();
    }

    /**
     * Sets recipients for the email.
     *
     * @param array $payload
     * @return void
     * @throws ProviderException
     */
    protected function setRecipients(array $payload): void
    {
        // Primary recipient
        if (empty($payload['to'])) {
            throw new ProviderException('No recipient specified', 'email', 'smtp');
        }
        
        if (is_string($payload['to'])) {
            $this->mailer->addAddress($payload['to']);
        } elseif (is_array($payload['to'])) {
            foreach ($payload['to'] as $recipient) {
                if (is_string($recipient)) {
                    $this->mailer->addAddress($recipient);
                } elseif (is_array($recipient) && isset($recipient['email'])) {
                    $this->mailer->addAddress($recipient['email'], $recipient['name'] ?? '');
                }
            }
        }
        
        // CC recipients
        if (!empty($payload['cc'])) {
            $ccRecipients = is_array($payload['cc']) ? $payload['cc'] : [$payload['cc']];
            foreach ($ccRecipients as $cc) {
                if (is_string($cc)) {
                    $this->mailer->addCC($cc);
                } elseif (is_array($cc) && isset($cc['email'])) {
                    $this->mailer->addCC($cc['email'], $cc['name'] ?? '');
                }
            }
        }
        
        // BCC recipients
        if (!empty($payload['bcc'])) {
            $bccRecipients = is_array($payload['bcc']) ? $payload['bcc'] : [$payload['bcc']];
            foreach ($bccRecipients as $bcc) {
                if (is_string($bcc)) {
                    $this->mailer->addBCC($bcc);
                } elseif (is_array($bcc) && isset($bcc['email'])) {
                    $this->mailer->addBCC($bcc['email'], $bcc['name'] ?? '');
                }
            }
        }
    }

    /**
     * Sets email content.
     *
     * @param array $payload
     * @return void
     * @throws ProviderException
     */
    protected function setEmailContent(array $payload): void
    {
        if (empty($payload['subject'])) {
            throw new ProviderException('No subject specified', 'email', 'smtp');
        }
        
        $this->mailer->Subject = $payload['subject'];
        
        // Set body content
        if (!empty($payload['html_body'])) {
            $this->mailer->isHTML(true);
            $this->mailer->Body = $payload['html_body'];
            
            // Set alt body if provided
            if (!empty($payload['text_body'])) {
                $this->mailer->AltBody = $payload['text_body'];
            }
        } elseif (!empty($payload['text_body'])) {
            $this->mailer->isHTML(false);
            $this->mailer->Body = $payload['text_body'];
        } elseif (!empty($payload['body'])) {
            // Generic body field
            $this->mailer->isHTML(false);
            $this->mailer->Body = $payload['body'];
        } else {
            throw new ProviderException('No email body specified', 'email', 'smtp');
        }
        
        // Set custom headers
        if (!empty($payload['headers']) && is_array($payload['headers'])) {
            foreach ($payload['headers'] as $name => $value) {
                $this->mailer->addCustomHeader($name, $value);
            }
        }
    }

    /**
     * Sets email attachments.
     *
     * @param array $payload
     * @return void
     * @throws ProviderException
     */
    protected function setAttachments(array $payload): void
    {
        if (empty($payload['attachments'])) {
            return;
        }
        
        $attachments = is_array($payload['attachments']) ? $payload['attachments'] : [$payload['attachments']];
        
        foreach ($attachments as $attachment) {
            if (is_string($attachment)) {
                // File path
                if (file_exists($attachment)) {
                    $this->mailer->addAttachment($attachment);
                } else {
                    throw new ProviderException("Attachment file not found: {$attachment}", 'email', 'smtp');
                }
            } elseif (is_array($attachment)) {
                // Array with file info
                $path = $attachment['path'] ?? $attachment['file'] ?? '';
                $name = $attachment['name'] ?? basename($path);
                $encoding = $attachment['encoding'] ?? 'base64';
                $type = $attachment['type'] ?? 'application/octet-stream';
                
                if (file_exists($path)) {
                    $this->mailer->addAttachment($path, $name, $encoding, $type);
                } else {
                    throw new ProviderException("Attachment file not found: {$path}", 'email', 'smtp');
                }
            }
        }
    }

    /**
     * Generates a unique message ID.
     *
     * @return string
     */
    protected function generateMessageId(): string
    {
        return 'smtp_' . uniqid() . '_' . time();
    }

    /**
     * Gets the recipient list from payload.
     *
     * @param array $payload
     * @return array
     */
    protected function getRecipientList(array $payload): array
    {
        $recipients = [];
        
        if (!empty($payload['to'])) {
            if (is_string($payload['to'])) {
                $recipients[] = $payload['to'];
            } elseif (is_array($payload['to'])) {
                foreach ($payload['to'] as $recipient) {
                    if (is_string($recipient)) {
                        $recipients[] = $recipient;
                    } elseif (is_array($recipient) && isset($recipient['email'])) {
                        $recipients[] = $recipient['email'];
                    }
                }
            }
        }
        
        return $recipients;
    }

    /**
     * Closes the SMTP connection.
     *
     * @return void
     */
    protected function closeConnection(): void
    {
        if ($this->mailer !== null) {
            $this->mailer->smtpClose();
        }
    }

    /**
     * Gets the last send result.
     *
     * @return array
     */
    public function getLastSendResult(): array
    {
        return $this->lastSendResult;
    }

    /**
     * Returns sanitised configuration (masking sensitive data).
     *
     * @param array|null $fieldsToMask
     * @return array
     */
    public function getSanitisedConfig(array $fieldsToMask = null): array
    {
        $mask = $fieldsToMask ?: ['smtp_password'];
        return parent::getSanitisedConfig($mask);
    }
}
