<?php
// Section edit extension for Datenstrom Yellow 0.9.
// Keeps the normal /edit/ editor untouched and delegates saving/merging
// to YellowEditResponse::getPageEdit().

class YellowSectionedit {
    const VERSION = "0.1.28";
    const PRIORITY = "0";
    public $yellow;

    public function onLoad($yellow) {
        $this->yellow = $yellow;
        $this->yellow->system->setDefault("sectioneditLocation", "/edit/");
    }

    // Add direct SectionEdit links to rendered headings while the normal
    // Yellow Edit mode is active. The Markdown source is scanned to obtain
    // the same structural section IDs used by the SectionEdit editor.
    // Add direct SectionEdit links after the complete page HTML has been
    // rendered. This is intentionally onParsePageOutput, not
    // onParseContentHtml: the Toc extension runs during content parsing and
    // would otherwise copy our pencil character into the generated TOC.
    public function onParsePageOutput($page, $text) {
        $edit = $this->yellow->extension->isExisting("edit") ?
            $this->yellow->extension->get("edit") : null;
        if (!$edit || !$edit->editable || is_string_empty($text)) return null;

        // At this stage all onParseContentHtml hooks have already run, so
        // $page->parserData contains the final content HTML (including TOC).
        // Modify that content only; processing the complete page output would
        // also count headings from the Yellow layout itself.
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
            },
            $content
        );
        if (is_null($modifiedContent) || $modifiedContent === $content) return null;

        // Replace the already rendered content fragment in the final page.
        return str_replace($content, $modifiedContent, $text);
    }

    public function onParsePageExtra($page, $name) {
        $edit = $this->yellow->extension->isExisting("edit") ?
            $this->yellow->extension->get("edit") : null;
        if (!$edit || !$edit->editable || $name != "header") return null;
        return '<style type="text/css">.sectionedit-link{margin-left:.45em;text-decoration:none;opacity:.7;font-size:.85em}.sectionedit-link:hover{opacity:1}</style>' . PHP_EOL;
    }

    public function onRequest($scheme, $address, $base, $location, $fileName) {
        // Section editing intentionally lives below YellowEdit's /edit/ URL.
        // YellowEdit sets its authentication cookies with the /edit/ path,
        // therefore a separate /edit-section/ URL would not receive those
        // cookies from the browser.
        if (!$this->isSectioneditLocation($location)) return 0;
        if (is_string_empty($this->yellow->page->getRequest("section"))) return 0;

        list($scheme, $address, $editBase, $pageLocation, $pageFileName) =
            $this->getEditPageInformation($scheme, $address, $base, $location);

        return $this->processRequest($scheme, $address, $editBase, $pageLocation, $pageFileName);
    }

    // Resolve the page below the /edit/ URL using the same base calculation
    // as YellowEdit. Preserve the trailing slash for the first content lookup;
    // only fall back to the slashless location when Yellow cannot resolve it.
    private function getEditPageInformation($scheme, $address, $base, $location) {
        $editBase = rtrim(
            $this->yellow->system->get("coreServerBase") .
            $this->yellow->system->get("editLocation"), "/"
        );
        list($scheme, $address, $editBase, $pageLocation, $pageFileName) =
            $this->yellow->lookup->getRequestInformation($scheme, $address, $editBase);

        $editPrefix = rtrim($this->yellow->system->get("editLocation"), "/");
        $pageLocation = substru($location, strlenu($editPrefix));
        $pageLocation = "/" . ltrim($pageLocation, "/");
        if ($pageLocation === "") $pageLocation = "/";

        $pageFileName = $this->yellow->lookup->findFileFromContentLocation($pageLocation);
        if (!$this->yellow->lookup->isContentFile($pageFileName) || !is_file($pageFileName)) {
            $trimmedLocation = rtrim($pageLocation, "/");
            if ($trimmedLocation === "") $trimmedLocation = "/";
            $trimmedFileName = $this->yellow->lookup->findFileFromContentLocation($trimmedLocation);
            if ($this->yellow->lookup->isContentFile($trimmedFileName) && is_file($trimmedFileName)) {
                $pageLocation = $trimmedLocation;
                $pageFileName = $trimmedFileName;
            }
        }

        return array($scheme, $address, $editBase, $pageLocation, $pageFileName);
    }

    private function isSectioneditLocation($location) {
        $prefix = rtrim($this->yellow->system->get("sectioneditLocation"), "/");
        return $location === $prefix || substru($location, 0, strlenu($prefix) + 1) === $prefix . "/";
    }

    private function processRequest($scheme, $address, $base, $location, $fileName) {
        $edit = $this->yellow->extension->isExisting("edit") ?
            $this->yellow->extension->get("edit") : null;

        if (!$edit) {
            $this->yellow->page->error(500, "Section edit requires the edit extension!");
            return $this->yellow->processRequestError();
        }

        // The existing Edit extension owns authentication, CSRF and the user
        // session. Keep the original request action unchanged here. In particular,
        // a direct GET must leave action empty so YellowEdit accepts the request
        // without requiring a same-site Referer/Origin header. The POST form below
        // supplies action=edit explicitly.

        if (!$edit->checkUserAuth($scheme, $address, $base, $location, $fileName)) {
            return $this->yellow->sendStatus(403);
        }
        if (!$edit->response->isUserAccess("edit", $location)) {
            return $this->yellow->sendStatus(403);
        }
        if (!$this->yellow->lookup->isContentFile($fileName)) {
            return $this->showFileDiagnostic($location, $fileName);
        }

        // Yellow lookup returns content paths relative to the Yellow root.
        // Do not use PHP is_readable() here: the web server's current working
        // directory is not guaranteed to be the Yellow root. Yellow's own
        // toolbox must be used for filesystem access.
        $rawDataSource = $this->yellow->toolbox->readFile($fileName);
        // Determine the line-ending format directly from the source. Do not call
        // YellowEditResponse::getPageData() here: that method expects its response
        // rawDataEdit state to have been initialized by YellowEdit first.
        $endOfLine = $edit->response->getEndOfLine($rawDataSource);

        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            if ($this->yellow->page->getRequest("action") === "preview") {
                return $this->showPreview($edit, $scheme, $address, $base, $location, $fileName, $endOfLine);
            }
            return $this->saveSection(
                $edit, $scheme, $address, $base, $location, $fileName,
                $rawDataSource, $endOfLine
            );
        }

        return $this->showEditor(
            $edit, $scheme, $address, $base, $location, $fileName,
            $rawDataSource, $endOfLine
        );
    }

    private function showPreview($edit, $scheme, $address, $base, $location, $fileName, $endOfLine) {
        $rawDataEdit = $this->yellow->page->getRequest("rawdataedit");
        if (is_string_empty($rawDataEdit)) {
            $this->yellow->page->error(400, "Missing preview data.");
            return $this->yellow->processRequestError();
        }

        $page = $edit->response->getPagePreview(
            $scheme, $address, $base, $location, $fileName,
            $rawDataEdit, $endOfLine
        );
        $page->headerData = array(
            "Cache-Control" => "no-cache, no-store",
            "Content-Type" => $this->yellow->toolbox->getMimeContentType("a.html"),
            "Last-Modified" => $this->yellow->toolbox->getHttpDateFormatted(time())
        );
        return $this->yellow->sendData($page->statusCode, $page->headerData, $page->outputData);
    }

    private function getSectionId() {
        $id = trim($this->yellow->page->getRequest("section"));
        return preg_match('/^[1-9][0-9]*(?:\.[1-9][0-9]*)*$/', $id) ? $id : "";
    }

    private function saveSection($edit, $scheme, $address, $base, $location, $fileName, $rawDataSource, $endOfLine) {
        $sectionId = $this->getSectionId();
        $sectionEdit = isset($_POST["sectiondata"]) && is_string($_POST["sectiondata"])
            ? $_POST["sectiondata"] : "";
        $postedSource = isset($_POST["rawdatasource"]) && is_string($_POST["rawdatasource"])
            ? $_POST["rawdatasource"] : "";
        $postedEol = isset($_POST["rawdataendofline"]) && is_string($_POST["rawdataendofline"])
            ? $_POST["rawdataendofline"] : $endOfLine;

        // The browser must send the exact source snapshot it opened. We use
        // that snapshot for offsets and as Yellow's 3-way merge base.
        if ($postedSource === "") {
            $this->yellow->page->error(400, "Missing source snapshot.");
            return $this->yellow->processRequestError();
        }

        $sections = $this->scan($postedSource);
        $section = $this->findSection($sections, $sectionId);
        if (!$section) {
            $this->yellow->page->error(409, "Section no longer exists in the source snapshot.");
            return $this->yellow->processRequestError();
        }

        // If the section is followed by another heading, keep at least one line
        // ending between the edited section and that heading. The editor is allowed
        // to remove the final newline from the textarea, but Markdown headings must
        // not be glued directly to the preceding text.
        if ($section["end"] < strlen($postedSource) && !preg_match('/(?:\r\n|\r|\n)$/', $sectionEdit)) {
            $sectionEdit .= ($postedEol === "crlf" ? "\r\n" : "\n");
        }
        $rawDataEdit =
            substr($postedSource, 0, $section["start"]) .
            $sectionEdit .
            substr($postedSource, $section["end"]);

        // Delegate all actual page validation, metadata checks and 3-way merge
        // to the existing Yellow edit response.
        $page = $edit->response->getPageEdit(
            $scheme, $address, $base, $location, $fileName,
            $postedSource, $rawDataEdit, $rawDataSource, $postedEol
        );

        if ($page->isError()) {
            return $this->yellow->processRequestError();
        }

        $rawData = $page->rawData;
        if (!$this->yellow->toolbox->writeFile($page->fileName, $rawData, true)) {
            $this->yellow->page->error(500, "Can't write file '" . $page->fileName . "'!");
            return $this->yellow->processRequestError();
        }

        $redirect = $this->yellow->lookup->normaliseUrl(
            $scheme, $address,
            $base,
            $page->location
        );
        $anchor = $this->getSectionAnchor($rawData, $sectionId);
        if (!is_string_empty($anchor)) $redirect .= "#" . rawurlencode($anchor);
        return $this->yellow->sendStatus(303, $redirect);
    }

    private function showEditor($edit, $scheme, $address, $base, $location, $fileName, $rawDataSource, $endOfLine) {
        $sectionId = $this->getSectionId();
        $sections = $this->scan($rawDataSource);
        $section = $this->findSection($sections, $sectionId);
        if (!$section) {
            return $this->showSectionDiagnostic(
                $location, $fileName, $sectionId, $sections, $base
            );
        }

        $sectionText = substr($rawDataSource, $section["start"], $section["end"] - $section["start"]);
        $csrf = $this->yellow->toolbox->getCookie("yellowcsrftoken");
        list($actionUrl, $cancelUrl) = $this->getEditorUrls(
            $scheme, $address, $base, $location, $sectionId, $rawDataSource
        );

        $title = htmlspecialchars($section["title"], ENT_QUOTES, "UTF-8");
        $text = htmlspecialchars($sectionText, ENT_QUOTES, "UTF-8");
        $source = htmlspecialchars($rawDataSource, ENT_QUOTES, "UTF-8");
        $eol = htmlspecialchars($endOfLine, ENT_QUOTES, "UTF-8");
        $id = htmlspecialchars($sectionId, ENT_QUOTES, "UTF-8");
        $actionUrl = htmlspecialchars($actionUrl, ENT_QUOTES, "UTF-8");
        $cancelUrl = htmlspecialchars($cancelUrl, ENT_QUOTES, "UTF-8");
        $csrf = htmlspecialchars($csrf, ENT_QUOTES, "UTF-8");
        $assetLocation = $this->yellow->system->get("coreServerBase") .
            $this->yellow->system->get("coreAssetLocation");

        $html = '<!doctype html><html><head><meta charset="utf-8">';
        $html .= '<meta name="viewport" content="width=device-width,initial-scale=1">';
        $html .= '<title>Edit: ' . $title . '</title>';
        // Resolve the theme from the actual content page. At this point
        // YellowCore has not rendered the content page yet, so
        // $this->yellow->page may still describe the /edit/ request rather than
        // the page being edited. Build a page from the source metadata exactly
        // like YellowEditResponse::getPageEdit() does.
        $themePage = new YellowPage($this->yellow);
        $themePage->setRequestInformation($scheme, $address, $base, $location, $fileName, false);
        $themePage->parseMeta($rawDataSource, 200);
        $theme = $themePage->get("theme");
        $themeName = $this->yellow->lookup->normaliseName($theme);
        $themeFile = $this->yellow->system->get("coreThemeDirectory") . $themeName . ".css";
        if (is_file($themeFile)) {
            $themeLocation = $assetLocation . $themeName . ".css";
            $html .= '<link rel="stylesheet" type="text/css" media="all" href="' .
                htmlspecialchars($themeLocation, ENT_QUOTES, "UTF-8") . '" />';
        }
        // Reuse the normal Yellow Edit styles and icon set.
        $html .= '<link rel="stylesheet" type="text/css" media="all" href="' .
            htmlspecialchars($assetLocation . 'edit.css', ENT_QUOTES, "UTF-8") . '" />';
        $html .= '<style>' . $this->getCss() . '</style></head><body class="sectionedit-body">';
        $html .= '<main class="sectionedit yellow-pane">';
        $html .= '<div class="yellow-content">';
        $html .= '<form method="post" action="' . $actionUrl . '" id="sectionedit-form">';
        $html .= '<input type="hidden" name="action" value="edit">';
        $html .= '<input type="hidden" name="section" value="' . $id . '">';
        $html .= '<input type="hidden" name="yellowcsrftoken" value="' . $csrf . '">';
        $html .= '<textarea hidden name="rawdatasource" id="rawdatasource">' . $source . '</textarea>';
        $html .= '<input type="hidden" name="rawdataendofline" value="' . $eol . '">';
        $html .= '<div id="sectionedit-toolbar">';
        $html .= '<ul class="yellow-toolbar yellow-toolbar-left sectionedit-toolbar-buttons">';
        $html .= '<li><a href="#" class="yellow-toolbar-btn-icon yellow-toolbar-tooltip" data-section-action="format" aria-label="Format"><i class="yellow-icon yellow-icon-format"></i></a></li>';
        $html .= '<li><a href="#" class="yellow-toolbar-btn-icon yellow-toolbar-tooltip" data-section-action="bold" aria-label="Bold"><i class="yellow-icon yellow-icon-bold"></i></a></li>';
        $html .= '<li><a href="#" class="yellow-toolbar-btn-icon yellow-toolbar-tooltip" data-section-action="italic" aria-label="Italic"><i class="yellow-icon yellow-icon-italic"></i></a></li>';
        $html .= '<li><a href="#" class="yellow-toolbar-btn-icon yellow-toolbar-tooltip" data-section-action="strikethrough" aria-label="Strikethrough"><i class="yellow-icon yellow-icon-strikethrough"></i></a></li>';
        $html .= '<li><a href="#" class="yellow-toolbar-btn-icon yellow-toolbar-tooltip" data-section-action="code" aria-label="Code"><i class="yellow-icon yellow-icon-code"></i></a></li>';
        $html .= '<li><a href="#" class="yellow-toolbar-btn-icon yellow-toolbar-tooltip" data-section-action="list" aria-label="List"><i class="yellow-icon yellow-icon-list"></i></a></li>';
        $html .= '<li><a href="#" class="yellow-toolbar-btn-icon yellow-toolbar-tooltip" data-section-action="link" aria-label="Link"><i class="yellow-icon yellow-icon-link"></i></a></li>';
        $html .= '<li><a href="#" class="yellow-toolbar-btn-icon yellow-toolbar-tooltip" data-section-action="preview" aria-label="Preview"><i class="yellow-icon yellow-icon-preview"></i></a></li>';
        $html .= '</ul>';
        $html .= '<ul class="yellow-toolbar yellow-toolbar-right sectionedit-toolbar-main">';
        $html .= '<li><a href="' . $cancelUrl . '" class="yellow-toolbar-btn">Cancel</a></li>';
        $html .= '<li><a href="#" id="sectionedit-submit" class="yellow-toolbar-btn yellow-toolbar-btn-edit" data-section-action="save">Save</a></li>';
        $html .= '</ul>';
        $html .= '<ul class="yellow-toolbar yellow-toolbar-banner"></ul>';
        $html .= '</div>';
        $html .= '<textarea name="sectiondata" id="sectiondata" class="yellow-edit-text" spellcheck="false">' . $text . '</textarea>';
        $html .= '<div id="sectionedit-preview" class="yellow-edit-preview" hidden></div>';
        $html .= '</form></div></main>';
        $html .= '<script type="text/javascript">';
        $html .= '(function(){';
        $html .= 'var text=document.getElementById("sectiondata"),form=document.getElementById("sectionedit-form"),preview=document.getElementById("sectionedit-preview");';
        $html .= 'function focusText(){text.focus();}';
        $html .= 'function wrap(prefix,suffix){var a=text.selectionStart,b=text.selectionEnd,v=text.value,s=v.slice(a,b);text.focus();text.setSelectionRange(a,b);document.execCommand("insertText",false,prefix+s+suffix);text.value=v.slice(0,a)+prefix+s+suffix+v.slice(b);text.setSelectionRange(a+prefix.length,a+prefix.length+s.length);}';
        $html .= 'function block(prefix){var v=text.value,a=text.selectionStart,b=text.selectionEnd,start=a;while(start>0&&v.charAt(start-1)!="\n")start--;var end=b;while(end<v.length&&v.charAt(end)!="\n")end++;var s=v.slice(start,end);if(s.slice(0,prefix.length)===prefix){s=s.split("\n").map(function(x){return x.slice(prefix.length);}).join("\n");}else{s=s.split("\n").map(function(x){return prefix+x;}).join("\n");}text.value=v.slice(0,start)+s+v.slice(end);text.focus();text.setSelectionRange(start,start+s.length);}';
        $html .= 'function link(){var url=window.prompt("URL:","https://");if(url===null)return;var a=text.selectionStart,b=text.selectionEnd,v=text.value,s=v.slice(a,b);var ins=s?"["+s+"]("+url+")":"[link]("+url+")";text.value=v.slice(0,a)+ins+v.slice(b);text.focus();text.setSelectionRange(a+ins.length,a+ins.length);}';
        $html .= 'function format(){var p=window.prompt("Format:","h2");if(p==="h1"||p==="h2"||p==="h3")block(p==="h1"?"# ":p==="h2"?"## ":"### ");}';
        $html .= 'function togglePreview(){if(!preview.hasAttribute("hidden")){preview.hidden=true;text.hidden=false;return;}var payload=new FormData();payload.append("action","preview");payload.append("yellowcsrftoken",document.querySelector("input[name=yellowcsrftoken]").value);payload.append("rawdataedit",text.value);payload.append("rawdataendofline",document.querySelector("input[name=rawdataendofline]").value);var xhr=new XMLHttpRequest();xhr.open("POST",window.location.pathname+window.location.search,true);xhr.onload=function(){if(xhr.status===200){preview.innerHTML=xhr.responseText;text.hidden=true;preview.hidden=false;}};xhr.send(payload);}';
        $html .= 'document.querySelectorAll("[data-section-action]").forEach(function(el){el.addEventListener("click",function(e){e.preventDefault();var a=el.getAttribute("data-section-action");if(a==="bold")wrap("**","**");else if(a==="italic")wrap("*","*");else if(a==="strikethrough")wrap("~~","~~");else if(a==="code")wrap("`","`");else if(a==="list")block("* ");else if(a==="link")link();else if(a==="format")format();else if(a==="preview")togglePreview();else if(a==="save")form.submit();});});';
        $html .= 'focusText();})();';
        $html .= '</script></body></html>';

        return $this->yellow->sendData(200, array("Content-Type" => "text/html; charset=UTF-8", "Cache-Control" => "no-cache, no-store"), $html);
    }

    private function getEditorUrls($scheme, $address, $base, $location, $sectionId, $rawDataSource) {
        $actionUrl = $this->yellow->lookup->normaliseUrl(
            $scheme, $address,
            $this->yellow->system->get("coreServerBase"),
            rtrim($this->yellow->system->get("sectioneditLocation"), "/") . $location
        );
        $actionUrl .= (strpos($actionUrl, "?") === false ? "?" : "&") .
            "action=edit&section=" . rawurlencode($sectionId);

        $cancelUrl = $this->yellow->lookup->normaliseUrl($scheme, $address, $base, $location);
        $cancelAnchor = $this->getSectionAnchor($rawDataSource, $sectionId);
        if (!is_string_empty($cancelAnchor)) $cancelUrl .= "#" . rawurlencode($cancelAnchor);

        return array($actionUrl, $cancelUrl);
    }

    // Yellow's Markdown parser generates heading IDs from the heading text
    // when no explicit ID is supplied. Reproduce that rule for the section
    // currently being edited so Save/Cancel can return to the same heading.
    // If the title is duplicated, Yellow assigns error-duplicate-heading to
    // the later heading; in that case there is no useful unique anchor, so
    // return an empty anchor rather than jumping somewhere arbitrary.
    private function getSectionAnchor($rawData, $sectionId) {
        $sections = $this->scan($rawData);
        $target = $this->findSection($sections, $sectionId);
        if (!$target) return "";

        $anchor = $this->yellow->lookup->normaliseName($target["title"], true, false, true);
        if (is_string_empty($anchor)) return "";

        $count = 0;
        foreach ($sections as $section) {
            if ($this->yellow->lookup->normaliseName($section["title"], true, false, true) === $anchor) {
                $count++;
                if ($section["id"] === $sectionId) break;
            }
        }
        return $count === 1 ? $anchor : "";
    }

    private function findSection($sections, $id) {
        foreach ($sections as $section) {
            if ($section["id"] === $id) return $section;
        }
        return null;
    }

    private function showFileDiagnostic($location, $fileName) {
        $contentFile = $this->yellow->lookup->isContentFile($fileName);
        $readable = $this->yellow->lookup->isContentFile($fileName) && is_file($fileName);
        $html = $this->diagnosticStart("SectionEdit " . self::VERSION . " - file diagnostic");
        $html .= '<h1>SectionEdit: file could not be resolved</h1>';
        $html .= '<dl>';
        $html .= $this->diagnosticRow("Location", $location);
        $html .= $this->diagnosticRow("File", $fileName);
        $html .= $this->diagnosticRow("Content file", $contentFile ? "yes" : "no");
        $html .= $this->diagnosticRow("Readable", $readable ? "yes" : "no");
        $html .= '</dl>';
        $html .= '<p>The normal Yellow request lookup produced the values above. This diagnostic replaces the generic 404 page for SectionEdit testing.</p>';
        $html .= $this->diagnosticEnd();
        return $this->yellow->sendData(404, array("Content-Type" => "text/html; charset=UTF-8"), $html);
    }

    private function showSectionDiagnostic($location, $fileName, $sectionId, $sections, $base) {
        $html = $this->diagnosticStart("SectionEdit " . self::VERSION . " - section diagnostic");
        $html .= '<h1>SectionEdit: section not found</h1>';
        $html .= $this->diagnosticRow("Requested section", $sectionId === "" ? "(empty/invalid)" : $sectionId);
        $html .= $this->diagnosticRow("Location", $location);
        $html .= $this->diagnosticRow("File", $fileName);
        $html .= $this->diagnosticRow("Base", $base);
        $html .= $this->diagnosticRow("Core server base", $this->yellow->system->get("coreServerBase"));
        $html .= $this->diagnosticRow("Edit location", $this->yellow->system->get("editLocation"));
        $html .= $this->diagnosticRow("Readable", "readFile() was successful");
        if (!$sections) {
            $html .= '<h2>No headings detected</h2>';
            $html .= '<p>The scanner did not find any ATX or Setext headings outside fenced code blocks/front matter.</p>';
        } else {
            $html .= '<h2>Detected sections</h2><table><thead><tr><th>ID</th><th>Level</th><th>Type</th><th>Title</th><th>Lines</th></tr></thead><tbody>';
            foreach ($sections as $section) {
                $html .= '<tr>';
                $html .= '<td><code>' . htmlspecialchars($section["id"], ENT_QUOTES, "UTF-8") . '</code></td>';
                $html .= '<td>' . (int)$section["level"] . '</td>';
                $html .= '<td>' . htmlspecialchars($section["type"], ENT_QUOTES, "UTF-8") . '</td>';
                $html .= '<td>' . htmlspecialchars($section["title"], ENT_QUOTES, "UTF-8") . '</td>';
                $html .= '<td>' . ((int)$section["startLine"] + 1) . '-' . (int)$section["endLine"] . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
            $html .= '<p>Try one of the IDs above as <code>?section=...</code>.</p>';
        }
        $html .= $this->diagnosticEnd();
        return $this->yellow->sendData(404, array("Content-Type" => "text/html; charset=UTF-8"), $html);
    }

    private function diagnosticRow($label, $value) {
        return '<dt>' . htmlspecialchars($label, ENT_QUOTES, "UTF-8") . '</dt><dd><code>' .
            htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8") . '</code></dd>';
    }

    private function diagnosticStart($title) {
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' .
            '<title>' . htmlspecialchars($title, ENT_QUOTES, "UTF-8") . '</title><style>' .
            '.diag{max-width:1100px;margin:30px auto;padding:0 20px;font:16px system-ui,sans-serif}.diag h1{margin-bottom:20px}.diag dl{display:grid;grid-template-columns:160px 1fr;gap:8px 16px}.diag dt{font-weight:600}.diag dd{margin:0;overflow-wrap:anywhere}.diag table{border-collapse:collapse;width:100%;margin-top:12px}.diag th,.diag td{border:1px solid #bbb;padding:6px 8px;text-align:left;vertical-align:top}.diag code{font-family:ui-monospace,SFMono-Regular,Consolas,monospace}' .
            '</style></head><body><main class="diag">';
    }

    private function diagnosticEnd() {
        return '</main></body></html>';
    }

    /**
     * Scan Markdown headings while preserving byte offsets into the original
     * string. ATX headings (# ...), Setext H1/H2, front matter and fenced code
     * blocks are handled here. IDs are structural, not title based.
     */
    public function scan($text) {
        $lines = $this->getLines($text);
        $sections = array();
        $frontMatterEnd = $this->getFrontMatterEnd($lines);
        $fenced = false;
        $fenceChar = "";
        $fenceLength = 0;
        $counters = array(0, 0, 0, 0, 0, 0);

        for ($i = 0; $i < count($lines); $i++) {
            if ($i < $frontMatterEnd) continue;
            $line = $lines[$i]["text"];

            if ($fenced) {
                if (preg_match('/^ {0,3}(' . preg_quote($fenceChar, '/') . '{' . $fenceLength . ',})[ \t]*$/', $line)) {
                    $fenced = false;
                }
                continue;
            }
            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $m)) {
                $fenced = true;
                $fenceChar = $m[1][0];
                $fenceLength = strlen($m[1]);
                continue;
            }

            $heading = $this->parseAtxHeading($line);
            $headingLineCount = 1;
            if (!$heading && $i > 0 && trim($line) !== "" && preg_match('/^ {0,3}(=+|-+)[ \t]*$/', $line)) {
                $prev = $lines[$i - 1]["text"];
                if (trim($prev) !== "") {
                    $heading = array(
                        "level" => $line[0] === "=" ? 1 : 2,
                        "title" => trim($prev),
                        "startLine" => $i - 1,
                    );
                    $headingLineCount = 2;
                }
            }
            if (!$heading) continue;

            $startLine = isset($heading["startLine"]) && $heading["startLine"] !== null ? $heading["startLine"] : $i;
            $heading["startLine"] = $startLine;
            $level = $heading["level"];
            $title = $this->cleanAtxTitle($heading["title"]);
            for ($n = $level; $n < 6; $n++) $counters[$n] = 0;
            $counters[$level - 1]++;
            $idParts = array();
            for ($n = 0; $n < $level; $n++) {
                if ($counters[$n] > 0) $idParts[] = (string)$counters[$n];
            }
            $startLine = $heading["startLine"];
            $sections[] = array(
                "id" => implode(".", $idParts),
                "level" => $level,
                "type" => $headingLineCount === 1 ? "atx" : "setext",
                "title" => $title,
                "startLine" => $startLine,
                "endLine" => null,
                "start" => $lines[$startLine]["start"],
                "end" => strlen($text),
            );
        }

        // A section ends immediately before the next heading of the same or
        // higher level. This is independent of its own level.
        for ($i = 0; $i < count($sections); $i++) {
            $level = $sections[$i]["level"];
            $end = strlen($text);
            $endLine = count($lines);
            for ($j = $i + 1; $j < count($sections); $j++) {
                if ($sections[$j]["level"] <= $level) {
                    $end = $sections[$j]["start"];
                    $endLine = $sections[$j]["startLine"];
                    break;
                }
            }
            $sections[$i]["end"] = $end;
            $sections[$i]["endLine"] = $endLine;
        }
        return $sections;
    }

    private function parseAtxHeading($line) {
        if (!preg_match('/^ {0,3}(#{1,6})(?:[ \t]+|$)(.*)$/', $line, $m)) return null;
        return array(
            "level" => strlen($m[1]),
            "title" => $m[2],
            "startLine" => null,
        );
    }

    private function cleanAtxTitle($title) {
        $title = rtrim($title);
        $title = preg_replace('/[ \t]+#+[ \t]*$/', '', $title);
        return trim($title);
    }

    private function getFrontMatterEnd($lines) {
        if (count($lines) === 0) return 0;
        $first = ltrim($lines[0]["text"], "\xEF\xBB\xBF");
        if (trim($first) !== "---") return 0;
        for ($i = 1; $i < count($lines); $i++) {
            if (trim($lines[$i]["text"]) === "---") return $i + 1;
        }
        return 0;
    }

    private function getLines($text) {
        $lines = array();
        $length = strlen($text);
        $start = 0;
        while ($start < $length) {
            $p = preg_match('/\r\n|\r|\n/', $text, $m, PREG_OFFSET_CAPTURE, $start);
            if (!$p) {
                $lines[] = array("text" => substr($text, $start), "start" => $start, "end" => $length);
                $start = $length;
                break;
            }
            $nlStart = $m[0][1];
            $nlLen = strlen($m[0][0]);
            $lines[] = array(
                "text" => substr($text, $start, $nlStart - $start),
                "start" => $start,
                "end" => $nlStart + $nlLen,
            );
            $start = $nlStart + $nlLen;
        }
        if ($length === 0 || ($length > 0 && preg_match('/\r\n|\r|\n$/', $text))) {
            $lines[] = array("text" => "", "start" => $length, "end" => $length);
        }
        return $lines;
    }

    private function getCss() {
        // Do not define a font here. The active Yellow theme owns typography;
        // SectionEdit must inherit it just like the normal /edit/ page.
        return '.sectionedit-body{font-size:1em;line-height:1.5}.sectionedit{position:relative;display:block;box-sizing:border-box;width:100%;max-width:1100px;margin:1em auto 0;padding:10px;background-color:#fff;color:#000;border:1px solid #bbb;border-radius:4px;box-shadow:2px 4px 10px rgba(0,0,0,.2);text-align:left}.sectionedit form{margin:0}.sectionedit-toolbar-main{margin-left:8px}.sectionedit .yellow-edit-text{box-sizing:border-box;width:100%;min-height:65vh;padding:0 2px;outline:none;resize:none;border:0;background:transparent;color:inherit;font-size:.9em;font-family:inherit;font-weight:normal;line-height:normal;display:block;overflow:auto}.sectionedit .yellow-edit-preview{box-sizing:border-box;width:100%;min-height:65vh;padding:0;overflow:auto;border:0;background:#fff}.sectionedit .yellow-toolbar-btn-icon{cursor:pointer}.sectionedit .yellow-toolbar-btn-icon:hover{cursor:pointer}';
    }
}
