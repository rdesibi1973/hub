<?php
/**
 * guide_activities.php — cultural / local activities organised by the safari guide on the spot (Roberto, 14 Nov 2026):
 * no supplier booking, so no document expected in invoices/ and never a Booking follow-up line.
 * Shared by booking_check.php (bc_services() skips them) and the Booking follow-up (followup_classify.php
 * followup_guide_activity_only() drops / closes Claude-check findings about them). Pure, no dependencies, PHP-7 style.
 *
 * Town / city tour, lunch in town (Roberto, 20 Nov 2026: "lunch in town 20 pp", "town lunch", "lunch in Arusha town"),
 * Iraqw and Maasai bomas, Mto wa Mbu, Materuni, Lake Eyasi (Hadzabe, Datoga), Lake Natron, Napuro, Lake Duluti (walk,
 * canoe), Shanga, coffee plantation / farm / tour (Roberto, 24 Nov 2026, VaniaLaRocca: "Shanga non si prenota e nemmeno
 * la visita del coffee plantation"; "coffee" alone is not enough — Arusha Coffee Lodge is a lodge). Matched anywhere in
 * the item ("Visit Iraqw boma", "Materuni waterfalls & coffee", "Shanga e poi vanno al Tarangire"); transfers are
 * excepted by the callers.
 */
const BC_GUIDE_ACTIVITY = '/\b(town tour|city tour|lunch in (\w+ )?town|town lunch|iraq[wk]|maa?sai|mto ?wa ?mbu|materuni|eyasi|hadza(be)?|datoga|natron|napuro|duluti|shanga|coffee (plantation|farm|tour|estate)s?)\b/i';
