<?php
// php tools/tests/followup_guide_activity_test.php — followup_guide_activity_only() (24 Nov 2026).
require __DIR__ . '/../../modules/operations/includes/followup_classify.php';

$cases = [
    // dropped: activity organised by the safari guide
    [['No booking for the Mto wa Mbu walk on 31 Jan: book for 2 pax and save the confirmation.', '', ''], true],
    [['No document for the visit', 'Mto wa Mbu village walk', 'activity'], true],
    [['Materuni waterfalls & coffee tour not booked.', '', ''], true],
    [['Maasai boma visit: no confirmation in invoices/.', '', 'other'], true],
    [['Hadzabe bushmen hunting at Lake Eyasi: book it.', '', ''], true],
    // kept: a stay, transfer, flight or money in those places, or another activity
    [['Kisima Ngeda (Lake Eyasi) still provisional.', '', ''], false],
    [['Transfer Mto wa Mbu → Arusha airport not confirmed.', '', ''], false],
    [['Invoice 300 vs quoted 200 for the Materuni tour.', '', ''], false],
    [['Mto wa Mbu walk not booked', '', 'stay'], false],
    [['Lake Natron Camp: 2 nights on the invoice, Calc has 3.', '', ''], false],
    [['Balloon safari on 12 Feb not booked.', '', ''], false],
    [['', '', ''], false],
];
$fail = 0;
foreach ($cases as [[$t, $s, $ty], $want]) {
    $got = followup_guide_activity_only($t, $s, $ty);
    if ($got !== $want) { $fail++; echo "FAIL want " . var_export($want, true) . ": $t | $s | $ty\n"; }
}
echo $fail ? "$fail failed\n" : "OK " . count($cases) . " cases\n";
exit($fail ? 1 : 0);
