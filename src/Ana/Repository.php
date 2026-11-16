<?php

namespace GlpiPlugin\G4freportsana\Ana;

use GlpiPlugin\G4freportsana\Config;
use GlpiPlugin\G4freportsana\Database\ConnectionConfig;
use GlpiPlugin\G4freportsana\Database\ConnectionException;
use GlpiPlugin\G4freportsana\Database\MysqlClient;

/** GLPI 11/12 ANA source adapter. SELECT only; no use of the host GLPI database. */
final class Repository
{
    private const MAX_TICKETS = 20000;
    private const MAX_ASSOCIATIONS = 100000;
    private const BATCH_SIZE = 250;

    public static function extract(array $input): array
    {
        $period = self::period($input);
        $config = Config::merged();
        $profile = ConnectionConfig::fromConfig('glpi', $config);
        if (empty($profile['enabled'])) {
            throw new \RuntimeException('Habilite a conexão de leitura GLPI do relatório ANA.');
        }
        $client = new MysqlClient($profile);
        $warnings = [];
        $started = gmdate('c');
        $catalog = SelectionCatalog::resolve($client, $input, $warnings);
        $tickets = self::tickets($client, $input, $period);
        $tickets = ActorEvidence::load($client, $tickets, $input, $warnings);
        $context = SlaDataSource::load($client, $tickets, $input, $warnings);
        $calls = [];
        $callsAvailable = false;
        $phone = ['available' => false, 'reason' => 'disabled'];
        try {
            $callProfile = ConnectionConfig::fromConfig('callcenter', $config);
            if (!empty($callProfile['enabled'])) {
                $calls = PhoneData::load(new MysqlClient($callProfile), $input, $period, $warnings);
                $callsAvailable = true;
                $phone = ['available' => true, 'leg_count' => count($calls), 'in_period_leg_count' => count(array_filter($calls, static fn($c) => !empty($c['in_period']))), 'queues' => PhoneData::queues($input),
                    'datetime_timezone' => $input['callcenter_timezone'] ?? $period['timezone'], 'time_type' => $calls[0]['source_time_type'] ?? null];
            } else {
                $warnings[] = 'Conexão de telefonia desabilitada.';
            }
        } catch (ConnectionException $e) {
            // Telephone failure never converts an unavailable source into zero calls or 100%.
            $phone['reason'] = $e->getReason();
            $phone['message'] = $e->getMessage();
            $warnings[] = 'Dados de telefonia indisponíveis nesta extração.';
        } catch (\Exception $e) {
            // Configuration/secret errors are also confined to this optional source.
            $phone['reason'] = 'configuration_unavailable';
            $warnings[] = 'Configuração da conexão de telefonia inválida.';
        }
        unset($config, $profile, $callProfile);
        return [
            'tickets' => $tickets, 'calls' => $calls, 'calls_available' => $callsAvailable,
            'warnings' => array_values(array_unique($warnings)),
            'capabilities' => ['glpi11' => true, 'satisfaction' => true, 'reopening' => true,
                'phone' => $callsAvailable, 'sla' => $context['capabilities'] ?? []],
            'selection_catalog' => $catalog, 'sla_context' => $context,
            'source_manifest' => [
                'adapter' => 'ana-glpi11-12-v1', 'schema_references' => ['11.0.8', '12.0.1'],
                'extraction_started_at' => $started, 'extraction_finished_at' => gmdate('c'),
                'read_only' => true, 'atomic_across_queries' => false, 'timezone' => $period['timezone'],
                'period_start_epoch' => $period['start_epoch'], 'period_end_epoch' => $period['end_epoch'],
                'scope_basis' => $input['scope_basis'] ?? 'closed', 'candidate_count' => count($tickets),
                'safety_limits' => ['tickets' => self::MAX_TICKETS, 'associations' => self::MAX_ASSOCIATIONS, 'history' => 250000, 'queue_entries' => 30000, 'queue_events' => 250000],
                'date_storage' => 'GLPI11/12 TIMESTAMP; SQL epoch anchors; indexed FROM_UNIXTIME boundaries',
                'telephone' => $phone,
            ],
        ];
    }

