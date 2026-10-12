<?php
/**
 * assignment_service.php — when a request was assigned to its current agent.
 *
 * requests.assigned_at       DATETIME (EAT) of the last agent change (or creation with an agent)
 * requests.assigned_agent_id agent_id seen at that moment — lets assign_touch() detect a change
 *                            without knowing the old value.
 *
 * Every writer that inserts a request or may change agent_id calls assign_touch($db, $id)
 * right after the write. Rows assigned before this existed have assigned_at NULL:
 * readers fall back to date_received (see assign_date_sql()).
 */

/** Lazy schema: adds the two columns once (MySQL has no ADD COLUMN IF NOT EXISTS). */
function assign_schema(PDO $db, int $skipId = 0): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $st = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests' AND COLUMN_NAME = 'assigned_at'");
        if ((int)$st->fetchColumn() > 0) return;
        foreach ([
            "ALTER TABLE requests ADD COLUMN assigned_at DATETIME NULL",
            "ALTER TABLE requests ADD COLUMN assigned_agent_id INT NULL",
            "ALTER TABLE requests ADD INDEX idx_req_assigned_at (assigned_at)",
        ] as $q) {
            try { $db->exec($q); } catch (PDOException $ignored) {}
        }
        // Existing rows: current agent is the known one, assignment date unknown (NULL).
        // $skipId = the request being saved right now, so its change is still recorded.
        $db->prepare("UPDATE requests SET assigned_agent_id = agent_id WHERE id <> ?")->execute([$skipId]);
    } catch (Throwable $ignored) {}
}

/** Record an assignment if agent_id changed since the last call. Never throws. */
function assign_touch(PDO $db, int $requestId): void {
    if ($requestId <= 0) return;
    assign_schema($db, $requestId);
    try {
        $db->prepare("UPDATE requests
                      SET assigned_at = IF(agent_id IS NULL, assigned_at, ?),
                          assigned_agent_id = agent_id
                      WHERE id = ? AND NOT (agent_id <=> assigned_agent_id)")
           ->execute([date('Y-m-d H:i:s'), $requestId]);
    } catch (Throwable $ignored) {}
}

/** SQL expression: the date a request was assigned (falls back to date_received). */
function assign_date_sql(string $alias = ''): string {
    $p = $alias !== '' ? $alias . '.' : '';
    return "COALESCE(DATE({$p}assigned_at), {$p}date_received)";
}
