-- 061_rate_costs.sql — cost next to sale rates (Agent API get_rates / fill_calc).
-- Also created lazily by modules/leads/pricing_activities.php and includes/calc_service.php.
-- rate_pax / rate = what we SELL; cost_pax / cost = what we PAY (written in the Calc).

ALTER TABLE flight_routes  ADD COLUMN cost_pax DECIMAL(10,2) NULL DEFAULT NULL AFTER rate_pax;
ALTER TABLE activity_rates ADD COLUMN cost     DECIMAL(10,2) NULL DEFAULT NULL AFTER rate;
