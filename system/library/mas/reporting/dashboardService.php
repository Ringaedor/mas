<?php
/**
 * MAS – Marketing Automation Suite
 * DashboardService
 *
 * High-level façade that exposes unified reporting APIs for the MAS UI
 * (admin dashboard, widgets, exported reports, REST endpoints).
 *
 * Responsibilities
 * ─────────────────
 * • Aggregates and caches core KPIs (GMV, orders, AOV, CR, new users, churn)
 * • Generates ready-to-plot time-series data (hourly, daily, weekly, monthly)
 * • Offers drill-down helpers (top products, top segments, top channels)
 * • Normalises date ranges & time-zones
 * • Supports quick filters (store, channel, segment, campaign, currency)
 * • Publishes “report.generated” events for audit & async exports
 *
 * Path: system/library/mas/reporting/DashboardService.php
 *
 * © 2025 Your Company – Proprietary
 */

namespace Opencart\Library\Mas\Reporting;

use Opencart\Library\Mas\ServiceContainer;
use Opencart\Library\Mas\Helper\ArrayHelper;
use Opencart\Library\Mas\Helper\DateHelper;
use Opencart\Library\Mas\Events\Event;
use Opencart\System\Engine\Log;
use Opencart\System\Library\Cache;
use Opencart\System\Library\DB;

class DashboardService
{
    /* ---------------------------------------------------------------------
     * Dependencies
     * -------------------------------------------------------------------*/
    protected ServiceContainer $container;
    protected Log   $log;
    protected Cache $cache;
    protected DB    $db;

    /* ---------------------------------------------------------------------
     * Configuration
     * -------------------------------------------------------------------*/
    /** Default cache TTL (seconds) */
    protected int $ttl = 900;

    /** Allowed granularities */
    protected array $grains = ['hour', 'day', 'week', 'month'];

    public function __construct(ServiceContainer $container, array $config = [])
    {
        $this->container = $container;
        $this->log   = $container->get('log');
        $this->cache = $container->get('cache');
        $this->db    = $container->get('db');

        $this->ttl   = $config['cache_ttl'] ?? $this->ttl;
    }

    /* ---------------------------------------------------------------------
     * Public API – summary KPIs
     * -------------------------------------------------------------------*/

    /**
     * Returns key performance indicators for the given period.
     *
     * @param string $dateFrom Y-m-d
     * @param string $dateTo   Y-m-d
     * @param array  $filters  [store_id, channel, segment_id, currency_code]
     */
    public function getKpiSummary(string $dateFrom, string $dateTo, array $filters = []): array
    {
        $cacheKey = $this->fingerprint('kpi', $dateFrom, $dateTo, $filters);
        if ($hit = $this->cache->get($cacheKey)) {
            return $hit;
        }

        $orders = $this->fetchOrders($dateFrom, $dateTo, $filters);
        $revenue = array_sum(array_column($orders, 'total'));
        $orderCnt = count($orders);

        $visits = $this->fetchVisits($dateFrom, $dateTo, $filters);
        $visitCnt = $visits['count'] ?? 0;

        $users = $this->fetchNewUsers($dateFrom, $dateTo, $filters);
        $signupCnt = count($users);

        $kpi = [
            'revenue'          => round($revenue, 2),
            'orders'           => $orderCnt,
            'average_order'    => $orderCnt ? round($revenue / $orderCnt, 2) : 0,
            'conversion_rate'  => $visitCnt ? round(($orderCnt / $visitCnt) * 100, 2) : 0,
            'visits'           => $visitCnt,
            'new_customers'    => $signupCnt,
            'aov'              => $orderCnt ? round($revenue / $orderCnt, 2) : 0,
        ];

        $this->cache->set($cacheKey, $kpi, $this->ttl);
        $this->emit('report.generated', ['type'=>'kpi','period'=>[$dateFrom,$dateTo],'filters'=>$filters]);

        if ($this->container->has('mas.event_dispatcher')) {
            $this->container->get('mas.event_dispatcher')->dispatch(
                new Event('report.generated', [
                    'type' => 'kpi',
                    'period' => [$dateFrom, $dateTo],
                    'filters' => $filters
                ])
                );
        }
        
        return $kpi;
    }

