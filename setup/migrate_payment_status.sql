-- ============================================================
-- Migration: Add payment_status to requests
-- Run once on savannp5_savannah_leads
-- ============================================================

ALTER TABLE requests ADD COLUMN payment_status VARCHAR(20) DEFAULT NULL;

-- Migrate Balance-Cash first (more specific match via practice_code)
UPDATE requests
SET    payment_status = 'Balance-Cash',
       status         = 'Booked'
WHERE  status = 'Balance'
  AND  practice_code LIKE '%BALANCE-CASH%';

-- Migrate remaining Balance
UPDATE requests
SET    payment_status = 'Balance',
       status         = 'Booked'
WHERE  status = 'Balance';

-- Migrate Deposit
UPDATE requests
SET    payment_status = 'Deposit',
       status         = 'Booked'
WHERE  status = 'Deposit';

-- Migrate Paid
UPDATE requests
SET    payment_status = 'Paid',
       status         = 'Booked'
WHERE  status = 'Paid';

-- Verify: after migration no rows should have these old status values
-- SELECT status, COUNT(*) FROM requests GROUP BY status;
