<?php
require_once __DIR__.'/historical_report_helper.php';
require_once __DIR__.'/report_template_layout.php';
require_once __DIR__.'/msme_template_export.php';

/** Populate the sanitized template without changing its column order or styles. */
function build_official_template_report(string $program, array $rows): string {
    if (stripos($program,'MSME')!==false) return build_msme_template_report($rows);
    $spes=stripos($program,'SPES')!==false;
    $source=__DIR__.'/assets/report_templates/'.($spes?'SPES':'TUPAD').'_Report_Blank.xlsx';
    $firstData=$spes?4:11;
    $columns=official_report_columns($program);
    $temporary=sys_get_temp_dir().DIRECTORY_SEPARATOR.'benepeso_template_'.bin2hex(random_bytes(8)).'.zip';
    if (!copy($source,$temporary)) throw new RuntimeException('The report template could not be opened.');
    try {
        $archive=new PharData($temporary,0,null,Phar::ZIP);
        $doc=new DOMDocument();$doc->loadXML($archive['xl/worksheets/sheet1.xml']->getContent(),LIBXML_NONET);
        $ns='http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $xpath=new DOMXPath($doc);$xpath->registerNamespace('m',$ns);
        $data=$xpath->query('/m:worksheet/m:sheetData')->item(0);
        $styles=[];
        foreach($xpath->query('m:row[@r="'.$firstData.'"]/m:c',$data) as $cell) {
            $styles[preg_replace('/\d+/','',$cell->getAttribute('r'))]=$cell->getAttribute('s');
        }
        foreach(iterator_to_array($xpath->query('m:row',$data)) as $row) if ((int)$row->getAttribute('r') >= $firstData) $data->removeChild($row);
        foreach($rows as $index=>$record) {
            $number=$firstData+$index;$row=$doc->createElementNS($ns,'row');$row->setAttribute('r',(string)$number);
            $values=[(string)($index+1)];
            foreach($columns as $column) $values[]=official_report_value($record,$column['key']);
            foreach($values as $position=>$value) {
                $column=xlsxColumnName($position+1);$cell=$doc->createElementNS($ns,'c');$cell->setAttribute('r',$column.$number);$cell->setAttribute('t','inlineStr');
                if (($styles[$column] ?? '')!=='') $cell->setAttribute('s',$styles[$column]);
                $inline=$doc->createElementNS($ns,'is');$text=$doc->createElementNS($ns,'t');$text->setAttributeNS('http://www.w3.org/XML/1998/namespace','xml:space','preserve');
                // XML 1.0 excludes these control characters, even in text nodes.
                $text->appendChild($doc->createTextNode(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u','',(string)$value)));
                $inline->appendChild($text);$cell->appendChild($inline);$row->appendChild($cell);
            }
            $data->appendChild($row);
        }
        $lastColumn=xlsxColumnName(count($columns)+1);$lastRow=max($firstData-1,$firstData+count($rows)-1);
        $dimension=$xpath->query('/m:worksheet/m:dimension')->item(0);
        if ($dimension) $dimension->setAttribute('ref','A1:'.$lastColumn.$lastRow);
        $setup=$xpath->query('/m:worksheet/m:pageSetup')->item(0);
        if ($setup) { $setup->setAttribute('orientation','landscape');$setup->setAttribute('fitToWidth','1');$setup->setAttribute('fitToHeight','0'); }
        if (!$spes && $rows) {
            // The export carries only the selected location; no beneficiary names in headings.
            $barangays=array_values(array_unique(array_column($rows,'barangay')));
            if (count($barangays)===1) {
                $row=$xpath->query('/m:worksheet/m:sheetData/m:row[@r="7"]')->item(0);
                if ($row) { $cell=$doc->createElementNS($ns,'c');$cell->setAttribute('r','C7');$cell->setAttribute('t','inlineStr');$inline=$doc->createElementNS($ns,'is');$text=$doc->createElementNS($ns,'t');$text->appendChild($doc->createTextNode($barangays[0]));$inline->appendChild($text);$cell->appendChild($inline);$row->appendChild($cell); }
            }
        }
        $archive['xl/worksheets/sheet1.xml']=$doc->saveXML();
        normalize_report_workbook($archive,$program);
        unset($archive);
        return $temporary;
    } catch (Throwable $e) { unset($archive);if(is_file($temporary)) unlink($temporary);throw $e; }
}

function output_official_template_report(string $program, array $rows): void {
    $path=build_official_template_report($program,$rows);
    try {
        $name=(stripos($program,'SPES')!==false?'SPES':(stripos($program,'MSME')!==false?'MSME':'TUPAD')).'_Report_'.date('Ymd').'.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$name.'"');
        header('Content-Length: '.filesize($path));header('Cache-Control: private, no-store');readfile($path);
    } finally { if(is_file($path)) unlink($path); }
    exit;
}
