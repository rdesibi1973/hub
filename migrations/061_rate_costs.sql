-- 061_rate_costs.sql — sale price next to the cost rates (Agent API get_rates / fill_calc).
-- flight_routes.rate_pax and activity_rates.rate are COSTS (the quote module adds the
-- markup on them, and fill_calc writes them in the Calc). sale_pax / sale = price to agency.
-- Also created lazily by includes/calc_service.php (calc_rates_schema), which also renames
-- the cost_pax / cost columns created by the first phase-2 build.

ALTER TABLE flight_routes  ADD COLUMN sale_pax DECIMAL(10,2) NULL DEFAULT NULL AFTER rate_pax;
ALTER TABLE activity_rates ADD COLUMN sale     DECIMAL(10,2) NULL DEFAULT NULL AFTER rate;
