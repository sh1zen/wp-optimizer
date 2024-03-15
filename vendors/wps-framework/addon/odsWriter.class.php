<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core\addon;

use WPS\core\UtilEnv;

class odsWriter
{
    private \ZipArchive $zipFile;
    private ?\DOMDocument $dom;
    private array $pages = [];
    private ?\DOMElement $current_page;
    private ?\DOMElement $spreadsheet;
    private string $archive_file;

    /**
     * @throws \DOMException
     * @throws \Exception
     */
    public function __construct()
    {
        // Create the basic ODS file structure
        $this->zipFile = new \ZipArchive();

        $this->archive_file = UtilEnv::unique_filename(__DIR__ . '/tmp/output.ods');

        if ($this->zipFile->open($this->archive_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \Exception("Cannot create ODS file");
        }

        $this->build_content();
    }

    private function build_content()
    {
        $this->resetDom();

        $root = $this->dom->createElement('office:document-content');
        $root->setAttribute('xmlns:table', 'urn:oasis:names:tc:opendocument:xmlns:office:1.0');
        $root->setAttribute('xmlns:table', 'urn:oasis:names:tc:opendocument:xmlns:table:1.0');
        $root->setAttribute('xmlns:office', 'urn:oasis:names:tc:opendocument:xmlns:office:1.0');
        $root->setAttribute('xmlns:text', 'urn:oasis:names:tc:opendocument:xmlns:text:1.0');
        $root->setAttribute('xmlns:style', 'urn:oasis:names:tc:opendocument:xmlns:style:1.0');
        $root->setAttribute('xmlns:draw', 'urn:oasis:names:tc:opendocument:xmlns:drawing:1.0');
        $root->setAttribute('xmlns:fo', 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0');
        $root->setAttribute('xmlns:xlink', 'http://www.w3.org/1999/xlink');
        $root->setAttribute('xmlns:dc', 'http://purl.org/dc/elements/1.1/');
        $root->setAttribute('xmlns:number', 'urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0');
        $root->setAttribute('xmlns:svg', 'urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0');
        $root->setAttribute('xmlns:of', 'urn:oasis:names:tc:opendocument:xmlns:of:1.2');
        $root->setAttribute('office:version', '1.2');
        $this->dom->appendChild($root);

        $fontFaceDecls = $this->dom->createElement('office:font-face-decls');

        // Create the <style:font-face> element and its attributes
        $fontFace = $this->dom->createElement('style:font-face');
        $fontFace->setAttribute('style:name', 'Calibri');
        $fontFace->setAttribute('svg:font-family', 'Calibri');

        // Append the <style:font-face> element to the <office:font-face-decls> element
        $fontFaceDecls->appendChild($fontFace);

        // Create the root element <office:body>
        $body = $this->dom->createElement('office:body');

        // Create the <office:spreadsheet> element
        $this->spreadsheet = $this->dom->createElement('office:spreadsheet');

        // Create the <table:calculation-settings> element and its attributes
        $calculationSettings = $this->dom->createElement('table:calculation-settings');
        $calculationSettings->setAttribute('table:case-sensitive', 'false');
        $calculationSettings->setAttribute('table:search-criteria-must-apply-to-whole-cell', 'true');
        $calculationSettings->setAttribute('table:use-wildcards', 'true');
        $calculationSettings->setAttribute('table:use-regular-expressions', 'false');
        $calculationSettings->setAttribute('table:automatic-find-labels', 'false');

        // Append the <table:calculation-settings> element to the <office:spreadsheet> element
        $this->spreadsheet->appendChild($calculationSettings);

        // Append the <office:spreadsheet> element to the <office:body> element
        $body->appendChild($this->spreadsheet);

        // Append the <office:body> element to the DOMDocument
        $root->appendChild($body);
    }

    private function resetDom()
    {
        $this->dom = new \DOMDocument();
        $this->dom->formatOutput = true;
        $this->dom->xmlStandalone = true;
    }

    public static function getInstance(): odsWriter
    {
        return new self();
    }

    public function import(array $rows): odsWriter
    {
        $this->set_page('Foglio1');

        foreach ($rows as $row) {

            try {
                $this->addRow($row);
            } catch (\DOMException $e) {
                continue;
            }
        }
        return $this;
    }

    public function set_page($name)
    {
        if (isset($this->pages[$name])) {
            $this->current_page = $this->pages[$name];
            return $this->current_page;
        }

        $this->current_page = $this->dom->createElement('table:table');
        $this->current_page->setAttribute('table:name', $name);
        $this->current_page->setAttribute('table:style-name', 'ta1');

        $this->spreadsheet->appendChild($this->current_page);

        $this->pages[$name] = $this->current_page;

        return $this->current_page;
    }

    /**
     * @throws \DOMException
     */
    public function addRow($rowData)
    {
        // Create the root element
        $row = $this->dom->createElement('table:table-row');
        $row->setAttribute('table:style-name', 'ro1');

        foreach ($rowData as $value) {

            $cel = $this->dom->createElement('table:table-cell');
            $cel->setAttribute('office:value-type', 'string');
            $cel->setAttribute('table:style-name', 'ce1');

            $cel_value = $this->dom->createElement('text:p', htmlspecialchars($value));
            $cel->appendChild($cel_value);

            $row->appendChild($cel);
        }

        $this->current_page->appendChild($row);
    }

    public function export()
    {
        try {
            $tmpFilename = $this->save();
        } catch (\Exception $e) {
            return '';
        }

        $content = file_get_contents($tmpFilename);
        @unlink($tmpFilename);

        return $content;
    }

    /**
     * @throws \Exception
     */
    public function save(): string
    {
        $success = true;

        $success &= $this->zipFile->addFromString("content.xml", $this->dom->saveXML());

        // Create metafile
        $success &= $this->zipFile->addFromString("meta.xml", $this->getMetaXML());

        // Create mimetype
        $success &= $this->zipFile->addFromString('mimetype', 'application/vnd.oasis.opendocument.spreadsheet');

        // Create manifest.xml
        $success &= $this->zipFile->addFromString('META-INF/manifest.xml', $this->getManifestXml());

        // Create styles.xml
        $success &= $this->zipFile->addFromString('styles.xml', $this->getStylesXml());

        if (!$success) {
            throw new \Exception("Cannot create ODS file");
        }

        $this->zipFile->close();

        return $this->archive_file;
    }

    private function getMetaXML()
    {
        $this->resetDom();

        // Create the root element <office:document-meta>
        $documentMeta = $this->dom->createElement('office:document-meta');
        $documentMeta->setAttribute('xmlns:office', 'urn:oasis:names:tc:opendocument:xmlns:office:1.0');
        $documentMeta->setAttribute('xmlns:meta', 'urn:oasis:names:tc:opendocument:xmlns:meta:1.0');
        $documentMeta->setAttribute('xmlns:dc', 'http://purl.org/dc/elements/1.1/');
        $documentMeta->setAttribute('xmlns:xlink', 'http://www.w3.org/1999/xlink');
        $documentMeta->setAttribute('office:version', '1.2');

        // Create the <office:meta> element
        $meta = $this->dom->createElement('office:meta');

        // Create and append child elements to <office:meta>
        $generator = $this->dom->createElement('meta:generator', 'WPS-odsWriter/1.0');
        $meta->appendChild($generator);

        $initialCreator = $this->dom->createElement('meta:initial-creator', get_option('blogname'));
        $meta->appendChild($initialCreator);

        $dcCreator = $this->dom->createElement('dc:creator', get_option('blogname'));
        $meta->appendChild($dcCreator);

        $creationDate = $this->dom->createElement('meta:creation-date', wps_time('Y-m-d\TH:i:s\Z'));
        $meta->appendChild($creationDate);

        $dcDate = $this->dom->createElement('dc:date', wps_time('Y-m-d\TH:i:s\Z'));
        $meta->appendChild($dcDate);

        // Append the <office:meta> element to <office:document-meta>
        $documentMeta->appendChild($meta);

        // Append the <office:document-meta> element to the DOMDocument
        $this->dom->appendChild($documentMeta);

        return $this->dom->saveXML();
    }

    private function getManifestXML()
    {
        $this->resetDom();

        // Create the root element <manifest:manifest>
        $manifest = $this->dom->createElementNS('urn:oasis:names:tc:opendocument:xmlns:manifest:1.0', 'manifest:manifest');

        // Create file entries
        $fileEntries = [
            ['/', 'application/vnd.oasis.opendocument.spreadsheet'],
            ['styles.xml', 'text/xml'],
            ['content.xml', 'text/xml'],
            ['meta.xml', 'text/xml']
        ];

        // Create and append file entry elements to the manifest
        foreach ($fileEntries as $entry) {
            $fileEntry = $this->dom->createElement('manifest:file-entry');
            $fileEntry->setAttribute('manifest:full-path', $entry[0]);
            $fileEntry->setAttribute('manifest:media-type', $entry[1]);
            $manifest->appendChild($fileEntry);
        }

        // Append the <manifest:manifest> element to the DOMDocument
        $this->dom->appendChild($manifest);

        return $this->dom->saveXML();
    }

    private function getStylesXml()
    {
        $this->resetDom();

        // Create the root element <office:document-styles>
        $documentStyles = $this->dom->createElement('office:document-styles');
        $documentStyles->setAttribute('xmlns:table', 'urn:oasis:names:tc:opendocument:xmlns:table:1.0');
        $documentStyles->setAttribute('xmlns:office', 'urn:oasis:names:tc:opendocument:xmlns:office:1.0');
        $documentStyles->setAttribute('xmlns:text', 'urn:oasis:names:tc:opendocument:xmlns:text:1.0');
        $documentStyles->setAttribute('xmlns:style', 'urn:oasis:names:tc:opendocument:xmlns:style:1.0');
        $documentStyles->setAttribute('xmlns:draw', 'urn:oasis:names:tc:opendocument:xmlns:drawing:1.0');
        $documentStyles->setAttribute('xmlns:fo', 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0');
        $documentStyles->setAttribute('xmlns:xlink', 'http://www.w3.org/1999/xlink');
        $documentStyles->setAttribute('xmlns:dc', 'http://purl.org/dc/elements/1.1/');
        $documentStyles->setAttribute('xmlns:number', 'urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0');
        $documentStyles->setAttribute('xmlns:svg', 'urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0');
        $documentStyles->setAttribute('xmlns:of', 'urn:oasis:names:tc:opendocument:xmlns:of:1.2');
        $documentStyles->setAttribute('office:version', '1.2');

        // Append the <office:document-styles> element to the DOMDocument
        $this->dom->appendChild($documentStyles);

        return $this->dom->saveXML();
    }
}