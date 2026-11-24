<?php
// php tools/tests/followup_room_layout_test.php — followup_room_layout_only() (24 Nov 2026).
require __DIR__ . '/../../modules/operations/includes/followup_classify.php';

$cases = [
    // dropped: room type / bed layout only
    ['WB9813 has room Eyasi (3 Sngl) on 05 Feb for the couple: change to a double room.', true],
    ['Twin room booked, the Calc has 1 DBL: ask the lodge for a double bed.', true],
    ['Kilimanjaro (1Dbl & 2Sngl) booked for 1 DBL', true],
    ['Room type differs from the Calc (1 Twin vs 1 Double).', true],
    // kept: something else is wrong
    ['Rooms do not hold the pax: 1 double for 3 adults.', false],
    ['1 double room booked, the Calc has 2 double rooms.', false],
    ['Marera booking still provisional (expires 10 Dec), 1 double room.', false],
    ['Invoice 1,250 vs quoted 1,100 (+150) for 2 double rooms.', false],
    ['Transfer ZNZ airport → hotel 13 Oct not confirmed.', false],
    ['Double room: check-in 05 Feb on the SV, Calc 04 Feb.', false],
    ['', false],
];
$fail = 0;
foreach ($cases as [$t, $want]) {
    $got = followup_room_layout_only($t);
    if ($got !== $want) { $fail++; echo "FAIL want " . var_export($want, true) . ": $t\n"; }
}
echo $fail ? "$fail failed\n" : "OK " . count($cases) . " cases\n";
exit($fail ? 1 : 0);