    public static function period(array $input): array
    {
        $month = (string)($input['month'] ?? '');
        if (!preg_match('/\A[0-9]{4}-(?:0[1-9]|1[0-2])\z/D', $month)) {
            throw new \InvalidArgumentException('Informe a competência no formato AAAA-MM.');
        }
        $timezone = (string)($input['timezone'] ?? 'America/Sao_Paulo');
        $zone = new \DateTimeZone($timezone);
        $start = new \DateTimeImmutable($month . '-01 00:00:00', $zone);
        $utc = new \DateTimeImmutable($month . '-01 00:00:00', new \DateTimeZone('UTC'));
        return ['timezone' => $timezone, 'start_epoch' => $start->getTimestamp(), 'end_epoch' => $start->modify('+1 month')->getTimestamp(),
            'start' => $start->format('Y-m-d H:i:s'), 'end' => $start->modify('+1 month')->format('Y-m-d H:i:s'),
            'reference_start_epoch' => $utc->getTimestamp(), 'reference_end_epoch' => $utc->modify('+1 month')->getTimestamp()];
    }

    /** No current-team predicate: otherwise unassigned and transferred tickets disappear. */
    public static function ticketQuery(array $input, array $period): array
    {
        $basis = $input['scope_basis'] ?? 'closed';
        if (!in_array($basis, ['closed', 'solved', 'reference'], true)) throw new \InvalidArgumentException('Base mensal inválida.');
        $dateColumn = $basis === 'solved' ? 'solvedate' : 'closedate';
        $entity = (int)($input['entity_id'] ?? 0);
        if ($entity < 0) throw new \InvalidArgumentException('Entidade inválida.');
        $sql = <<<'SQL'
SELECT t.id, t.entities_id AS entity_id, t.name AS title, t.type, t.status, t.priority,
 t.urgency, t.impact, t.requesttypes_id, t.itilcategories_id AS category_id,
 c.completename AS category, c.code AS category_code,
 UNIX_TIMESTAMP(t.`date`) AS opened_epoch, UNIX_TIMESTAMP(t.date_creation) AS created_epoch,
 UNIX_TIMESTAMP(t.takeintoaccountdate) AS taken_epoch, UNIX_TIMESTAMP(t.solvedate) AS solved_epoch,
 UNIX_TIMESTAMP(t.closedate) AS closed_epoch, UNIX_TIMESTAMP(t.time_to_resolve) AS deadline_epoch,
 UNIX_TIMESTAMP(t.time_to_own) AS own_deadline_epoch,
 t.takeintoaccount_delay_stat AS takeintoaccount_seconds, t.solve_delay_stat AS solve_seconds,
 t.close_delay_stat AS close_seconds, t.waiting_duration AS waiting_seconds,
 t.sla_waiting_duration AS sla_waiting_seconds, t.slas_id_ttr AS sla_id, t.slas_id_tto AS sla_tto_id,
 s.id AS survey_id, s.satisfaction AS satisfaction_raw, s.satisfaction_scaled_to_5 AS satisfaction,
 UNIX_TIMESTAMP(s.date_answered) AS survey_answered_epoch
FROM glpi_tickets t
LEFT JOIN glpi_itilcategories c ON c.id = t.itilcategories_id
LEFT JOIN glpi_ticketsatisfactions s ON s.tickets_id = t.id
WHERE t.entities_id = ? AND t.is_deleted = 0
SQL;
        $sql .= $basis === 'solved' ? ' AND t.status IN (5,6)' : ' AND t.status = 6';
        $sql .= ' AND t.' . $dateColumn . ' >= FROM_UNIXTIME(?) AND t.' . $dateColumn . ' < FROM_UNIXTIME(?)';
        $params = [$entity, $period['start_epoch'], $period['end_epoch']];
        if ($basis === 'reference') {
            $sql .= ' AND t.`date` >= FROM_UNIXTIME(?) AND t.`date` < FROM_UNIXTIME(?)';
            $params[] = $period['reference_start_epoch']; $params[] = $period['reference_end_epoch'];
        }
        return [$sql . ' ORDER BY t.' . $dateColumn . ', t.id', $params];
    }

