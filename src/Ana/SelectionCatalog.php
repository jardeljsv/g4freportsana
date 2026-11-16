<?php
namespace GlpiPlugin\G4freportsana\Ana;
use GlpiPlugin\G4freportsana\Database\MysqlClient;

/** Confirm only selected ANA group IDs. Never infer contract ownership from names. */
final class SelectionCatalog
{
    public static function resolve(MysqlClient $client, array $input, array &$warnings): array
    {
        $ids = array_values(array_unique(array_map('intval', array_merge($input['n1_group_ids'] ?? [4], $input['n2_group_ids'] ?? [1], $input['n3_group_ids'] ?? range(22,30)))));
        if (!$ids || min($ids) < 1) throw new \InvalidArgumentException('Selecione os grupos responsáveis ANA.');
        $result = [];
        foreach (array_chunk($ids, 250) as $chunk) {
            $rows = $client->fetchAll('SELECT id, completename AS name, entities_id AS entity_id, groups_id AS parent_id FROM glpi_groups WHERE id IN (' . implode(',', array_fill(0,count($chunk),'?')) . ') ORDER BY id', $chunk, 1000);
            foreach ($rows as $row) {
                $id = (int)$row['id'];
                $result[$id] = ['id' => $id, 'name' => html_entity_decode((string)$row['name'],ENT_QUOTES|ENT_HTML5,'UTF-8'), 'entity_id' => (int)$row['entity_id'], 'parent_id' => (int)$row['parent_id']];
            }
        }
        $missing = array_diff($ids, array_keys($result));
        if ($missing) throw new \InvalidArgumentException('Grupos ANA não encontrados nesta fonte GLPI: ' . implode(', ', $missing));
        return ['groups' => $result, 'basis' => 'explicit_ids', 'entity_id' => (int)($input['entity_id'] ?? 0)];
    }
}
