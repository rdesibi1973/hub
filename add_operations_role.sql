-- ============================================================
-- Migration: Add 'operations' role, restrict Operations Hub
-- Run once in phpMyAdmin on savannp5_savannah_leads
-- Date: 2026-05-18
-- ============================================================

-- 1. Create the 'operations' role (skip if already exists)
INSERT IGNORE INTO roles (name, description)
VALUES ('operations', 'Access to Operations Hub (Arrivals & Departures) only');

-- 2. Grant 'hub' and 'operations' permissions to the new role
INSERT IGNORE INTO role_permissions (role_id, module)
SELECT id, 'hub'        FROM roles WHERE name = 'operations';

INSERT IGNORE INTO role_permissions (role_id, module)
SELECT id, 'operations' FROM roles WHERE name = 'operations';

-- 3. Remove 'operations' permission from 'staff' role
DELETE rp FROM role_permissions rp
JOIN roles r ON r.id = rp.role_id
WHERE r.name = 'staff' AND rp.module = 'operations';

-- ============================================================
-- Result after migration:
--   admin      → hub, operations, leave, leads, admin
--   manager    → hub, operations, leave
--   operations → hub, operations          (NEW)
--   staff      → hub                      (operations removed)
--   accountant → hub, leads
-- ============================================================
