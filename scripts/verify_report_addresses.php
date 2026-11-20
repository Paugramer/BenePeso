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
foreach([
    [$record,'Purok 5'],
    [['street_purok_zone'=>'House 1, Purok 2, Aguit-It, Vinzons, Camarines Norte','barangay'=>'Aguit-It'],'House 1, Purok 2'],
    [['street_purok_zone'=>'House 1 Purok 2 Aguit It Vinzons Camarines Norte','barangay'=>'Aguit-It'],'House 1 Purok 2'],
    [['street_purok_zone'=>'Camarines Norte Road','barangay'=>'Aguit-It'],'Camarines Norte Road'],
    [['street_purok_zone'=>'Purok 2, San Jose 10','barangay'=>'San Jose 1'],'Purok 2, San Jose 10'],
    [['street_purok_zone'=>'Peñafrancia Street','barangay'=>'Peñafrancia'],'Peñafrancia Street'],
    [['street_purok_zone'=>'Aguit-It, Vinzons, Camarines Norte','barangay'=>'Aguit-It'],''],
] as [$row,$expected]){
    if(getReportCellValue($row,'street_purok_zone')!==$expected)throw new RuntimeException('St./Zone was not separated correctly');
}
foreach([
    [['municipality'=>'Vinzons','district'=>'Camarines Norte'],'2nd District'],
    [['municipality'=>'Vinzons','district'=>'1st District'],'2nd District'],
    [[], '2nd District'],
    [['municipality'=>'Other Municipality','district'=>'First District'],'1st District'],
    [['municipality'=>'Other Municipality','district'=>'District 2'],'2nd District'],
    [['municipality'=>'Other Municipality','district'=>'Camarines Norte'],''],
] as [$row,$expected]){
    if(getReportCellValue($row,'district')!==$expected)throw new RuntimeException('Province was mistaken for a district');
}
foreach(['MSME'=>'V5','SPES'=>'H4','TUPAD'=>'I11'] as $program=>$reference){
    $path=build_official_template_report($program,[$record]);
    try{
        $archive=new PharData($path,0,null,Phar::ZIP);
        $doc=new DOMDocument();$doc->loadXML($archive['xl/worksheets/sheet1.xml']->getContent(),LIBXML_NONET);
        $xp=new DOMXPath($doc);$xp->registerNamespace('m','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $actual=$xp->evaluate('string(//m:c[@r="'.$reference.'"]/m:is/m:t)');
        $expected=$program==='TUPAD'?'Purok 5':$cases[0][1];
        if($actual!==$expected)throw new RuntimeException($program.' workbook address was repeated');
        if($program==='TUPAD')foreach(['J11'=>'Aguit-It','K11'=>'Vinzons','L11'=>'Camarines Norte','M11'=>'2nd District'] as $cell=>$value){
            if($xp->evaluate('string(//m:c[@r="'.$cell.'"]/m:is/m:t)')!==$value)throw new RuntimeException('Wrong TUPAD location column: '.$cell);
        }
        unset($archive);
    }finally{unlink($path);}
    $preview=report_template_sheets($program,[$record]);
    if(strpos($preview[0]['html'],$expected)===false)throw new RuntimeException($program.' preview address differs from export');
}
echo "Complete-address, St./Zone and district cases passed; TUPAD/SPES/MSME workbook and preview values verified.\n";
