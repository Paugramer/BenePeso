<?php

function xlsxXml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function xlsxColumnName(int $number): string
{
    $name = '';
    while ($number > 0) {
        $number--;
        $name = chr(65 + ($number % 26)) . $name;
        $number = intdiv($number, 26);
    }
    return $name;
}

function xlsxInlineCell(string $reference, string $value, int $style): string
{
    return '<c r="' . $reference . '" t="inlineStr" s="' . $style . '"><is><t xml:space="preserve">' . xlsxXml($value) . '</t></is></c>';
}

function outputNativeXlsxReport(string $programName, array $columns, array $rows, string $subtitle): void
{
    $headerImagePath = __DIR__ . '/assets/peso_official_report_header.png';
    $columnCount = count($columns) + 1;
    $lastColumn = xlsxColumnName($columnCount);
    $sheetRows = [];

    $totalColumnWidth = 7.0;
    $columnXml = '<col min="1" max="1" width="7" customWidth="1"/>';
    foreach ($columns as $index => $column) {
        $width = min(34, max(12, strlen($column['label']) * 0.9));
        if (in_array($column['key'], ['complete_address', 'primary_products', 'remarks', 'work_experience'], true)) $width = 30;
        $position = $index + 2;
        $totalColumnWidth += $width;
        $columnXml .= '<col min="' . $position . '" max="' . $position . '" width="' . $width . '" customWidth="1"/>';
    }

    // Match the letterhead drawing to the actual width of all exported table columns.
    $headerWidthPixels = $totalColumnWidth * 7 + 5;
    $headerWidthEmu = (int) round($headerWidthPixels * 9525);
    $headerHeightEmu = min((int) round($headerWidthEmu * 250 / 2200), 1371600);
    $headerRowHeight = max(36, round(($headerHeightEmu / 12700) / 3, 2));
    $sheetZoom = max(40, min(100, (int) floor((1400 / max(1, $headerWidthPixels)) * 100)));

    $sheetRows[] = '<row r="1" ht="' . $headerRowHeight . '" customHeight="1"></row>';
    $sheetRows[] = '<row r="2" ht="' . $headerRowHeight . '" customHeight="1"></row>';
    $sheetRows[] = '<row r="3" ht="' . $headerRowHeight . '" customHeight="1"></row>';
    $sheetRows[] = '<row r="4" ht="28" customHeight="1">' . xlsxInlineCell('A4', $programName, 1) . '</row>';
    $sheetRows[] = '<row r="5" ht="20" customHeight="1">' . xlsxInlineCell('A5', $subtitle, 2) . '</row>';
    $sheetRows[] = '<row r="6" ht="8" customHeight="1"></row>';

    $headerCells = xlsxInlineCell('A7', 'No.', 3);
    foreach ($columns as $index => $column) {
        $headerCells .= xlsxInlineCell(xlsxColumnName($index + 2) . '7', $column['label'], 3);
    }
    $sheetRows[] = '<row r="7" ht="32" customHeight="1">' . $headerCells . '</row>';

    foreach ($rows as $rowIndex => $row) {
        $excelRow = $rowIndex + 8;
        $style = $rowIndex % 2 === 0 ? 4 : 5;
        $cells = xlsxInlineCell('A' . $excelRow, (string)($rowIndex + 1), $style);
        foreach ($columns as $columnIndex => $column) {
            $cells .= xlsxInlineCell(xlsxColumnName($columnIndex + 2) . $excelRow, getReportCellValue($row, $column['key']), $style);
        }
        $sheetRows[] = '<row r="' . $excelRow . '">' . $cells . '</row>';
    }

    $lastRow = max(7, count($rows) + 7);
    $worksheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheetViews><sheetView workbookViewId="0" zoomScale="' . $sheetZoom . '" zoomScaleNormal="100"><selection activeCell="A1" sqref="A1"/></sheetView></sheetViews>
  <sheetFormatPr defaultRowHeight="18"/>
  <cols>' . $columnXml . '</cols>
  <sheetData>' . implode('', $sheetRows) . '</sheetData>
  <autoFilter ref="A7:' . $lastColumn . $lastRow . '"/>
  <mergeCells count="2"><mergeCell ref="A4:' . $lastColumn . '4"/><mergeCell ref="A5:' . $lastColumn . '5"/></mergeCells>
  <drawing r:id="rId1"/>
  <pageMargins left="0.25" right="0.25" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>
  <pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0" paperSize="9"/>
</worksheet>';

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="3">
    <font><sz val="10"/><name val="Calibri"/><family val="2"/></font>
    <font><b/><color rgb="FFFFFFFF"/><sz val="16"/><name val="Calibri"/></font>
    <font><b/><color rgb="FFFFFFFF"/><sz val="10"/><name val="Calibri"/></font>
  </fonts>
  <fills count="5">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF176B45"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF21845B"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFF3F8F5"/><bgColor indexed="64"/></patternFill></fill>
  </fills>
  <borders count="2">
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border><left style="thin"><color rgb="FFB8C8BF"/></left><right style="thin"><color rgb="FFB8C8BF"/></right><top style="thin"><color rgb="FFB8C8BF"/></top><bottom style="thin"><color rgb="FFB8C8BF"/></bottom><diagonal/></border>
  </borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="6">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="49" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="49" fontId="0" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
  </cellXfs>
  <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets><sheet name="Beneficiary Report" sheetId="1" r:id="rId1"/></sheets>
  <calcPr calcId="191029"/>
</workbook>';

    $drawing = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><xdr:oneCellAnchor><xdr:from><xdr:col>0</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>0</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from><xdr:ext cx="' . $headerWidthEmu . '" cy="' . $headerHeightEmu . '"/><xdr:pic><xdr:nvPicPr><xdr:cNvPr id="1" name="PESO Vinzons Official Header"/><xdr:cNvPicPr/></xdr:nvPicPr><xdr:blipFill><a:blip r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill><xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $headerWidthEmu . '" cy="' . $headerHeightEmu . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/></xdr:oneCellAnchor></xdr:wsDr>';

    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>',
        'xl/workbook.xml' => $workbook,
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/worksheets/sheet1.xml' => $worksheet,
        'xl/worksheets/_rels/sheet1.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>',
        'xl/drawings/drawing1.xml' => $drawing,
        'xl/drawings/_rels/drawing1.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/peso_official_report_header.png"/></Relationships>',
        'xl/styles.xml' => $styles,
        'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . xlsxXml($programName) . ' Beneficiary Report</dc:title><dc:creator>BENEPESO</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>',
        'docProps/app.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>BENEPESO</Application></Properties>',
    ];

    $temporaryPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'benepeso_report_' . bin2hex(random_bytes(8)) . '.zip';
    try {
        $archive = new PharData($temporaryPath, 0, null, Phar::ZIP);
        foreach ($files as $path => $content) $archive->addFromString($path, $content);
        if (is_file($headerImagePath)) $archive->addFile($headerImagePath, 'xl/media/peso_official_report_header.png');
        unset($archive);

        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $programName);
        $filename = $safeName . '_Beneficiary_Report_' . date('Ymd') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($temporaryPath));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        readfile($temporaryPath);
    } finally {
        if (is_file($temporaryPath)) unlink($temporaryPath);
    }
    exit();
}
