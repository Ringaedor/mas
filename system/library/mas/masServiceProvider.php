<?php
/**
 * MAS Service Provider
 * Registra tutti i servizi MAS dentro al ServiceContainer e al Registry di OpenCart.
 * Percorso: system/library/mas/masServiceProvider.php
 */

namespace Opencart\Library\Mas;

use Opencart\System\Engine\Registry;
use Opencart\Library\Mas\Services\Ai\AiGateway;
use Opencart\Library\Mas\Services\Message\MessageGateway;
use Opencart\Library\Mas\Services\Payment\PaymentGateway;
use Opencart\Library\Mas\Events\EventDispatcher;
use Opencart\Library\Mas\Events\Queue;
use Opencart\Library\Mas\Audit\AuditLogger;
use Opencart\Library\Mas\Reporting\DashboardService;
use Opencart\Library\Mas\Reporting\CsvExporter;

class MasServiceProvider
{
    public static function register(Registry $registry): void
    {
        // Carica la configurazione dal file MAS
        $masConfigFile = DIR_SYSTEM . 'library/mas/config.php';
        $masConfig = file_exists($masConfigFile) ? include $masConfigFile : [];
        
        // Merge con eventuali override dal config globale di OpenCart
        $globalConfig = $registry->get('config')->get('mas_config') ?? [];
        $cfg = array_merge($masConfig, $globalConfig);
        
        $container = ServiceContainer::getInstance();

        /* Event Dispatcher */
        $dispatcher = new EventDispatcher($container);
        $container->set('mas.event_dispatcher', $dispatcher);
        $registry->set('mas_event_dispatcher', $dispatcher);

        /* Event Queue */
        $queue = new Queue($container, $config['event_queue'] ?? []);
        $container->set('mas.event_queue', $queue);

        /* Gateways */
        $aiGateway = new AiGateway($container, $config['ai_gateway'] ?? []);
        $container->set('mas.ai_gateway', $aiGateway);
        $registry->set('mas_ai_gateway', $aiGateway);

        $msgGateway = new MessageGateway($container, $config['message_gateway'] ?? []);
        $container->set('mas.message_gateway', $msgGateway);
        $registry->set('mas_message_gateway', $msgGateway);

        $payGateway = new PaymentGateway($container, $config['payment_gateway'] ?? []);
        $container->set('mas.payment_gateway', $payGateway);
        $registry->set('mas_payment_gateway', $payGateway);

        /* Audit */
        $audit = new AuditLogger($container, $config['audit_logger'] ?? []);
        $container->set('mas.audit_logger', $audit);
        $registry->set('mas_audit_logger', $audit);

        /* Reporting */
        $dash = new DashboardService($container, $config['dashboard_service'] ?? []);
        $container->set('mas.dashboard_service', $dash);

        $csv = new CsvExporter($container, $config['csv_exporter'] ?? []);
        $container->set('mas.csv_exporter', $csv);
    }
}
