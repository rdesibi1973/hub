-- CK tracker notes: free notes and sent emails on a booking folder (one row of
-- the CK tracker = a private safari or a whole GRP). Keyed by ck_folders.id, not
-- by request, so GRPs and folders without a Hub request can have notes too.
-- An email sent from the CK tracker is logged here as note_type 'email_sent'.
-- Also created lazily by modules/leads/includes/ck_lib.php (ck_ensure_schema).
CREATE TABLE IF NOT EXISTS ck_notes (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  ck_folder_id  INT          NOT NULL,
  created_by    INT          NULL,        -- users.id
  note_type     VARCHAR(12)  NOT NULL,    -- manual | email_sent
  recipients    VARCHAR(500) NULL,        -- email_sent: the To addresses
  subject       VARCHAR(255) NULL,
  body          MEDIUMTEXT   NULL,        -- manual: plain text; email_sent: HTML
  created_at    DATETIME     NOT NULL,    -- office time (Africa/Dar_es_Salaam)
  KEY idx_ck_notes_folder (ck_folder_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
