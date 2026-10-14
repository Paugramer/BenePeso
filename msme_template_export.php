<?php
/** MSME has two sheets and ten-row product/employment blocks per business. */
function build_msme_template_report(array $records): string {
    $temporary=sys_get_temp_dir().DIRECTORY_SEPARATOR.'benepeso_msme_'.bin2hex(random_bytes(8)).'.zip';
    if(!copy(__DIR__.'/assets/report_templates/MSME_Report_Blank.xlsx',$temporary)) throw new RuntimeException('MSME template unavailable.');
    try {
        $archive=new PharData($temporary,0,null,Phar::ZIP);
        $ns='http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $firstKeys=['business_name','ownership_type','business_nature','primary_products','product_price','year_started','business_permit_no','permit_validity','dti_no','tin_no','contact_no','business_email','business_social_media','owner_name','owner_contact','sex','birthdate','age','civil_status','complete_address','educational_attainment','work_experience','nm_stall_no','nm_date_started','assets_owned','utility_needs'];
        $secondKeys=['hr_skills','source_of_capital','business_size','initial_capital','current_capital','daily_earnings','nm_two_day_earnings','nm_total_earnings','mode_of_payment','distribution_channels','availed_before','assistance_availed','past_programs','programs_needed','challenges_encountered'];
        foreach([1=>5,2=>7] as $sheet=>$firstData) {
            $path='xl/worksheets/sheet'.$sheet.'.xml';$doc=new DOMDocument();$doc->loadXML($archive[$path]->getContent(),LIBXML_NONET);
            $xpath=new DOMXPath($doc);$xpath->registerNamespace('m',$ns);$data=$xpath->query('/m:worksheet/m:sheetData')->item(0);
            if($sheet===1){
                $header=$xpath->query('m:row[@r="4"]',$data)->item(0);$cell=$xpath->query('m:c[@r="B4"]',$header)->item(0);
                if(!$cell){$cell=$doc->createElementNS($ns,'c');$cell->setAttribute('r','B4');$header->insertBefore($cell,$header->firstChild);}
                foreach(iterator_to_array($cell->childNodes) as $child)$cell->removeChild($child);
                $cell->setAttribute('t','inlineStr');$inline=$doc->createElementNS($ns,'is');$text=$doc->createElementNS($ns,'t','No.');$inline->appendChild($text);$cell->appendChild($inline);
            }

            $prototype=[];
            for($offset=0;$offset<10;$offset++) $prototype[]=$xpath->query('m:row[@r="'.($firstData+$offset).'"]',$data)->item(0)->cloneNode(true);
            foreach(iterator_to_array($xpath->query('m:row',$data)) as $row) if((int)$row->getAttribute('r') >= $firstData) $data->removeChild($row);
            $merges=$xpath->query('/m:worksheet/m:mergeCells')->item(0);$patterns=[];
            foreach(iterator_to_array($xpath->query('m:mergeCell',$merges)) as $merge) {
                preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/',$merge->getAttribute('ref'),$parts);
                if((int)$parts[2]>=$firstData) {
                    if((int)$parts[2]<$firstData+10 && (int)$parts[4]<$firstData+10) $patterns[]=[$parts[1],(int)$parts[2]-$firstData,$parts[3],(int)$parts[4]-$firstData];
                    $merges->removeChild($merge);
                }
            }
            $cursor=$firstData;
            foreach($records as $index=>$record) {
                $products=official_report_list($record['primary_products'] ?? '');$prices=official_report_list($record['product_price'] ?? '');
                $height=max(10,count($products),count($prices));$blockRows=[];
                for($offset=0;$offset<$height;$offset++) {
                    $row=$prototype[min($offset,9)]->cloneNode(true);$number=$cursor+$offset;$row->setAttribute('r',(string)$number);
                    foreach($xpath->query('m:c',$row) as $cell) $cell->setAttribute('r',preg_replace('/\d+/','',$cell->getAttribute('r')).$number);
                    $data->appendChild($row);$blockRows[]=$row;
                }
                $write=static function(string $column,int $offset,string $value) use($doc,$xpath,$ns,$blockRows,$cursor): void {
                    $ref=$column.($cursor+$offset);$row=$blockRows[$offset];$cell=$xpath->query('m:c[@r="'.$ref.'"]',$row)->item(0);
                    if(!$cell) { $cell=$doc->createElementNS($ns,'c');$cell->setAttribute('r',$ref);$row->appendChild($cell); }
                    foreach(iterator_to_array($cell->childNodes) as $child) $cell->removeChild($child);
                    $cell->setAttribute('t','inlineStr');$inline=$doc->createElementNS($ns,'is');$text=$doc->createElementNS($ns,'t');$text->setAttributeNS('http://www.w3.org/XML/1998/namespace','xml:space','preserve');
                    $text->appendChild($doc->createTextNode(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u','',$value)));$inline->appendChild($text);$cell->appendChild($inline);
                };
                if($sheet===1) {
                    $write('B',0,(string)($index+1));
                    foreach($firstKeys as $position=>$key) if(!in_array($key,['primary_products','product_price'],true)) $write(xlsxColumnName($position+3),0,official_report_value($record,$key));
                    foreach($products as $offset=>$value) $write('F',$offset,$value);
                    foreach($prices as $offset=>$value) $write('G',$offset,$value);
                } else {
                    $write('A',0,(string)($index+1));$write('B',0,official_report_value($record,'report_workers'));
                    foreach(['emp_regular'=>['Regular',0],'emp_seasonal'=>['Seasonal',2],'emp_contractual'=>['Contractual',5],'emp_family'=>['Family',7]] as $key=>[$label,$offset]) {
                        $write('C',$offset,$label);$write('D',$offset,isset($record[$key])?(string)$record[$key]:'');
                    }
                    foreach($secondKeys as $position=>$key) $write(xlsxColumnName($position+5),0,official_report_value($record,$key));
                }
                foreach($patterns as [$left,$start,$right,$end]) {
                    if($end===9) $end=$height-1;
                    $merge=$doc->createElementNS($ns,'mergeCell');$merge->setAttribute('ref',$left.($cursor+$start).':'.$right.($cursor+$end));$merges->appendChild($merge);
                }
                $cursor+=$height;
            }
            $merges->setAttribute('count',(string)$merges->getElementsByTagNameNS($ns,'mergeCell')->length);
            $dimension=$xpath->query('/m:worksheet/m:dimension')->item(0);if($dimension)$dimension->setAttribute('ref','A1:'.($sheet===1?'AC':'Z').max($firstData-1,$cursor-1));
            $archive[$path]=$doc->saveXML();
        }
        normalize_report_workbook($archive,'MSME');
        unset($archive);return $temporary;
    } catch(Throwable $e) { unset($archive);if(is_file($temporary))unlink($temporary);throw $e; }
}
