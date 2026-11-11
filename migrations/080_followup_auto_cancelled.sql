-- 080: Booking follow-up close reasons of 11 Nov 2026 (additive: values added to the ENUM, none removed).
--   auto_request_cancelled  request Lost (or Cancelled before the cancelled-folder rule)
--   auto_merged             joined another line of the same service (one line per transfer and day)
--   auto_cancelled          trip cancelled: folder renamed _CANCELLED / request Cancelled ("Trip cancelled")
-- Also applied lazily by followup_schema_reasons() (modules/operations/includes/followup_service.php, meta reasons_3).
ALTER TABLE booking_followups MODIFY close_reason
  ENUM('auto_confirmed','auto_ticket_saved','auto_trip_ended','auto_not_found','auto_resolved','auto_request_cancelled','auto_merged','auto_cancelled','manual') NULL;
