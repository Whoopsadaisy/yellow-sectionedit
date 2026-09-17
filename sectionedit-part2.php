<?php
trait YellowSectioneditPart2 {
function processRequest($scheme, $address, $base, $location, $fileName) {
        $edit = $this->yellow->extension->isExisting("edit") ?
            $this->yellow->extension->get("edit") : null;
        if (!$edit) {
            $this->yellow->page->error(500, "Section edit requires the edit extension!");
            return $this->yellow->processRequestError();
        }
        if (!$edit->checkUserAuth($scheme, $address, $base, $location, $fileName)) return $this->yellow->sendStatus(403);
        if (!$edit->response->isUserAccess("edit", $location)) return $this->yellow->sendStatus(403);
        if (!$this->yellow->lookup->isContentFile($fileName)) return $this->showFileDiagnostic($location, $fileName);
        $rawDataSource = $this->yellow->toolbox->readFile($fileName);
        $endOfLine = $edit->response->getPageData($this->yellow->page);
        $endOfLine = isset($endOfLine["rawDataEndOfLine"]) ? $endOfLine["rawDataEndOfLine"] : "auto";
        if ($_SERVER["REQUEST_METHOD"] === "POST") return $this->saveSection($edit, $scheme, $address, $base, $location, $fileName, $rawDataSource, $endOfLine);
        return $this->showEditor($edit, $scheme, $address, $base, $location, $fileName, $rawDataSource, $endOfLine);
    }
function getSectionId() {
        $id = trim($this->yellow->page->getRequest("section"));
        return preg_match('/^[1-9][0-9]*(?:\.[1-9][0-9]*)*$/', $id) ? $id : "";
    }
function saveSection($edit, $scheme, $address, $base, $location, $fileName, $rawDataSource, $endOfLine) {
        $sectionId = $this->getSectionId();
        $sectionEdit = isset($_POST["sectiondata"]) && is_string($_POST["sectiondata"]) ? $_POST["sectiondata"] : "";
        $postedSource = isset($_POST["rawdatasource"]) && is_string($_POST["rawdatasource"]) ? $_POST["rawdatasource"] : "";
        $postedEol = isset($_POST["rawdataendofline"]) && is_string($_POST["rawdataendofline"]) ? $_POST["rawdataendofline"] : $endOfLine;
        if ($postedSource === "") { $this->yellow->page->error(400, "Missing source snapshot."); return $this->yellow->processRequestError(); }
        $sections = $this->scan($postedSource);
        $section = $this->findSection($sections, $sectionId);
        if (!$section) { $this->yellow->page->error(409, "Section no longer exists in the source snapshot."); return $this->yellow->processRequestError(); }
        $rawDataEdit = substr($postedSource, 0, $section["start"]) . $sectionEdit . substr($postedSource, $section["end"]);
        $page = $edit->response->getPageEdit($scheme, $address, $base, $location, $fileName, $postedSource, $rawDataEdit, $rawDataSource, $postedEol);
        if ($page->isError()) return $this->yellow->processRequestError();
        $rawData = $page->rawData;
        if (!$this->yellow->toolbox->writeFile($page->fileName, $rawData, true)) { $this->yellow->page->error(500, "Can't write file '" . $page->fileName . "'!"); return $this->yellow->processRequestError(); }
        $redirect = $this->yellow->lookup->normaliseUrl($scheme, $address, $base, $page->location);
        $anchor = $this->getSectionAnchor($rawData, $sectionId);
        if (!is_string_empty($anchor)) $redirect .= "#" . rawurlencode($anchor);
        return $this->yellow->sendStatus(303, $redirect);
    }
}
