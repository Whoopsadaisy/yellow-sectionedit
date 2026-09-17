<?php
trait YellowSectioneditPart1 {
function onParsePageOutput($page, $text) {
        $edit = $this->yellow->extension->isExisting("edit") ?
            $this->yellow->extension->get("edit") : null;
        if (!$edit || !$edit->editable || is_string_empty($text)) return null;
        $content = $page->parserData;
        if (is_string_empty($content)) return null;
        $sections = $this->scan($page->rawData);
        if (count($sections) == 0) return null;
        $baseUrl = $page->get("editPageUrl");
        if (is_string_empty($baseUrl)) {
            $baseUrl = $this->yellow->lookup->normaliseUrl(
                $page->scheme, $page->address,
                $this->yellow->system->get("coreServerBase"),
                rtrim($this->yellow->system->get("editLocation"), "/") . $page->location
            );
        }
        $index = 0;
        $modifiedContent = preg_replace_callback(
            '#(<h([1-6])\b[^>]*>)(.*?)(</h\2>)#is',
            function($matches) use (&$index, $sections, $baseUrl) {
                if ($index >= count($sections)) return $matches[0];
                $section = $sections[$index++];
                $level = (int)$matches[2];
                if ($level != $section["level"]) return $matches[0];
                $url = $baseUrl . (strpos($baseUrl, "?") === false ? "?" : "&") .
                    "section=" . rawurlencode($section["id"]);
                $url = htmlspecialchars($url, ENT_QUOTES, "UTF-8");
                $label = htmlspecialchars("Edit section " . $section["id"], ENT_QUOTES, "UTF-8");
                $link = '<a class="sectionedit-link" href="' . $url . '" title="' . $label . '" aria-label="' . $label . '">✎</a>';
                return $matches[1] . $matches[3] . ' ' . $link . $matches[4];
            }, $content
        );
        if (is_null($modifiedContent) || $modifiedContent === $content) return null;
        return str_replace($content, $modifiedContent, $text);
    }
function onParsePageExtra($page, $name) {
        $edit = $this->yellow->extension->isExisting("edit") ?
            $this->yellow->extension->get("edit") : null;
        if (!$edit || !$edit->editable || $name != "header") return null;
        return '<style type="text/css">.sectionedit-link{margin-left:.45em;text-decoration:none;opacity:.7;font-size:.85em}.sectionedit-link:hover{opacity:1}</style>' . PHP_EOL;
    }
function onRequest($scheme, $address, $base, $location, $fileName) {
        if (!$this->isSectioneditLocation($location)) return 0;
        if (is_string_empty($this->yellow->page->getRequest("section"))) return 0;
        $editBase = rtrim(
            $this->yellow->system->get("coreServerBase") .
            $this->yellow->system->get("editLocation"), "/"
        );
        list($scheme, $address, $editBase, $pageLocation, $pageFileName) =
            $this->yellow->lookup->getRequestInformation($scheme, $address, $editBase);
        $editPrefix = rtrim($this->yellow->system->get("editLocation"), "/");
        $pageLocation = substru($location, strlenu($editPrefix));
        $pageLocation = "/" . ltrim($pageLocation, "/");
        $pageLocation = rtrim($pageLocation, "/");
        if ($pageLocation === "") $pageLocation = "/";
        $pageFileName = $this->yellow->lookup->findFileFromContentLocation($pageLocation);
        return $this->processRequest($scheme, $address, $editBase, $pageLocation, $pageFileName);
    }
function isSectioneditLocation($location) {
        $prefix = rtrim($this->yellow->system->get("sectioneditLocation"), "/");
        return $location === $prefix || substru($location, 0, strlenu($prefix) + 1) === $prefix . "/";
    }
}
