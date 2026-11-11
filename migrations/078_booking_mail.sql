-- 078_booking_mail.sql — Booking mail check (modules/operations/booking_mail.php): headers of glady@ / operations@
-- crossed with the Booking follow-up, and the log of who opened which thread. Kept apart from mail_headers_index so the
-- Sales Response numbers do not change. Optional: bm_schema() creates the same tables on first use.
-- Never holds mail bodies, attachments or passport numbers.

CREATE TABLE IF NOT EXISTS booking_mail_index (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    mailbox     VARCHAR(120) NOT NULL,
    folder      VARCHAR(190) NOT NULL,
    file_key    VARCHAR(190) NOT NULL,
    msg_key     CHAR(40) NOT NULL,
    message_id  VARCHAR(255) NULL,
    in_reply_to VARCHAR(255) NULL,
    refs        TEXT NULL,
    date_utc    DATETIME NOT NULL,
    from_addr   VARCHAR(190) NOT NULL DEFAULT '',
    to_addrs    TEXT NULL,
    subject     VARCHAR(500) NULL,
    direction   VARCHAR(10) NOT NULL,
    indexed_at  DATETIME NOT NULL,
    UNIQUE KEY uq_bmi_file (mailbox, folder, file_key),
    KEY idx_bmi_msg (msg_key),
    KEY idx_bmi_mid (message_id(100)),
    KEY idx_bmi_irt (in_reply_to(100)),
    KEY idx_bmi_date (date_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS booking_mail_scan_state (
    mailbox    VARCHAR(120) NOT NULL,
    folder     VARCHAR(190) NOT NULL,
    last_mtime INT NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (mailbox, folder)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS booking_mail_run (
    id     INT AUTO_INCREMENT PRIMARY KEY,
    ran_at DATETIME NOT NULL,
    ok     TINYINT(1) NOT NULL,
    error  TEXT NULL,
    added  INT NOT NULL DEFAULT 0,
    KEY idx_bmr_ran (ran_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every thread opened, attachment downloaded, reply sent, passport read or written. No content.
CREATE TABLE IF NOT EXISTS mail_access_log (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    at         DATETIME NOT NULL,
    user_id    INT NULL,
    username   VARCHAR(100) NULL,
    action     VARCHAR(30) NOT NULL,
    mailbox    VARCHAR(120) NULL,
    msg_key    CHAR(40) NULL,
    request_id INT NULL,
    reason     VARCHAR(500) NULL,
    KEY idx_mal_at (at),
    KEY idx_mal_req (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
