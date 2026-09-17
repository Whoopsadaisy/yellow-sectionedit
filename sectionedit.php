<?php
require_once __DIR__ . "/sectionedit-part1.php";
require_once __DIR__ . "/sectionedit-part2.php";
require_once __DIR__ . "/sectionedit-part3.php";
require_once __DIR__ . "/sectionedit-part4.php";
require_once __DIR__ . "/sectionedit-part5.php";
require_once __DIR__ . "/sectionedit-part6.php";

class YellowSectionedit {
    const VERSION = "0.1.23";
    const PRIORITY = "0";
    public $yellow;
    use YellowSectioneditPart1, YellowSectioneditPart2, YellowSectioneditPart3,
        YellowSectioneditPart4, YellowSectioneditPart5, YellowSectioneditPart6;
    public function onLoad($yellow) {
        $this->yellow = $yellow;
        $this->yellow->system->setDefault("sectioneditLocation", "/edit/");
    }
}
