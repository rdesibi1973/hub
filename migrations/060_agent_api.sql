-- 060_agent_api.sql — Agent API (modules/leads/agent_api.php)
-- The table is also created lazily on first call; this file documents it and
-- lets it be created up front.

CREATE TABLE IF NOT EXISTS agent_audit_log (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    ts           DATETIME NOT NULL,
    action       VARCHAR(40) NOT NULL,
    request_id   INT NULL,
    user_id      INT NULL,
    http_code    SMALLINT NOT NULL DEFAULT 200,
    dry_run      TINYINT(1) NOT NULL DEFAULT 0,
    payload_json MEDIUMTEXT NULL,
    result_json  MEDIUMTEXT NULL,
    ip           VARCHAR(45) NULL,
    KEY idx_ts (ts),
    KEY idx_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dedicated Hub user the API key acts as (AGENT_API_USER, default 'claude-agent').
-- Create it from Admin → Users (so the password is hashed by the Hub), role
-- 'manager', agent = Roberto's agent if requests should default to him.
