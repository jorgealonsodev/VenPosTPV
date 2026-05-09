-- =============================================================================
-- VenPosTPV — Dev Seed Data
-- =============================================================================
-- Safe to run multiple times (INSERT IGNORE).
-- Run after migrations/install.sql.
-- =============================================================================

USE venpostpv;

-- 1. role: admin with full permissions
INSERT IGNORE INTO role (id, name, permissions)
VALUES (1, 'admin', '{"all":true}');

-- 2. terminal: Terminal 1 linked to PrestaShop shop 1
INSERT IGNORE INTO terminal (id, ps_shop_id, name, active)
VALUES (1, 1, 'Terminal 1', 1);

-- 3. user: María García, PIN = 1234
INSERT IGNORE INTO user (id, ps_employee_id, role_id, name, pin_hash, active)
VALUES (1, 1, 1, 'María García', SHA2('1234', 256), 1);

-- =============================================================================
-- Verification
-- =============================================================================
SELECT 'role'     AS `table`, COUNT(*) AS rows FROM role
UNION ALL
SELECT 'terminal' AS `table`, COUNT(*) AS rows FROM terminal
UNION ALL
SELECT 'user'     AS `table`, COUNT(*) AS rows FROM user;
