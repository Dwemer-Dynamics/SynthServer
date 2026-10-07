<?php

// Only existing legacy table views may name a deletion target; schema names and SQL fragments are not inputs.
function synthLegacyRowTarget(array $input): array
{
    $tables=['responselog'=>'responselog','eventlog'=>'eventlog','cache'=>'eventlog','log'=>'log',
        'diarylog'=>'diarylog','memory'=>'memory','memory_summary'=>'memory_summary'];
    $table=$input['table'] ?? null;
    $id=$input['rowid'] ?? null;
    if (!is_string($table) || !isset($tables[$table]) || (!is_int($id) && !is_string($id))
        || !preg_match('/^[1-9][0-9]{0,17}$/D',strval($id))) {
        throw new InvalidArgumentException('Invalid legacy row target.');
    }
    return [$tables[$table],strval($id)];
}

// Preserve native insertion evidence; duplicate legacy row IDs cannot silently delete multiple records.
function synthDeleteLegacyRow($db, array $input): bool
{
    [$table,$id]=synthLegacyRowTarget($input);
    if (($GLOBALS['SYNTH_REQUEST_EVENT']['json']['protocol_version'] ?? 1)===2) {
        throw new LogicException('Native requests cannot delete legacy records.');
    }
    if (!$db->isTransactionIdle()) throw new LogicException('Row deletion requires an idle database connection.');
    return $db->withManagementTransaction(static function($connection) use ($table,$id) {
        $result=@pg_query_params($connection,"DELETE FROM synth.$table WHERE rowid=$1"
            .($table==='eventlog' ? ' AND synth_session_id IS NULL' : '').' RETURNING rowid',[$id]);
        if ($result===false) throw new RuntimeException('Unable to delete legacy row.');
        $deleted=pg_num_rows($result);
        if ($deleted>1) throw new RuntimeException('Ambiguous legacy row ID.');
        return $deleted===1;
    });
}