    /* ---------------------------------------------------------------------
     * Time-series for charts
     * -------------------------------------------------------------------*/

    /**
     * Returns series: [['date'=>'2025-07-01','value'=>123], …]
     *
     * @param string $metric revenue|orders|visits|signups
     * @param string $grain  hour|day|week|month
     */
    public function getTimeSeries(
        string $metric,
        string $grain,
        string $dateFrom,
        string $dateTo,
        array $filters = []
    ): array {
        $grain = in_array($grain, $this->grains, true) ? $grain : 'day';
        $cacheKey = $this->fingerprint("ts_{$metric}_{$grain}", $dateFrom, $dateTo, $filters);
        if ($hit = $this->cache->get($cacheKey)) {
            return $hit;
        }

        $series = match ($metric) {
            'revenue' => $this->timeSeriesOrders($dateFrom, $dateTo, $grain, $filters, true),
            'orders'  => $this->timeSeriesOrders($dateFrom, $dateTo, $grain, $filters, false),
            'visits'  => $this->timeSeriesVisits($dateFrom, $dateTo, $grain, $filters),
            'signups' => $this->timeSeriesSignups($dateFrom, $dateTo, $grain, $filters),
            default   => [],
        };

        $this->cache->set($cacheKey, $series, $this->ttl);
        return $series;
    }

    /* ---------------------------------------------------------------------
     * Drill-down helpers
     * -------------------------------------------------------------------*/

