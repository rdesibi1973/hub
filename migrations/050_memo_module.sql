-- Migration 050: Memo Board module
-- Private per-user board: memos / todos / notes + self-reminders (one-shot or recurring)
-- Decoupled from requests. Each memo belongs to exactly one user.

CREATE TABLE IF NOT EXISTS memos (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    title           VARCHAR(255) NOT NULL,
    body            TEXT NULL,
    type            ENUM('memo','todo','note') NOT NULL DEFAULT 'memo',
    status          ENUM('open','done','archived') NOT NULL DEFAULT 'open',
    priority        ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
    pinned          TINYINT(1) NOT NULL DEFAULT 0,
    color           VARCHAR(7) NULL,                 -- hex e.g. #FFE082 for post-it style
    due_date        DATE NULL,
    reminder_at     DATETIME NULL,                   -- next scheduled send (EAT)
    reminder_sent   TINYINT(1) NOT NULL DEFAULT 0,   -- used only when recur_rule = 'none'
    recur_rule      ENUM('none','daily','weekly','monthly') NOT NULL DEFAULT 'none',
    sort_order      INT NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    deleted_at      DATETIME NULL,                   -- soft-delete
    INDEX idx_memo_user (user_id, deleted_at),
    INDEX idx_memo_reminder (status, reminder_at, deleted_at),
    CONSTRAINT fk_memo_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
