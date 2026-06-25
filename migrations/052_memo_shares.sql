-- Migration 052: Memo sharing
-- Allows a memo owner to share a memo with specific users or all users.
-- shared_with_user_id = NULL means "all active users".
-- can_edit = 1 grants edit rights; 0 = read-only.

CREATE TABLE IF NOT EXISTS memo_shares (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    memo_id              INT NOT NULL,
    shared_with_user_id  INT NULL,          -- NULL = everyone
    can_edit             TINYINT(1) NOT NULL DEFAULT 0,
    created_at           DATETIME NOT NULL,
    UNIQUE KEY uq_share (memo_id, shared_with_user_id),
    INDEX idx_share_memo (memo_id),
    INDEX idx_share_user (shared_with_user_id),
    CONSTRAINT fk_share_memo FOREIGN KEY (memo_id) REFERENCES memos(id) ON DELETE CASCADE,
    CONSTRAINT fk_share_user FOREIGN KEY (shared_with_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
