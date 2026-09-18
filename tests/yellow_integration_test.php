<?php
// Integration tests against the real Yellow 0.9.26 source.
require __DIR__ . "/../sectionedit.php";
require getenv("YELLOW_ROOT") . "/system/workers/core.php";
require getenv("YELLOW_ROOT") . "/system/workers/edit.php";

function failTest($message) {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}
function same($expected, $actual, $message) {
    if ($expected !== $actual) {
        failTest($message . "\nexpected: " . var_export($expected, true) .
            "\nactual:   " . var_export($actual, true));
    }
}

$root = getenv("YELLOW_ROOT");
@mkdir("$root/content/1-home", 0777, true);
@mkdir("$root/content/2-wiki", 0777, true);
file_put_contents("$root/content/1-home/page.md", "# Home\n");
file_put_contents("$root/content/2-wiki/page.md", "## Pages\n\nSome text\n");
file_put_contents("$root/content/2-wiki/juniper-vpn.md", "## Juniper VPN\n");

$cwd = getcwd();
chdir($root);
$yellow = new YellowCore();
$yellow->load();
$yellow->system->set("coreServerScheme", "http");
$yellow->system->set("coreServerAddress", "example.test");
$yellow->system->set("coreServerBase", "/yellow");
$edit = $yellow->extension->get("edit");

same(
    "content/2-wiki/page.md",
    $yellow->lookup->findFileFromContentLocation("/wiki/"),
    "Yellow must resolve /wiki/ to the directory page"
);
same(
    "content/2-wiki/juniper-vpn.md",
    $yellow->lookup->findFileFromContentLocation("/wiki/juniper-vpn"),
    "Yellow must resolve file page without trailing slash"
);
same(
    "content/2-wiki/juniper-vpn/page.md",
    $yellow->lookup->findFileFromContentLocation("/wiki/juniper-vpn/"),
    "Yellow lookup must preserve its documented directory semantics"
);

$page = new YellowPage($yellow);
$page->setRequestInformation("http", "example.test", "/yellow", "/wiki/", "content/2-wiki/page.md", false);
$page->parseMeta("## Pages\n\nSome text\n", 200);
$edit->onParseMetaData($page);
same(
    "http://example.test/yellow/edit/wiki/",
    $page->get("editPageUrl"),
    "YellowEdit must generate the directory edit URL with trailing slash"
);

$page = new YellowPage($yellow);
$page->setRequestInformation("http", "example.test", "/yellow", "/wiki/juniper-vpn", "content/2-wiki/juniper-vpn.md", false);
$page->parseMeta("## Juniper VPN\n", 200);
$edit->onParseMetaData($page);
same(
    "http://example.test/yellow/edit/wiki/juniper-vpn",
    $page->get("editPageUrl"),
    "YellowEdit must generate the file edit URL without trailing slash"
);

echo "PASS: real Yellow lookup/edit URL integration tests\n";
