<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Db;
use Aicountly\Api\Http;

/**
 * The audit trail, read back.
 *
 *   GET v1/audit-log                        the company's trail, filtered and paged   voice.audit.view
 *   GET v1/audit-log/export                 the same filters as CSV, capped            voice.audit.export
 *   GET v1/audit-log/entity/{type}/{id}     one record's history                       voice.audit.view
 *
 * Read-only, and only ever this company's rows: the scope comes from the checked
 * company context (Controller::enter), never from a filter. Nothing here writes
 * to the trail except the export itself, which is recorded — a copy of the trail
 * leaving the product is an event.
 *
 * (GET v1/audit is the older, narrower list and stays for existing callers.)
 */
final class AuditController extends Controller
{
    /** Rows in one CSV. Beyond this the file says it is partial, and the filters narrow it. */
    public const EXPORT_CAP = 5000;

    public static function index(): never
    {
        [, $ctx] = self::enter('voice.audit.view');
        $page = Http::listParams(['occurred_at'], 'occurred_at');
        [$where, $params] = self::filters($ctx);

        $total = (int) (Db::scalar('SELECT COUNT(*) FROM ' . Audit::TABLE . ' WHERE ' . $where, $params) ?? 0);
        $rows = Db::all(
            'SELECT * FROM ' . Audit::TABLE . ' WHERE ' . $where
            . ' ORDER BY occurred_at ' . $page['order'] . ', audit_id ' . $page['order']
            . ' LIMIT ' . $page['limit'] . ' OFFSET ' . $page['offset'],
            $params,
        );

        Http::list(array_map([self::class, 'present'], $rows), $total, $page['limit'], $page['offset']);
    }

    public static function entity(string $type, string $id): never
    {
        [, $ctx] = self::enter('voice.audit.view');
        $page = Http::listParams(['occurred_at'], 'occurred_at');
        [$scope, $params] = $ctx->scopeClause();
        $where = $scope . ' AND entity_type = :etype AND entity_id = :eid';
        $params['etype'] = rawurldecode($type);
        $params['eid'] = rawurldecode($id);

        $total = (int) (Db::scalar('SELECT COUNT(*) FROM ' . Audit::TABLE . ' WHERE ' . $where, $params) ?? 0);
        $rows = Db::all(
            'SELECT * FROM ' . Audit::TABLE . ' WHERE ' . $where
            . ' ORDER BY occurred_at ' . $page['order'] . ', audit_id ' . $page['order']
            . ' LIMIT ' . $page['limit'] . ' OFFSET ' . $page['offset'],
            $params,
        );

        Http::list(array_map([self::class, 'present'], $rows), $total, $page['limit'], $page['offset']);
    }

