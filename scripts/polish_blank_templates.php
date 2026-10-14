<?php
/** Apply presentation fixes to downloadable workbooks without changing field order or values. */
require_once __DIR__.'/../report_template_layout.php';
$ns='http://schemas.openxmlformats.org/spreadsheetml/2006/main';
$files=['assets/report_templates/MSME_Report_Blank.xlsx'=>'MSME','assets/report_templates/SPES_Report_Blank.xlsx'=>'SPES','assets/report_templates/TUPAD_Report_Blank.xlsx'=>'TUPAD','assets/templates/MSME_Import_Blank.xlsx'=>'MSME','assets/templates/SPES_Import_Blank.xlsx'=>'SPES','assets/templates/TUPAD_Import_Blank.xlsx'=>'TUPAD'];
foreach($files as$relative=>$program){$path=__DIR__.'/../'.$relative;$temporary=__DIR__.'/../storage/phase2_validation/polish_template_'.bin2hex(random_bytes(6)).'.zip';copy($path,$temporary);$zip=new PharData($temporary,0,null,Phar::ZIP);$report=strpos($relative,'report_templates')!==false;
if($report)normalize_report_workbook($zip,$program);
$doc=new DOMDocument();$doc->loadXML($zip['xl/styles.xml']->getContent(),LIBXML_NONET);$xp=new DOMXPath($doc);$xp->registerNamespace('m',$ns);
foreach($xp->query('//m:cellXfs/m:xf')as$xf){$alignment=$xp->query('m:alignment',$xf)->item(0);if(!$alignment){$alignment=$doc->createElementNS($ns,'alignment');$xf->appendChild($alignment);}$alignment->setAttribute('wrapText','1');$alignment->setAttribute('vertical','center');$xf->setAttribute('applyAlignment','1');}
$zip['xl/styles.xml']=$doc->saveXML();$shared=[];
if(isset($zip['xl/sharedStrings.xml'])){$doc=new DOMDocument();$doc->loadXML($zip['xl/sharedStrings.xml']->getContent(),LIBXML_NONET);$xp=new DOMXPath($doc);$xp->registerNamespace('m',$ns);foreach($xp->query('//m:si')as$si)$shared[]=$si->textContent;}
for($sheet=1;$sheet<=2;$sheet++){$entry='xl/worksheets/sheet'.$sheet.'.xml';if(!isset($zip[$entry]))continue;$doc=new DOMDocument();$doc->loadXML($zip[$entry]->getContent(),LIBXML_NONET);$xp=new DOMXPath($doc);$xp->registerNamespace('m',$ns);$data=$xp->query('//m:sheetData')->item(0);$headerRow=$report?($program==='MSME'?($sheet===1?4:6):($program==='SPES'?3:10)):1;$headers=[];$maxCol=0;
foreach($xp->query('m:row[@r="'.$headerRow.'"]/m:c',$data)as$cell){preg_match('/^([A-Z]+)/',$cell->getAttribute('r'),$parts);$col=report_column_number($parts[1]);$type=$cell->getAttribute('t');$text=$type==='inlineStr'?$xp->evaluate('string(m:is)',$cell):$xp->evaluate('string(m:v)',$cell);if($type==='s')$text=$shared[(int)$text]??'';$headers[$col]=$text;$maxCol=max($maxCol,$col);}
$hidden=[];foreach($xp->query('//m:cols/m:col[@hidden="1"]')as$col)for($n=(int)$col->getAttribute('min');$n<=(int)$col->getAttribute('max');$n++)$hidden[$n]=true;
$cols=$xp->query('//m:cols')->item(0);if(!$cols){$cols=$doc->createElementNS($ns,'cols');$data->parentNode->insertBefore($cols,$data);}while($cols->firstChild)$cols->removeChild($cols->firstChild);
for($n=1;$n<=$maxCol;$n++){$text=strtolower($headers[$n]??'');$width=22;if(preg_match('/^no\.?$|^age$/',trim($text)))$width=8;elseif(trim($text)==='sex')$width=10;elseif(strpos($text,'address')!==false)$width=34;elseif(strpos($text,'birth')!==false)$width=20;elseif(strlen($text)>42)$width=30;elseif(strpos($text,'name')!==false||strpos($text,'business')!==false)$width=26;elseif(strlen($text)<13)$width=18;if($report&&$program==='MSME'&&$n===($sheet===1?2:1))$width=8;
$col=$doc->createElementNS($ns,'col');foreach(['min'=>$n,'max'=>$n,'width'=>$width,'customWidth'=>1]as$key=>$value)$col->setAttribute($key,(string)$value);if(isset($hidden[$n]))$col->setAttribute('hidden','1');$cols->appendChild($col);}
$row=$xp->query('//m:row[@r="'.$headerRow.'"]')->item(0);if($row){$row->setAttribute('ht',$report?'72':'66');$row->setAttribute('customHeight','1');}
foreach($xp->query('//m:sheetView')as$view){$view->setAttribute('view','normal');$view->setAttribute('zoomScale','100');$view->setAttribute('zoomScaleNormal','100');}
if($report&&$program==='TUPAD'){$merges=$xp->query('//m:mergeCells')->item(0);if($merges){foreach(iterator_to_array($xp->query('m:mergeCell',$merges))as$merge)if(preg_match('/^[A-Z]+([3-7]):[A-Z]+([3-7])$/',$merge->getAttribute('ref')))$merges->removeChild($merge);foreach(range(3,7)as$r)foreach(['A'.$r.':B'.$r,'C'.$r.':AB'.$r]as$ref){$merge=$doc->createElementNS($ns,'mergeCell');$merge->setAttribute('ref',$ref);$merges->appendChild($merge);}$merges->setAttribute('count',(string)$merges->childNodes->length);}}
$zip[$entry]=$doc->saveXML();}
unset($zip);copy($temporary,$path);unlink($temporary);echo 'Updated workbook: '.$relative.PHP_EOL;
}
