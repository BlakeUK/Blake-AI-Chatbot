<?php
// scripts/setup_terms.php - seeds the trade terminology list with the terms
// we know about (idempotent: never overwrites an edited entry). Staff add and
// edit the rest in Admin > Terminology.
require dirname(__DIR__) . '/src/bootstrap.php';
$seed = [
    ['External cable entry cover (brick cover)', 'roman nose, romans nose, blast plate, brick cover, brick covers, hole tidy, hole tidies, cable entry cover',
     'cable entry cover, hole tidy, brick cover', 'Covers brick damage where cable passes through a wall; terracotta, white, brown, black and clear.', '', 'sales'],
    ['Amplifier (active, powered)', 'amp, amps, booster, signal booster, powered splitter, amplified splitter, distribution amp',
     'amplifier, masthead amplifier, distribution amplifier, launch amplifier',
     'An amplifier is powered and adds gain. A passive splitter (e.g. SPL204, SPL408) only divides the signal and LOSES level - never treat the two as the same. Check which the customer needs.',
     'BLATLA11, BLAAMP12, BLAMHD12V', 'technical'],
    ['Passive splitter', 'splitter, splitters, passive splitter, 2 way splitter, 4 way splitter, 8 way splitter',
     'splitter, passive splitter, IRS splitter', 'Passive: divides one input between outputs with insertion loss, no power and no gain. Examples SPL204 (2-way), SPL408 (8-way). Not an amplifier.',
     'SPL204, SPL408', 'technical'],
    ['Masthead amplifier', 'masthead, mast head, mast-head amp, pole amp, aerial amp',
     'masthead amplifier, variable gain masthead', 'Mounted at the aerial and powered through the coax by a power supply unit.', 'BLAMHD12V', 'technical'],
    ['Satellite dish', 'sky dish, satellite dish, dish, zone 1 dish, zone 2 dish', 'satellite dish, dish', 'Sky dishes are satellite dishes; zone 1 and zone 2 are UK sizing.', '', 'sales'],
    ['Coaxial cable', 'coax, co-ax, co ax, aerial cable, tv cable, wf100, rg6', 'coaxial cable, WF100, RG6', 'Single coax for TV/aerial. Twin (shotgun) cable is for satellite.', '', 'sales'],
    ['Twin satellite (shotgun) cable', 'shotgun, shotgun cable, twin cable, twinsat, wf65', 'twin satellite cable, shotgun, WF65', 'Two cables joined side by side for twin LNB satellite runs.', '', 'sales'],
    ['Outlet plate', 'wall plate, face plate, faceplate, socket plate, outlet plate, tv socket', 'outlet plate, wall plate, IEC outlet', 'The plate at the TV point; isolated or non-isolated, IEC and/or F-type.', '', 'sales'],
    ['Log-periodic aerial', 'log, log periodic, log-periodic, logs, lp aerial', 'log periodic aerial', 'Compact directional TV aerial with good rejection; good in strong-signal areas.', 'BLA-LP20K, BLA-LP28K', 'technical'],
    ['Aerial', 'ariel, arial, aeriel, antena, antenna, areial, aeraial', 'TV aerial, aerial', 'Common misspellings of aerial.', '', 'technical'],
];
$n = 0;
foreach ($seed as [$term, $aliases, $search, $note, $codes, $dept]) {
    $s = db()->prepare('SELECT id FROM term_aliases WHERE lower(term) = lower(?)');
    $s->execute([$term]);
    if ($s->fetchColumn()) continue;
    \Knowledge\Terms::save(['term' => $term, 'aliases' => $aliases, 'search_terms' => $search, 'note' => $note,
                            'product_codes' => $codes, 'department' => $dept, 'active' => 1]);
    $n++;
}
echo "Terminology: {$n} term(s) added, " . count(\Knowledge\Terms::all()) . " active.\n";