    public static function export(): never
    {
        [$auth, $ctx] = self::enter('voice.audit.export');
        [$where, $params] = self::filters($ctx);

        $total = (int) (Db::scalar('SELECT COUNT(*) FROM ' . Audit::TABLE . ' WHERE ' . $where, $params) ?? 0);
        $rows = Db::all(
            'SELECT * FROM ' . Audit::TABLE . ' WHERE ' . $where
            . ' ORDER BY occurred_at DESC, audit_id DESC LIMIT ' . self::EXPORT_CAP,
            $params,
        );
        $truncated = $total > count($rows);

        $lines = [[
            'When (UTC)', 'Action', 'Severity', 'Record type', 'Record id', 'Record', 'Summary', 'Changes',
            'Actor', 'Actor name', 'Actor kind', 'Source app', 'Request id', 'Audit id',
        ]];
        foreach ($rows as $row) {
            $p = self::present($row);
            $lines[] = [
                $p['occurred_at'], $p['action'], $p['severity'], $p['entity_type'], $p['entity_id'],
                $p['entity_label'], $p['summary'],
                implode('; ', array_map(
                    static fn (array $c): string => $c['label'] . ': ' . self::flat($c['old']) . ' -> ' . self::flat($c['new']),
                    $p['changes'],
                )),
                $p['actor_uuid'], $p['actor_label'], $p['actor_kind'], $p['actor_app'], $p['request_id'], $p['audit_id'],
            ];
        }
        if ($truncated) {
            $lines[] = ['PARTIAL: the first ' . count($rows) . ' of ' . $total . ' rows. Narrow the dates or filters to export the rest.'];
        }

        Audit::record($ctx, $auth, Audit::EXPORT_TAKEN, 'audit_log', null, [
            'rows' => count($rows), 'total' => $total, 'truncated' => $truncated, 'filters' => self::echoFilters(),
        ], summary: 'Audit trail exported (' . count($rows) . ' of ' . $total . ' rows)');

        $csv = self::csv($lines);
        $file = 'voice-audit-trail-' . gmdate('Y-m-d') . '.csv';

        if (PHP_SAPI === 'cli') {
            // Under test the CSV comes back as data so it can be asserted on.
            Http::data(['csv' => $csv, 'rows' => count($rows), 'total' => $total, 'truncated' => $truncated, 'file_name' => $file]);
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Cache-Control: no-store');
        header('X-Export-Truncated: ' . ($truncated ? 'true' : 'false'));
        header('X-Export-Rows: ' . count($rows) . '/' . $total);
        echo $csv;
        exit;
    }

    /**
     * WHERE clause and bindings for the shared filters. Always this company first.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function filters(\Aicountly\Api\Context $ctx): array
    {
        [$scope, $params] = $ctx->scopeClause();
        $where = [$scope];

        $from = self::dateParam('from');
        $to = self::dateParam('to');
        if ($from !== null && $to !== null && $from > $to) {
            Http::validationFailed('The period starts after it ends.', ['from' => 'Start on or before the end date.']);
        }
        if ($from !== null) {
            $where[] = 'occurred_at >= CAST(:from_ts AS TIMESTAMPTZ)';
            $params['from_ts'] = $from . ' 00:00:00+00';
        }
        if ($to !== null) {
            // Inclusive: the whole of the "to" day.
            $where[] = 'occurred_at < CAST(:to_ts AS TIMESTAMPTZ) + INTERVAL \'1 day\'';
            $params['to_ts'] = $to . ' 00:00:00+00';
        }

        $action = Http::param('action');
        if ($action !== null && $action !== '') {
            if (str_ends_with($action, '.*')) {
                $where[] = 'action LIKE :action_prefix';
                $params['action_prefix'] = self::like(substr($action, 0, -1)) . '%';
            } else {
                $where[] = 'action = :action';
                $params['action'] = $action;
            }
        }
        foreach (['entity_type', 'entity_id', 'actor_uuid', 'severity'] as $column) {
            $value = Http::param($column);
            if ($value !== null && $value !== '') {
                $where[] = $column . ' = :' . $column;
                $params[$column] = $value;
            }
        }
        $label = Http::param('entity_label');
        if ($label !== null && $label !== '') {
            $where[] = 'entity_label ILIKE :entity_label';
            $params['entity_label'] = '%' . self::like($label) . '%';
        }
        $q = Http::param('q');
        if ($q !== null && $q !== '') {
            $where[] = '(summary ILIKE :q OR entity_label ILIKE :q OR action ILIKE :q)';
            $params['q'] = '%' . self::like($q) . '%';
        }

        return [implode(' AND ', $where), $params];
    }

    private static function dateParam(string $name): ?string
    {
        $value = Http::param($name);
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || strtotime($value) === false) {
            Http::validationFailed('That is not a date.', [$name => 'Use YYYY-MM-DD.']);
        }

        return $value;
    }

    /** Escape LIKE wildcards in what a person typed. */
    private static function like(string $value): string
    {
        return addcslashes($value, '\\%_');
    }

    /** @return array<string, string> */
    private static function echoFilters(): array
    {
        $out = [];
        foreach (['from', 'to', 'action', 'entity_type', 'entity_id', 'entity_label', 'actor_uuid', 'severity', 'q'] as $name) {
            $value = Http::param($name);
            if ($value !== null && $value !== '') {
                $out[$name] = mb_substr($value, 0, 120);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function present(array $r): array
    {
        return [
            'audit_id'     => (int) $r['audit_id'],
            'occurred_at'  => $r['occurred_at'],
            'actor_uuid'   => $r['actor_uuid'],
            'actor_label'  => $r['actor_label'] ?? null,
            'actor_kind'   => (string) $r['actor_kind'],
            'actor_app'    => $r['actor_app'],
            'action'       => (string) $r['action'],
            'severity'     => (string) ($r['severity'] ?? 'info'),
            'entity_type'  => $r['entity_type'],
            'entity_id'    => $r['entity_id'],
            'entity_label' => $r['entity_label'] ?? null,
            'summary'      => $r['summary'] ?? null,
            'changes'      => array_values(Db::jsonColumn($r['changes'] ?? null)),
            'before'       => Db::jsonColumn($r['before_state'] ?? null),
            'after'        => Db::jsonColumn($r['after_state'] ?? null),
            'detail'       => Db::jsonColumn($r['detail'] ?? null),
            'request_id'   => $r['request_id'] ?? $r['correlation_id'] ?? null,
            'user_agent'   => $r['user_agent'] ?? null,
        ];
    }

    private static function flat(mixed $value): string
    {
        if ($value === null) {
            return '(empty)';
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        return is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /** @param list<list<mixed>> $lines */
    private static function csv(array $lines): string
    {
        $out = "\xEF\xBB\xBF";
        foreach ($lines as $line) {
            $out .= implode(',', array_map([self::class, 'cell'], $line)) . "\r\n";
        }

        return $out;
    }

    /** One cell, quoted, and safe to open in a spreadsheet: a leading = + - @ (or tab/CR) is neutralised. */
    private static function cell(mixed $value): string
    {
        $text = $value === null ? '' : (is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE));
        if ($text !== '' && strpbrk($text[0], "=+-@\t\r") !== false) {
            $text = "'" . $text;
        }

        return '"' . str_replace('"', '""', $text) . '"';
    }
}
