<?php
require_once __DIR__.'/../report_template_layout.php';
foreach(['MSME','SPES','TUPAD']as$program){$file=__DIR__.'/../assets/report_templates/'.$program.'_Report_Blank.xlsx';$temp=__DIR__.'/../storage/phase2_validation/center_'.bin2hex(random_bytes(6)).'.zip';copy($file,$temp);$zip=new PharData($temp,0,null,Phar::ZIP);normalize_report_workbook($zip,$program);unset($zip);copy($temp,$file);unlink($temp);echo 'Centered original letterhead: '.$program.PHP_EOL;}
