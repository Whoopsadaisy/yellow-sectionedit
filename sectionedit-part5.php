<?php
trait YellowSectioneditPart5 {
function scan($text) {
        $lines = $this->getLines($text); $sections = array(); $frontMatterEnd = $this->getFrontMatterEnd($lines); $fenced = false; $fenceChar = ""; $fenceLength = 0; $counters = array(0, 0, 0, 0, 0, 0);
        for ($i = 0; $i < count($lines); $i++) {
            if ($i < $frontMatterEnd) continue; $line = $lines[$i]["text"];
            if ($fenced) { if (preg_match('/^ {0,3}(' . preg_quote($fenceChar, '/') . '{' . $fenceLength . ',})[ \t]*$/', $line)) $fenced = false; continue; }
            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $m)) { $fenced = true; $fenceChar = $m[1][0]; $fenceLength = strlen($m[1]); continue; }
            $heading = $this->parseAtxHeading($line); $headingLineCount = 1;
            if (!$heading && $i > 0 && trim($line) !== "" && preg_match('/^ {0,3}(=+|-+)[ \t]*$/', $line)) { $prev = $lines[$i - 1]["text"]; if (trim($prev) !== "") { $heading = array("level" => $line[0] === "=" ? 1 : 2, "title" => trim($prev), "startLine" => $i - 1); $headingLineCount = 2; } }
            if (!$heading) continue; $startLine = isset($heading["startLine"]) && $heading["startLine"] !== null ? $heading["startLine"] : $i; $heading["startLine"] = $startLine; $level = $heading["level"]; $title = $this->cleanAtxTitle($heading["title"]);
            for ($n = $level; $n < 6; $n++) $counters[$n] = 0; $counters[$level - 1]++; $idParts = array(); for ($n = 0; $n < $level; $n++) if ($counters[$n] > 0) $idParts[] = (string)$counters[$n];
            $sections[] = array("id" => implode(".", $idParts), "level" => $level, "type" => $headingLineCount === 1 ? "atx" : "setext", "title" => $title, "startLine" => $startLine, "endLine" => null, "start" => $lines[$startLine]["start"], "end" => strlen($text));
        }
        for ($i = 0; $i < count($sections); $i++) { $level = $sections[$i]["level"]; $end = strlen($text); $endLine = count($lines); for ($j = $i + 1; $j < count($sections); $j++) if ($sections[$j]["level"] <= $level) { $end = $sections[$j]["start"]; $endLine = $sections[$j]["startLine"]; break; } $sections[$i]["end"] = $end; $sections[$i]["endLine"] = $endLine; }
        return $sections;
    }
}