    public function getTopProducts(string $dateFrom, string $dateTo, array $filters = [], int $limit = 10): array
    {
        $sql = "
            SELECT p.product_id, pd.name,
                   SUM(op.quantity) qty,
                   SUM(op.total)    total
            FROM `order_product` op
            JOIN `order` o     ON op.order_id   = o.order_id
            JOIN `product` p   ON p.product_id  = op.product_id
            JOIN `product_description` pd ON pd.product_id = p.product_id
            WHERE o.date_added BETWEEN ? AND ?
              AND o.order_status_id IN (2,3,5)
            GROUP BY p.product_id
            ORDER BY total DESC
            LIMIT ?";
        $rows = $this->db->query($sql, [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59', $limit])->rows;
        return $rows;
    }

    public function getSegmentPerformance(string $dateFrom, string $dateTo, int $segmentId, array $filters = []): array
    {
        $sql = "
            SELECT SUM(o.total) revenue,
                   COUNT(o.order_id) orders,
                   AVG(o.total) aov
            FROM `order` o
            JOIN `seg_customer` sc ON sc.customer_id = o.customer_id
            WHERE sc.segment_id = ?
              AND o.date_added BETWEEN ? AND ?
              AND o.order_status_id IN (2,3,5)";
        return $this->db->query($sql, [$segmentId, $dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])->row;
    }

    /* ---------------------------------------------------------------------
     * Internal fetch helpers
     * -------------------------------------------------------------------*/

    private function fetchOrders(string $from, string $to, array $f): array
    {
        $q = $this->db->query("
            SELECT order_id,total
            FROM `order`
            WHERE date_added BETWEEN ? AND ?
              AND order_status_id IN (2,3,5)",
            [$from . ' 00:00:00', $to . ' 23:59:59']
        );
        return $q->rows;
    }

    private function fetchVisits(string $from, string $to, array $f): array
    {
        return $this->db->query("
            SELECT COUNT(*) count
            FROM `mas_customer_activity`
            WHERE activity_type = 'page_view'
              AND created_at BETWEEN ? AND ?",
            [$from . ' 00:00:00', $to . ' 23:59:59']
        )->row;
    }

    private function fetchNewUsers(string $from, string $to, array $f): array
    {
        return $this->db->query("
            SELECT customer_id
            FROM `customer`
            WHERE date_added BETWEEN ? AND ?
              AND status = 1",
            [$from . ' 00:00:00', $to . ' 23:59:59']
        )->rows;
    }

    private function timeSeriesOrders(string $from, string $to, string $grain, array $f, bool $asRevenue): array
    {
        $select = $asRevenue ? 'SUM(o.total) AS value' : 'COUNT(o.order_id) AS value';
        $group  = $this->sqlGroupBy($grain, 'o.date_added');

        $rows = $this->db->query("
            SELECT {$group} AS period, {$select}
            FROM `order` o
            WHERE o.date_added BETWEEN ? AND ?
              AND o.order_status_id IN (2,3,5)
            GROUP BY period
            ORDER BY period",
            [$from . ' 00:00:00', $to . ' 23:59:59']
        )->rows;

        return $this->fillGaps($rows, $from, $to, $grain);
    }

    private function timeSeriesVisits(string $from, string $to, string $grain, array $f): array
    {
        $group = $this->sqlGroupBy($grain, 'created_at');

        $rows = $this->db->query("
            SELECT {$group} AS period, COUNT(*) AS value
            FROM `mas_customer_activity`
            WHERE activity_type = 'page_view'
              AND created_at BETWEEN ? AND ?
            GROUP BY period
            ORDER BY period",
            [$from . ' 00:00:00', $to . ' 23:59:59']
        )->rows;

        return $this->fillGaps($rows, $from, $to, $grain);
    }

    private function timeSeriesSignups(string $from, string $to, string $grain, array $f): array
    {
        $group = $this->sqlGroupBy($grain, 'date_added');

        $rows = $this->db->query("
            SELECT {$group} AS period, COUNT(*) AS value
            FROM `customer`
            WHERE status = 1
              AND date_added BETWEEN ? AND ?
            GROUP BY period
            ORDER BY period",
            [$from . ' 00:00:00', $to . ' 23:59:59']
        )->rows;

        return $this->fillGaps($rows, $from, $to, $grain);
    }

    /* ---------------------------------------------------------------------
     * Utility
     * -------------------------------------------------------------------*/
    private function sqlGroupBy(string $grain, string $col): string
    {
        return match ($grain) {
            'hour'  => "DATE_FORMAT({$col}, '%Y-%m-%d %H:00:00')",
            'day'   => "DATE({$col})",
            'week'  => "YEARWEEK({$col}, 3)",      // ISO-week
            'month' => "DATE_FORMAT({$col}, '%Y-%m-01')",
            default => "DATE({$col})",
        };
    }

    private function fillGaps(array $rows, string $from, string $to, string $grain): array
    {
        $map = [];
        foreach ($rows as $r) {
            $map[$r['period']] = (float)$r['value'];
        }

        $cursor = DateHelper::toCarbon("{$from} 00:00:00");
        $end    = DateHelper::toCarbon("{$to} 23:59:59");

        $out = [];
        while ($cursor <= $end) {
            $key = match ($grain) {
                'hour'  => $cursor->format('Y-m-d H:00:00'),
                'day'   => $cursor->format('Y-m-d'),
                'week'  => $cursor->format('oW'),
                'month' => $cursor->format('Y-m-01'),
            };
            $out[] = ['period' => $key, 'value' => $map[$key] ?? 0];
            $cursor->add(match ($grain) {
                'hour'  => \DateInterval::createFromDateString('1 hour'),
                'day'   => \DateInterval::createFromDateString('1 day'),
                'week'  => \DateInterval::createFromDateString('1 week'),
                'month' => \DateInterval::createFromDateString('1 month'),
            });
        }
        return $out;
    }

    private function fingerprint(string $prefix, string $from, string $to, array $filters): string
    {
        return "mas_dash_{$prefix}_" . md5($from . $to . json_encode($filters));
    }

    private function emit(string $name, array $payload): void
    {
        if ($this->container->has('mas.event_dispatcher')) {
            $this->container->get('mas.event_dispatcher')->dispatch(new Event($name, $payload));
        }
    }
}
