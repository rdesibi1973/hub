-- Add GPS coordinates to lodges so the itinerary map can place a marker on the
-- exact lodge (falling back to the destination centre when a lodge has none).
-- Values are copied straight from Google Maps (right-click → the "lat, lng"
-- pair) via the lodge editor. MySQL (BlueHost): no IF NOT EXISTS on ADD COLUMN.
ALTER TABLE iti_lodges
  ADD COLUMN latitude  DECIMAL(9,6) NULL AFTER website,
  ADD COLUMN longitude DECIMAL(9,6) NULL AFTER latitude;
