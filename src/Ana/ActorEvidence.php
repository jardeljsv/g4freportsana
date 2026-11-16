<?php
namespace GlpiPlugin\G4freportsana\Ana;
use GlpiPlugin\G4freportsana\Database\MysqlClient;
use GlpiPlugin\G4freportsana\Database\ConnectionException;

/** Assignment and lifecycle history; no present-day user-membership substitution. */
final class ActorEvidence
{
    public static function load(MysqlClient $client, array $tickets, array $input, array &$warnings): array
    {
        $logs = $solutions = [];
        $available = true;
        try {
            foreach (array_chunk(array_column($tickets, 'id'), 250) as $ids) {
                $marks = implode(',', array_fill(0,count($ids),'?'));
                $rows = $client->fetchAll("SELECT id AS log_id, items_id AS ticket_id, UNIX_TIMESTAMP(date_mod) AS epoch, user_name,
 itemtype_link, linked_action, id_search_option, old_id, new_id, old_value, new_value
FROM glpi_logs WHERE itemtype = 'Ticket' AND items_id IN (" . $marks . ")
 AND ((linked_action = 0 AND id_search_option IN (12,15,16,17,18,37,180,185))
 OR (itemtype_link = 'Group' AND linked_action IN (15,16) AND id_search_option = 8)
 OR (itemtype_link = 'User' AND linked_action IN (15,16) AND id_search_option = 5))
ORDER BY items_id, date_mod, id", $ids, max(1,250000-count($logs)));
                $logs = array_merge($logs, $rows);
                if (count($logs) >= 250000) throw new ConnectionException('row_limit_exceeded','Histórico excedeu o limite seguro.');
            }
        } catch (ConnectionException $e) {
            if (!in_array($e->getReason(),['missing_table','missing_columns','permission_denied'],true)) throw $e;
            $logs = []; $available = false;
            $warnings[] = 'Histórico GLPI inacessível. Responsabilidade histórica e reaberturas ficam pendentes; nenhuma ausência de histórico será convertida em zero.';
        }
        $solutionsAvailable = true;
        try {
            foreach (array_chunk(array_column($tickets, 'id'),250) as $ids) {
                $rows = $client->fetchAll("SELECT id, items_id AS ticket_id, UNIX_TIMESTAMP(date_creation) AS created_epoch,
 UNIX_TIMESTAMP(date_approval) AS approval_epoch, users_id AS solver_id, user_name AS solver_name,
 status, users_id_approval AS approval_user_id
FROM glpi_itilsolutions WHERE itemtype = 'Ticket' AND items_id IN (" . implode(',',array_fill(0,count($ids),'?')) . ') ORDER BY items_id,date_creation,id', $ids,max(1,100000-count($solutions)));
                $solutions = array_merge($solutions,$rows);
                if (count($solutions)>=100000) throw new ConnectionException('row_limit_exceeded','Soluções excederam o limite seguro.');
            }
        } catch (ConnectionException $e) {
            if (!in_array($e->getReason(),['missing_table','missing_columns','permission_denied'],true)) throw $e;
            $solutions=[]; $solutionsAvailable=false;
            $warnings[]='Registros de solução inacessíveis; chamados sem confirmação documental serão identificados.';
        }
        $input['_history_available']=$available;
        $input['_solutions_available']=$solutionsAvailable;
        return self::annotate($tickets,$logs,$solutions,$input);
    }

    /** Exact same replay for live reads and offline regression fixtures. */
    public static function annotate(array $tickets,array $logs,array $solutions,array $input): array
    {
        return Lifecycle::annotate($tickets,$logs,$solutions,$input);
    }
    public static function historyUserId($value): int
    {
        return preg_match('/\((\d+)\)\s*$/u',(string)$value,$m) ? (int)$m[1] : 0;
    }
}
