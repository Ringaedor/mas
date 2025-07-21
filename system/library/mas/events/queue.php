<?php
/**
 * MAS – Marketing Automation Suite
 * EventQueue
 *
 * Lightweight FIFO queue for MAS events.
 * • Persists queued events in `mas_event_queue`
 * • Supports delayed / scheduled dispatch (cron-friendly)
 * • Handles exponential back-off on retries
 * • Plays nicely with EventDispatcher for synchronous delivery
 *
 * 2025 © Your Company – Proprietary
 *
 * File path: system/library/mas/events/Queue.php
 */

namespace Opencart\Library\Mas\Events;

use Opencart\Library\Mas\ServiceContainer;
use Opencart\Library\Mas\Helper\DateHelper;
use Opencart\Library\Mas\Exception\EventException;
use Opencart\System\Engine\Log;
use Opencart\System\Library\DB;

class Queue
{
    /** @var ServiceContainer */
    protected ServiceContainer $container;

    /** @var Log */
    protected Log $log;

    /** @var DB */
    protected DB $db;

    /** @var EventDispatcher */
    protected EventDispatcher $dispatcher;

    /** @var int default batch size for processing */
    protected int $batchSize = 100;

    /** @var int max attempts before dead-letter */
    protected int $maxAttempts = 5;

    /** @var int initial back-off (seconds) */
    protected int $initialBackoff = 30;

    public function __construct(ServiceContainer $container, array $config = [])
    {
        $this->container  = $container;
        $this->log        = $container->get('log');
        $this->db         = $container->get('db');
        $this->dispatcher = $container->get('mas.event_dispatcher');

        $this->batchSize      = $config['batch_size']      ?? $this->batchSize;
        $this->maxAttempts    = $config['max_attempts']    ?? $this->maxAttempts;
        $this->initialBackoff = $config['initial_backoff'] ?? $this->initialBackoff;
    }

    /* ------------------------------------------------------------------------
     *  Enqueue / dequeue
     * --------------------------------------------------------------------- */

    /**
     * Adds an event to the queue.
     *
     * @param Event  $event
     * @param string $schedule ISO-8601 date or “now”
     */
    public function push(Event $event, string $schedule = 'now'): void
    {
        $this->db->query("
            INSERT INTO `mas_event_queue` SET
                `event_name`   = '" . $this->db->escape($event->getName()) . "',
                `payload`      = '" . $this->db->escape(json_encode($event->getPayload())) . "',
                `status`       = 'pending',
                `attempts`     = 0,
                `scheduled_at` = '" . $this->db->escape($schedule === 'now' ? DateHelper::now() : $schedule) . "',
                `created_at`   = NOW()
        ");
    }

    /**
     * Processes the queue.
     *
     * Typical usage from cron:
     * $queue->process(); // default batch size
     */
    public function process(int $batchSize = null): array
    {
        $batchSize = $batchSize ?? $this->batchSize;

        $rows = $this->db->query("
            SELECT *
            FROM `mas_event_queue`
            WHERE `status` = 'pending'
              AND `scheduled_at` <= NOW()
            ORDER BY `priority` DESC, `scheduled_at` ASC
            LIMIT {$batchSize}
        ")->rows;

        $processed = 0;
        $failed    = 0;

        foreach ($rows as $row) {
            $event = new Event($row['event_name'], json_decode($row['payload'], true));

            try {
                if ($this->container->has('mas.event_dispatcher')) {
                    $this->container->get('mas.event_dispatcher')->dispatch($event);
                }

                $this->ack($row['id'], 'processed');
                $processed++;
            } catch (\Throwable $e) {
                $failed++;
                $this->handleFailure($row, $e);
            }
        }

        return [
            'processed' => $processed,
            'failed'    => $failed,
            'batch'     => count($rows),
        ];
    }

    /* ------------------------------------------------------------------------
     *  Helpers
     * --------------------------------------------------------------------- */

    /**
     * Acknowledge queue entry.
     */
    protected function ack(int $id, string $status): void
    {
        $this->db->query("
            UPDATE `mas_event_queue`
            SET `status` = '" . $this->db->escape($status) . "',
                `processed_at` = NOW()
            WHERE `id` = {$id}
        ");
    }

    /**
     * Handle failed dispatch with incremental back-off.
     */
    protected function handleFailure(array $row, \Throwable $e): void
    {
        $attempts = (int)$row['attempts'] + 1;

        if ($attempts >= $this->maxAttempts) {
            // dead-letter
            $this->db->query("
                UPDATE `mas_event_queue`
                SET `status` = 'failed',
                    `attempts` = {$attempts},
                    `error` = '" . $this->db->escape($e->getMessage()) . "',
                    `processed_at` = NOW()
                WHERE `id` = {$row['id']}
            ");
            $this->log->write("MAS EventQueue: dead-letter {$row['event_name']} ({$row['id']}) – {$e->getMessage()}");
            return;
        }

        // back-off
        $delay = $this->initialBackoff * pow(2, $attempts - 1);

        $this->db->query("
            UPDATE `mas_event_queue`
            SET `attempts` = {$attempts},
                `scheduled_at` = DATE_ADD(NOW(), INTERVAL {$delay} SECOND),
                `error` = '" . $this->db->escape($e->getMessage()) . "'
            WHERE `id` = {$row['id']}
        ");

        $this->log->write("MAS EventQueue: retry {$row['event_name']} ({$row['id']}) in {$delay}s – attempt {$attempts}");
    }

    /* ------------------------------------------------------------------------
     *  Monitoring
     * --------------------------------------------------------------------- */

    public function stats(): array
    {
        $totals = $this->db->query("
            SELECT `status`, COUNT(*) as cnt
            FROM `mas_event_queue`
            GROUP BY `status`
        ")->rows;

        $out = ['pending'=>0,'processed'=>0,'failed'=>0];
        foreach ($totals as $row) {
            $out[$row['status']] = (int)$row['cnt'];
        }
        return $out;
    }

    /**
     * Purge old processed / failed entries.
     *
     * @param int $days  older than N days
     */
    public function purge(int $days = 7): int
    {
        $this->db->query("
            DELETE FROM `mas_event_queue`
            WHERE `status` IN ('processed','failed')
              AND `processed_at` < DATE_SUB(NOW(), INTERVAL {$days} DAY)
        ");
        return $this->db->countAffected();
    }
}
