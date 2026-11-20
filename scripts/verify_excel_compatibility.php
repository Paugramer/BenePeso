<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../report_columns.php';
require_once __DIR__ . '/../xlsx_report_helper.php';
require_once __DIR__ . '/../official_template_export.php';

// Exercise the actual binary response, with nested output from earlier includes.
if (($argv[1] ?? '') === '--native') {
    ob_start(); echo "\xEF\xBB\xBF unwanted buffered output";
    ob_start(); echo 'more buffered output';
    outputNativeXlsxReport('Compatibility Test', [
        ['key'=>'full_name','label'=>'Name'], ['key'=>'contact_no','label'=>'Contact'],
    ], ($argv[2] ?? '') === 'empty' ? [] : [
        ['full_name'=>"TEST & <Name> José\x01\xEF\xBF\xBE", 'contact_no'=>'09123456789'],
    ], 'Synthetic records only');
}

function excel_check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

function excel_validate(string $path): void {
    $stream=fopen($path,'rb'); $signature=fread($stream,4); fclose($stream);
    excel_check($signature === "PK\x03\x04", 'Workbook contains output before the ZIP signature');
    $zip=new PharData($path,0,null,Phar::ZIP);
    $ns='http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    // Worksheet elements have a required schema order, even when XML parses.
    $order=array_flip(['sheetPr','dimension','sheetViews','sheetFormatPr','cols','sheetData',
        'sheetCalcPr','sheetProtection','protectedRanges','scenarios','autoFilter','sortState',
        'dataConsolidate','customSheetViews','mergeCells','phoneticPr','conditionalFormatting',
        'dataValidations','hyperlinks','printOptions','pageMargins','pageSetup','headerFooter',
        'rowBreaks','colBreaks','customProperties','cellWatches','ignoredErrors','smartTags',
        'drawing','legacyDrawing','legacyDrawingHF','picture','oleObjects','controls',
        'webPublishItems','tableParts','extLst']);
    foreach (new RecursiveIteratorIterator($zip) as $entry) {
        $name=substr(str_replace('\\','/',$entry->getPathname()),strlen('phar://'.str_replace('\\','/',$path))+1);
        if (!preg_match('/\.(xml|rels)$/',$name)) continue;
        $doc=new DOMDocument();
        excel_check($doc->loadXML($entry->getContent(),LIBXML_NONET), 'Malformed XML: '.$name);
        if (!preg_match('#^xl/worksheets/sheet\d+\.xml$#',$name)) continue;
        $previous=-1;
        foreach ($doc->documentElement->childNodes as $node) {
            if (!$node instanceof DOMElement) continue;
            excel_check(isset($order[$node->localName]), 'Unknown worksheet element: '.$node->localName);
            $position=$order[$node->localName];
            excel_check($position >= $previous, 'Worksheet elements out of order: '.$name.' / '.$node->localName);
            $previous=$position;
        }
        $xp=new DOMXPath($doc); $xp->registerNamespace('m',$ns);
        foreach ($xp->query('/m:worksheet/m:sheetData/m:row') as $row) {
            $column=0;
            foreach ($xp->query('m:c',$row) as $cell) {
                preg_match('/^([A-Z]+)(\d+)$/',$cell->getAttribute('r'),$ref);
                excel_check(isset($ref[2]) && (int)$ref[2]===(int)$row->getAttribute('r'), 'Invalid cell reference');
                $current=report_column_number($ref[1]);
                excel_check($current>$column,'Cells out of order: '.$name.' / '.$cell->getAttribute('r'));
                $column=$current;
            }
        }
    }
    unset($zip);
}

$sample=['first_name'=>'SYNTHETIC', 'last_name'=>'TEST', 'full_name'=>"SYNTHETIC José\x01\xEF\xBF\xBE",
    'barangay'=>'Alpha','contact_no'=>'09123456789','id_number'=>'001234',
    'business_name'=>'SYNTHETIC BUSINESS','approval_status'=>'Approved',
    'availment_status'=>'Completed', 'birthdate'=>'2001-02-03',
    'primary_products'=>json_encode(array_map(static fn($n)=>'Product '.$n,range(1,12)))];
foreach (['SPES','TUPAD','MSME'] as $program) {
    foreach ([[],[$sample],[$sample,$sample]] as $rows) {
        foreach (['All','Alpha'] as $barangay) {
            $path=build_official_template_report($program,$rows,$barangay);
            try { excel_validate($path); } finally { unlink($path); }
        }
    }
    echo $program . ": empty, one/multiple records and location filters passed\n";
}
foreach (['empty','populated'] as $mode) {
    $path=tempnam(sys_get_temp_dir(),'benepeso_excel_');
    try {
        $process=proc_open([PHP_BINARY,__FILE__,'--native',$mode], [
            0=>['pipe','r'], 1=>['file',$path,'wb'], 2=>['pipe','w'],
        ],$pipes);
        excel_check(is_resource($process),'Could not start native export verification');
        fclose($pipes[0]); $errors=stream_get_contents($pipes[2]); fclose($pipes[2]);
        excel_check(proc_close($process)===0,'Native export failed: '.$errors);
        excel_check($errors==='','Native export emitted a warning: '.$errors);
        excel_validate($path);
        $zip=new PharData($path,0,null,Phar::ZIP);
        $sheet=$zip['xl/worksheets/sheet1.xml']->getContent();
        if ($mode==='populated') {
            excel_check(strpos($sheet,'09123456789')!==false,'Phone lost leading zero');
            excel_check(strpos($sheet,'José')!==false,'Unicode name was corrupted');
        }
        unset($zip);
    } finally { unlink($path); }
}
echo "Native selected-column exports: schema order, XML text and clean binary output passed\n";
