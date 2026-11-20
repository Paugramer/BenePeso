<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../report_columns.php';
require_once __DIR__.'/../xlsx_report_helper.php';
require_once __DIR__.'/../official_template_export.php';

$cases=[
    [['street_purok_zone'=>'Purok 5, Aguit-It, Vinzons, Camarines Norte','barangay'=>'Aguit-It'], 'Purok 5, Aguit-It, Vinzons, Camarines Norte'],
    [['street_purok_zone'=>'Purok 5','barangay'=>'Aguit-It'], 'Purok 5, Aguit-It, Vinzons, Camarines Norte'],
    [['street_purok_zone'=>'Purok 5, Aguit-It','barangay'=>'Aguit-It'], 'Purok 5, Aguit-It, Vinzons, Camarines Norte'],
    [['street_purok_zone'=>'Purok 5, Aguit It, VINZONS, CAMARINES NORTE','barangay'=>'Aguit-It'], 'Purok 5, Aguit It, VINZONS, CAMARINES NORTE'],
    [['street_purok_zone'=>'Purok 5 Aguit-It Vinzons Camarines Norte','barangay'=>'Aguit-It'], 'Purok 5 Aguit-It Vinzons Camarines Norte'],
    [['street_purok_zone'=>'Purok 5, Peñafrancia','barangay'=>'Peñafrancia'], 'Purok 5, Peñafrancia, Vinzons, Camarines Norte'],
    [['street_purok_zone'=>'House 10, San Jose 10','barangay'=>'San Jose 1'], 'House 10, San Jose 10, San Jose 1, Vinzons, Camarines Norte'],
    [['street_purok_zone'=>'House 2','barangay'=>'Sample','municipality'=>'Daet','province'=>'Camarines Norte'], 'House 2, Sample, Daet, Camarines Norte'],
    [['street_purok_zone'=>'Street 1','barangay'=>'Sample','municipality'=>'Naga','province'=>'Camarines Sur'], 'Street 1, Sample, Naga, Camarines Sur'],
    [['address'=>'Official stored address','street_purok_zone'=>'Purok 5','barangay'=>'Aguit-It'], 'Official stored address'],
    [['street_purok_zone'=>'   ','barangay'=>'Aguit-It'], 'Aguit-It, Vinzons, Camarines Norte'],
    [[], 'Vinzons, Camarines Norte'],
];
foreach($cases as [$record,$expected]){
    foreach([getReportCellValue($record,'complete_address'),official_report_value($record,'complete_address')] as $actual){
        if($actual!==$expected)throw new RuntimeException('Address formatting mismatch: '.$actual);
    }
}
$record=$cases[0][0]+['first_name'=>'SYNTHETIC','last_name'=>'TEST','business_name'=>'SYNTHETIC BUSINESS'];
foreach(['MSME'=>'V5','SPES'=>'H4'] as $program=>$reference){
    $path=build_official_template_report($program,[$record]);
    try{
        $archive=new PharData($path,0,null,Phar::ZIP);
        $doc=new DOMDocument();$doc->loadXML($archive['xl/worksheets/sheet1.xml']->getContent(),LIBXML_NONET);
        $xp=new DOMXPath($doc);$xp->registerNamespace('m','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $actual=$xp->evaluate('string(//m:c[@r="'.$reference.'"]/m:is/m:t)');
        if($actual!==$cases[0][1])throw new RuntimeException($program.' workbook address was repeated');
        unset($archive);
    }finally{unlink($path);}
    $preview=report_template_sheets($program,[$record]);
    if(strpos($preview[0]['html'],$cases[0][1])===false)throw new RuntimeException($program.' preview address differs from export');
}
echo "12 address cases passed; MSME/SPES workbook and preview addresses contain each location once.\n";
