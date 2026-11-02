-- 076_credit_note_applied.sql — credit notes applied to invoices: payment method "Credit note" and CN status
-- Issued → Partially applied → Applied (modules/invoices/includes/invoice_service.php, Agent API apply_credit_note).
-- Run BEFORE deploying the code: sql_mode on the server is not strict, so without the new ENUM value
-- an applied credit would silently be stored with an empty method.

ALTER TABLE invoice_payments
    MODIFY method ENUM('Bank Transfer','Credit Card','Cash','Other','Credit note') NOT NULL DEFAULT 'Bank Transfer';

-- Payments already made by applying a credit note (they were saved as "Other").
UPDATE invoice_payments p
    JOIN credit_note_allocations a ON a.payment_id = p.id
    SET p.method = 'Credit note'
    WHERE a.cancelled_at IS NULL;

-- Status of the credit notes already used.
UPDATE credit_notes c
    JOIN (SELECT credit_note_id, SUM(amount_cn) AS applied
          FROM credit_note_allocations
          WHERE cancelled_at IS NULL
          GROUP BY credit_note_id) a ON a.credit_note_id = c.id
    SET c.status = IF(a.applied >= c.total - 0.001, 'Applied', 'Partially applied')
    WHERE c.status = 'Issued';
