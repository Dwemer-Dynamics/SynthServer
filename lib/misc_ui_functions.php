<?php

require_once __DIR__.'/legacy_row_management.php';
// Both management pages start their session before output; other callers remain read-only without one.
if (session_status()===PHP_SESSION_ACTIVE && (!is_string($_SESSION['synth_row_delete_csrf'] ?? null)
    || !preg_match('/^[a-f0-9]{64}$/D',$_SESSION['synth_row_delete_csrf']))) {
    $_SESSION['synth_row_delete_csrf']=bin2hex(random_bytes(32));
}

function print_array_as_table($data)
{
    // Start the HTML table

    if (sizeof($data) < 1) {
        return;
    }
    echo "<div class='datatable'>";
    echo "<table border='1' width='100%' class='table table-striped table-bordered table-sm'>";


    // Print the first row with array keys
    echo "<tr class='primary'>";
    foreach (array_keys($data[0]) as $key) {
        echo "<th>" . $key . "</th>";
    }
    echo "</tr>";

    // Print the remaining rows with array values
    foreach ($data as $i => $row) {
        $colorClass = "";

        // if we have an "url" column, paint the rows different colors
        if (isset($row["url"])) {
            $colorClasses = [
                "table-primary-dark",   // Deep blue
                "table-success-dark",   // Deep green
                "table-info-dark",      // Deep cyan
    "table-warning-dark",   // Deep amber
                "table-danger-dark"     // Deep red
            ];
            $colorIndex = abs(crc32(preg_replace('/in \d+ secs/', '', $row["url"]))) % 5;
            $colorClass = $colorClasses[$colorIndex];
        }
        // Add coloring for response log table
        else if (isset($row["response"]) || isset($row["AI Response"])) {
            $colorClasses = [
                "table-primary-dark",   // Deep blue
                "table-info-dark",      // Deep cyan
                "table-success-dark"    // Deep green
            ];
            // Use the rowid or timestamp to determine color
            $seed = $row["rowid"] ?? $row["localts"] ?? $row["LocalTS"] ?? time();
            $colorIndex = abs(crc32((string)$seed)) % 3;
            $colorClass = $colorClasses[$colorIndex];
        }

        echo "<tr>";
        foreach ($row as $n => $cell) {
            if ($n == "prompt") {
                /* This is fucking slow
                 * echo "<td class='{$colorClass}'>
                    <span data-bs-toggle='collapse' data-bs-target='.prompt-$i' style='cursor:pointer'>[+]</span>
                    <pre class='collapse prompt-$i'>" . $cell . "</pre>
                </td>";
                */

                echo "<td><span class='foldableCtl' onclick='togglePre(this)' style='cursor:pointer'>[+]</span><pre class='foldable'>" . $cell . "</pre></td>";

            } elseif ($n == "rowid") {
                $table=$_GET['table'] ?? '';
                $deletable=session_status()===PHP_SESSION_ACTIVE;
                try { synthLegacyRowTarget(['table'=>$table,'rowid'=>$cell]); }
                catch (InvalidArgumentException $error) { $deletable=false; }
                $safeId=htmlspecialchars((string)$cell,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
                if ($deletable) {
                    $safeTable=htmlspecialchars($table,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
                    echo "<td class='$colorClass'><button type='button' class='icon-link synth-row-delete'
                        data-table='$safeTable' data-rowid='$safeId' aria-label='Delete row $safeId from $safeTable'
                        style='color:red !important;background:none;border:0;cursor:pointer;padding:0;font:inherit;'>
                        $safeId <i class='bi-trash' style='color:red !important;' aria-hidden='true'></i></button></td>";
                } else {
                    // Virtual rows, unknown views and callers without a session are not deletion targets.
                    echo "<td class='$colorClass'>$safeId</td>";
                }
            } elseif ($n == "summary" || $n == "content") {
                echo "<td class='$colorClass'><em>
                    ".nl2br($cell)."</em>

                </td>";
            } elseif ($n == "packed_message") {
                echo "<td class='$colorClass'><p style='max-height:200px;overflow-y:scroll'>
                    ".nl2br($cell)."</p>

                </td>";
            } elseif (stripos($cell, 'background chat') !== false) {
                echo "<td class='$colorClass'><em>" . $cell . "</em></td>";
            } elseif (stripos($cell, ($GLOBALS["PLAYER_NAME"] ?? "_none") . ':') !== false) {
                echo "<td class='$colorClass'>" . $cell . "</td>";
            } elseif (stripos($cell, 'obtains a quest') !== false) {
                echo "<td class='$colorClass'><strong>" . $cell . "</strong></td>";
            } elseif (stripos($cell, ($GLOBALS["SYNTH_NAME"] ?? "_none") . ":") !== false) {
                echo "<td  class='$colorClass'>" . $cell . "</td>";

            } elseif ($n == "cost_USD" || $n == "total_cost_so_far_USD") {
                $formatted_cell = (is_numeric($cell)) ? number_format($cell, 6) : $cell;
                echo "<td class='$colorClass'>" . $formatted_cell . "</td>";
            } else {
                echo "<td class='$colorClass'>" . $cell . "</td>";
            }
        }
        echo "</tr>";
    }

    // End the HTML table
    echo "</table></div>";
}



// Move below javascript code elsewhere
?>

<script>
const synthRowDeleteCsrf = <?php echo json_encode(session_status()===PHP_SESSION_ACTIVE ? ($_SESSION['synth_row_delete_csrf'] ?? '') : '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const synthRowDeletesPending = new Set();
async function deleteRowAndRefresh(table, rowId, button) {
  const key = `${table}:${rowId}`;
  if (!button || button.disabled || synthRowDeletesPending.has(key)) return;
  if (!synthRowDeleteCsrf) { window.alert('Reload the page before deleting a row.'); return; }
  if (!window.confirm(`Delete row ${rowId} from ${table}? This cannot be undone.`)) return;
  synthRowDeletesPending.add(key);
  button.disabled = true;
  button.setAttribute('aria-busy', 'true');
  try {
    const response = await fetch('cmd/deleteRow.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ table, rowid: String(rowId), csrf_token: synthRowDeleteCsrf })
    });
    const result = await response.json();
    if (!response.ok || result?.ok !== true || result?.deleted !== true) {
      throw new Error(typeof result?.error === 'string' ? result.error : 'Could not confirm deletion. Reload the page before trying again.');
    }
    window.location.reload();
  } catch (error) {
    window.alert(error instanceof Error ? error.message : 'Could not delete this row.');
  } finally {
    synthRowDeletesPending.delete(key);
    button.disabled = false;
    button.removeAttribute('aria-busy');
  }
}
document.addEventListener('click', event => {
  const button = event.target.closest?.('button.synth-row-delete');
  if (button) void deleteRowAndRefresh(button.dataset.table, button.dataset.rowid, button);
});
</script>
