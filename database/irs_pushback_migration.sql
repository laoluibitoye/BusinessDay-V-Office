-- irs_pushback_migration.sql
-- Run ONCE in phpMyAdmin on hrindexx_hri_webmail BEFORE uploading PHP files.
-- Adds a 'pending_corrections' stage for every request type so that
-- rejected requests are pushed back for corrections instead of going terminal.
-- DO NOT upload this file to the web root.

INSERT IGNORE INTO irs_flow_stages
  (request_type, stage_code, stage_label, actor_roles, stage_order, stage_color, is_initial, is_terminal)
VALUES
  ('payment',    'pending_corrections', 'Corrections Required', '[]', 5, '#f59e0b', 0, 0),
  ('retirement', 'pending_corrections', 'Corrections Required', '[]', 5, '#f59e0b', 0, 0),
  ('requisition','pending_corrections', 'Corrections Required', '[]', 5, '#f59e0b', 0, 0),
  ('caution',    'pending_corrections', 'Corrections Required', '[]', 5, '#f59e0b', 0, 0),
  ('petty_cash', 'pending_corrections', 'Corrections Required', '[]', 5, '#f59e0b', 0, 0);