    private static function tickets(MysqlClient $client, array $input, array $period): array
    {
        [$sql, $parameters] = self::ticketQuery($input, $period);
        $tickets = [];
        foreach ($client->fetchAll($sql, $parameters, self::MAX_TICKETS) as $row) {
            $id = (int)$row['id'];
            if (isset($tickets[$id])) throw new \RuntimeException('Chamado duplicado na extração; nenhum total parcial foi produzido.');
            foreach (['id','entity_id','type','status','priority','urgency','impact','requesttypes_id','category_id','sla_id','sla_tto_id'] as $field) $row[$field] = (int)$row[$field];
            foreach (['opened_epoch','created_epoch','taken_epoch','solved_epoch','closed_epoch','deadline_epoch','own_deadline_epoch','survey_answered_epoch','survey_id','takeintoaccount_seconds','solve_seconds','close_seconds','waiting_seconds','sla_waiting_seconds'] as $field) $row[$field] = self::integer($row[$field] ?? null);
            foreach (['title','category','category_code'] as $field) $row[$field] = html_entity_decode((string)($row[$field] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            foreach (['opened','created','taken','solved','closed'] as $field) $row[$field . '_at'] = self::localDate($row[$field . '_epoch'], $period['timezone']);
            $row['deadline'] = self::localDate($row['deadline_epoch'], $period['timezone']);
            $row['satisfaction_raw'] = self::number($row['satisfaction_raw']);
            $row['satisfaction'] = self::number($row['satisfaction']);
            $row['satisfaction_basis'] = 'GLPI11/12 satisfaction_scaled_to_5';
            $row['group_ids'] = $row['group_names'] = $row['technician_ids'] = [];
            $row['status_events'] = $row['group_events'] = $row['solutions'] = [];
            $row['groups'] = ''; $row['technicians'] = '';
            $tickets[$id] = $row;
        }
        $associationCount = 0;
        foreach (array_chunk(array_keys($tickets), self::BATCH_SIZE) as $ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $rows = $client->fetchAll('SELECT a.tickets_id, a.groups_id, g.completename AS group_name FROM glpi_groups_tickets a LEFT JOIN glpi_groups g ON g.id = a.groups_id WHERE a.type = 2 AND a.tickets_id IN (' . $marks . ') ORDER BY a.tickets_id, a.groups_id', $ids, self::MAX_ASSOCIATIONS);
            $associationCount += count($rows);
            if ($associationCount > self::MAX_ASSOCIATIONS) throw new ConnectionException('row_limit_exceeded', 'Vínculos de chamados excederam o limite de extração.');
            foreach ($rows as $r) {
                $id = (int)$r['tickets_id']; $group = (int)$r['groups_id'];
                $tickets[$id]['group_ids'][] = $group;
                $tickets[$id]['group_names'][] = html_entity_decode((string)($r['group_name'] ?? ('Grupo #' . $group)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            $users = $client->fetchAll('SELECT tickets_id, users_id FROM glpi_tickets_users WHERE type = 2 AND tickets_id IN (' . $marks . ') ORDER BY tickets_id, users_id', $ids, self::MAX_ASSOCIATIONS);
            $associationCount += count($users);
            if ($associationCount > self::MAX_ASSOCIATIONS) throw new ConnectionException('row_limit_exceeded', 'Vínculos de chamados excederam o limite de extração.');
            foreach ($users as $r) $tickets[(int)$r['tickets_id']]['technician_ids'][] = (int)$r['users_id'];
        }
        foreach ($tickets as &$ticket) {
            $ticket['group_ids'] = array_values(array_unique($ticket['group_ids']));
            $ticket['technician_ids'] = array_values(array_unique($ticket['technician_ids']));
            $ticket['groups'] = implode('; ', $ticket['group_names']);
            $ticket['technicians'] = implode('; ', $ticket['technician_ids']);
        }
        unset($ticket);
        return array_values($tickets);
    }

    public static function localDate(?int $epoch, string $timezone): ?string
    {
        return $epoch === null ? null : (new \DateTimeImmutable('@' . $epoch))->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d\TH:i:sP');
    }
    private static function integer($v): ?int { return $v !== null && $v !== '' && preg_match('/\A-?[0-9]+\z/D', (string)$v) ? (int)$v : null; }
    private static function number($v): ?float { return $v !== null && $v !== '' && is_numeric($v) ? (float)$v : null; }
}
