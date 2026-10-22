<?php
require __DIR__ . "/../sectionedit.php";

function fail($message) {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}
function assertSameValue($expected, $actual, $message) {
    if ($expected !== $actual) {
        fail($message . "\nexpected: " . var_export($expected, true) . "\nactual:   " . var_export($actual, true));
    }
}

$se = new YellowSectionedit();

$source = "## Section 1\n\n### Section 1.1\n\nText\n\n## Section 2\n\nText\n\n## Section 3\n";
$sections = $se->scan($source);
assertSameValue(["0","1","1.1","2","3"], array_column($sections, "id"), "structural section IDs with empty preamble");
assertSameValue([0,2,3,2,2], array_column($sections, "level"), "heading levels");


$source = "Intro text before the first heading.\n\n## First\n\nSection text\n\n### Child\n\nChild text\n\n## Second\n";
$sections = $se->scan($source);
assertSameValue(["0","1","1.1","2"], array_column($sections, "id"), "preamble must not shift heading IDs");
assertSameValue("preamble", $sections[0]["type"], "leading content has a dedicated preamble section");
assertSameValue("Intro text before the first heading.\n\n", substr($source, $sections[0]["start"], $sections[0]["end"] - $sections[0]["start"]), "preamble offsets stop before first heading");

$source = "---\ntitle: Front matter title\n---\n\nIntro text after front matter.\n\n## First\n";
$sections = $se->scan($source);
assertSameValue(["0","1"], array_column($sections, "id"), "front matter preamble keeps normal heading IDs");
$preambleText = substr($source, $sections[0]["start"], $sections[0]["end"] - $sections[0]["start"]);
if (strpos($preambleText, "title: Front matter title") !== false || strpos($preambleText, "Intro text after front matter.") === false) {
    fail("preamble must exclude front matter while retaining leading page text");
}

$source = "## First\n\nText\n";
$sections = $se->scan($source);
assertSameValue(["0","1"], array_column($sections, "id"), "pages without leading text still get an empty section 0");
assertSameValue("", trim(substr($source, $sections[0]["start"], $sections[0]["end"] - $sections[0]["start"])), "empty preamble remains empty");

$source = "Only page text, with no headings.\n";
$sections = $se->scan($source);
assertSameValue(["0"], array_column($sections, "id"), "a page without headings can still edit its leading content");
assertSameValue($source, substr($source, $sections[0]["start"], $sections[0]["end"] - $sections[0]["start"]), "preamble-only page offsets");

$source = "---\ntitle: Test\n---\n\n# Real\n\n```\n# Not a heading\n```\n\n## Child\n";
$sections = $se->scan($source);
assertSameValue(["0","1","1.1"], array_column($sections, "id"), "front matter/fenced code exclusion with empty preamble");

$source = "## chapter 1\n\nblabla\n\n## chapter 2\n\nblabla";
$sections = $se->scan($source);
$first = $sections[1];
assertSameValue("## chapter 1\n\nblabla\n\n", substr($source, $first["start"], $first["end"] - $first["start"]), "section boundary preserves separator before next heading");

$posted = "## chapter 1\n\nblabla\n\n## chapter 2\n\nblabla";
$sections = $se->scan($posted);
$first = $sections[1];
$sectionEdit = "## chapter 1\n\nblabla\nnewcontent";
$eol = "lf";
if ($first["end"] < strlen($posted) && !preg_match('/(?:\r\n|\r|\n)$/', $sectionEdit)) {
    $sectionEdit .= ($eol === "crlf" ? "\r\n" : "\n");
}
$result = substr($posted, 0, $first["start"]) . $sectionEdit . substr($posted, $first["end"]);
assertSameValue("## chapter 1\n\nblabla\nnewcontent\n## chapter 2\n\nblabla", $result, "edited section must not glue following heading");

$posted = "## chapter 1\r\n\r\nblabla\r\n\r\n## chapter 2\r\n";
$sections = $se->scan($posted);
$first = $sections[1];
$sectionEdit = "## chapter 1\r\n\r\nblabla\r\nnewcontent";
$eol = "crlf";
if ($first["end"] < strlen($posted) && !preg_match('/(?:\r\n|\r|\n)$/', $sectionEdit)) {
    $sectionEdit .= ($eol === "crlf" ? "\r\n" : "\n");
}
$result = substr($posted, 0, $first["start"]) . $sectionEdit . substr($posted, $first["end"]);
if (strpos($result, "newcontent\r\n## chapter 2") === false) {
    fail("CRLF edited section glued or used wrong line ending");
}

echo "PASS: SectionEdit scanner and newline regression tests\n";
