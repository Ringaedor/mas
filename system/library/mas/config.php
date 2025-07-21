<?php
/**
 * MAS - Marketing Automation Suite
 * Complete Configuration File
 * Path: system/library/mas/config.php
 */
return [
    
    /* ========================= GATEWAYS ========================= */
    'ai_gateway' => [
        'default_provider'         => 'openai',
        'enable_cache'             => true,
        'cache_ttl'                => 3600,
        'max_retries'              => 3,
        'retry_backoff_multiplier' => 2.0,
        'circuit_breaker_threshold'=> 5,
        'circuit_breaker_timeout'  => 300,
        'ai_providers_path'        => DIR_SYSTEM . 'library/mas/ai/',
        'fallback_order' => [
            'chat'       => ['openai', 'anthropic', 'gemini', 'local_ml'],
            'completion' => ['openai', 'anthropic', 'gemini', 'local_ml'],
            'embedding'  => ['openai', 'gemini', 'local_ml'],
            'image'      => ['openai', 'stable_diffusion', 'midjourney'],
            'analysis'   => ['openai', 'anthropic', 'local_ml'],
            'prediction' => ['local_ml', 'openai', 'anthropic'],
            'clustering' => ['local_ml', 'openai']
        ]
    ],
    
    'message_gateway' => [
        'default_provider'         => 'sendgrid',
        'enable_cache'             => true,
        'cache_ttl'                => 1800,
        'max_retries'              => 3,
        'retry_backoff_multiplier' => 2.0,
        'circuit_breaker_threshold'=> 5,
        'circuit_breaker_timeout'  => 300,
        'default_batch_size'       => 100,
        'fallback_order' => [
            'email'    => ['sendgrid', 'mailgun', 'smtp', 'mailhog'],
            'sms'      => ['twilio', 'nexmo', 'messagebird'],
            'push'     => ['onesignal', 'pusher', 'firebase'],
            'whatsapp' => ['twilio', 'whatsapp_business'],
            'slack'    => ['slack', 'webhook'],
            'webhook'  => ['http', 'guzzle']
        ]
    ],
    
    'payment_gateway' => [
        'default_provider'         => 'stripe',
        'max_retries'              => 2,
        'backoff_multiplier'       => 2.0,
        'circuit_threshold'        => 3,
        'circuit_timeout'          => 300,
        'rate_limit_window'        => 60,
        'rate_limit_max'           => 100,
        'fallback_order' => [
            'authorize'           => ['stripe', 'paypal', 'authorizenet'],
            'capture'             => ['stripe', 'paypal'],
            'refund'              => ['stripe', 'paypal'],
            'void'                => ['stripe', 'authorizenet'],
            'subscribe'           => ['stripe', 'paypal'],
            'cancel_subscription' => ['stripe', 'paypal']
        ]
    ],
    
    /* ========================= EVENTS ========================= */
    'event_dispatcher' => [
        'enabled' => true
    ],
    
    'event_queue' => [
        'batch_size'      => 100,
        'max_attempts'    => 5,
        'initial_backoff' => 30
    ],
    
    /* ========================= AUDIT & COMPLIANCE ========================= */
    'audit_logger' => [
        'enabled'        => true,
        'retention_days' => 2555,
        'batch_size'     => 1000,
        'alert_events' => [
            'security.login_failed_multiple',
            'security.unauthorized_access',
            'security.privilege_escalation',
            'data.mass_deletion',
            'system.critical_error',
            'compliance.gdpr_violation',
            'payment.fraud_detected'
        ]
    ],
    
    'consent_manager' => [
        'cache_ttl' => 3600,
        'default_language' => 'en-gb'
    ],
    
    /* ========================= REPORTING ========================= */
    'dashboard_service' => [
        'cache_ttl' => 900,
        'supported_grains' => ['hour', 'day', 'week', 'month']
    ],
    
    'csv_exporter' => [
        'delimiter'   => ',',
        'enclosure'   => '"',
        'escape'      => '"',
        'add_bom'     => true,
        'batch_size'  => 1000
    ],
    
    /* ========================= SEGMENTATION ========================= */
    'segment_manager' => [
        'cache_ttl'        => 3600,
        'batch_size'       => 1000,
        'auto_refresh'     => true,
        'refresh_interval' => 24,
        'max_segment_size' => 100000
    ],
    
    'segment_suggestor' => [
        'cache_ttl'        => 3600,
        'ai_providers_path'=> DIR_SYSTEM . 'library/mas/ai/',
        'supported_types' => [
            'auto_discover',
            'rfm_optimization',
            'behavioral_patterns',
            'conversion_prediction',
            'churn_prediction',
            'engagement_optimization',
            'demographic_insights',
            'seasonal_patterns',
            'product_affinity',
            'cross_sell_opportunities',
            'retention_strategies',
            'lifecycle_stages'
        ]
    ],
    
    /* ========================= AI SUGGESTORS ========================= */
    'openai_suggester' => [
        'model'                => 'gpt-4',
        'temperature'          => 0.3,
        'max_tokens'           => 2000,
        'system_prompt'        => 'You are a marketing automation assistant. Analyze customer data and suggest segments.',
        'user_prompt_template' => 'Goal: {{goal}}\nCustomer Data Sample: {{data_sample}}\n\nProvide JSON:\n[\n  {\n    "id": "string",\n    "name": "string",\n    "description": "string",\n    "characteristics": ["string"],\n    "actions": ["string"]\n  }\n]'
    ],
    
    /* ========================= WORKFLOW ENGINE ========================= */
    'workflow_engine' => [
        'max_execution_time' => 300,
        'batch_size'         => 100,
        'retry_attempts'     => 3,
        'enable_logging'     => true,
        'queue_enabled'      => true
    ],
    
    'workflow_manager' => [
        'cache_ttl'          => 3600,
        'auto_save_interval' => 30,
        'max_workflow_size'  => 50,
        'enable_versioning'  => true
    ],
    
    /* ========================= PROVIDERS ========================= */
    'provider_manager' => [
        'cache_ttl'         => 3600,
        'auto_discovery'    => true,
        'discovery_paths' => [
            'ai'      => DIR_SYSTEM . 'library/mas/ai/',
            'message' => DIR_SYSTEM . 'library/mas/message/',
            'payment' => DIR_SYSTEM . 'library/mas/payment/'
        ]
    ],
    
    /* ========================= CAMPAIGN MANAGER ========================= */
    'campaign_manager' => [
        'cache_ttl'        => 1800,
        'batch_size'       => 500,
        'max_recipients'   => 10000,
        'throttle_rate'    => 100,
        'enable_tracking'  => true
    ],
    
    /* ========================= TEMPLATE ENGINE ========================= */
    'template_engine' => [
        'cache_ttl'       => 7200,
        'compile_check'   => true,
        'auto_escape'     => true,
        'template_paths' => [
            DIR_SYSTEM . 'library/mas/templates/',
            DIR_EXTENSION . 'mas/catalog/view/template/'
        ]
    ],
    
    /* ========================= ANALYTICS ========================= */
    'analytics_manager' => [
        'cache_ttl'           => 1800,
        'retention_days'      => 365,
        'batch_size'          => 1000,
        'enable_real_time'    => true,
        'aggregation_interval'=> 'hourly'
    ],
    
    /* ========================= SECURITY & PERFORMANCE ========================= */
    'security' => [
        'enable_ip_whitelist' => false,
        'allowed_ips'         => [],
        'rate_limit_enabled'  => true,
        'max_requests_per_hour' => 3600,
        'enable_csrf_protection' => true,
        'session_timeout'     => 7200
    ],
    
    'performance' => [
        'enable_query_cache'  => true,
        'query_cache_ttl'     => 300,
        'enable_compression'  => true,
        'memory_limit'        => '512M',
        'execution_time_limit'=> 300
    ],
    
    /* ========================= INTEGRATION ========================= */
    'webhooks' => [
        'enabled'         => true,
        'timeout'         => 30,
        'retry_attempts'  => 3,
        'verify_ssl'      => true,
        'max_payload_size'=> 1048576 // 1MB
    ],
    
    'api' => [
        'rate_limit_enabled' => true,
        'requests_per_minute'=> 100,
        'enable_cors'        => false,
        'allowed_origins'    => ['*'],
        'enable_auth'        => true,
        'token_lifetime'     => 3600
    ],
    
    /* ========================= DEBUGGING & DEVELOPMENT ========================= */
    'debug' => [
        'enabled'           => false,
        'log_level'         => 'info',
        'log_queries'       => false,
        'log_performance'   => false,
        'enable_profiler'   => false
    ]
];
