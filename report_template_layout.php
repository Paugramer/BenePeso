<?php
/** Shared workbook presentation and browser rendering of the actual export template. */
function normalize_report_workbook(PharData $archive, string $program): void {
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    foreach (['xl/worksheets/sheet1.xml','xl/worksheets/sheet2.xml'] as $path) {
        if (!isset($archive[$path])) continue;
        $doc = new DOMDocument(); $doc->loadXML($archive[$path]->getContent(), LIBXML_NONET);
        $xp = new DOMXPath($doc); $xp->registerNamespace('m',$ns);
        foreach ($xp->query('//m:sheetView') as $view) {
            $view->setAttribute('view','normal'); $view->setAttribute('topLeftCell','A1');
            $view->setAttribute('zoomScale','100'); $view->setAttribute('zoomScaleNormal','100');
            if($path==='xl/worksheets/sheet1.xml')$view->setAttribute('tabSelected','1');else $view->removeAttribute('tabSelected');
            foreach ($xp->query('m:selection',$view) as $selection) {
                $selection->setAttribute('activeCell','A1'); $selection->setAttribute('sqref','A1');
            }
        }
        $row = $xp->query('//m:row[@r="1"]')->item(0);
        if ($row) { $row->setAttribute('ht','110'); $row->setAttribute('customHeight','1'); }
        $headerRow=stripos($program,'MSME')!==false?($path==='xl/worksheets/sheet1.xml'?4:6):(stripos($program,'SPES')!==false?3:10);
        $header=$xp->query('//m:row[@r="'.$headerRow.'"]')->item(0);
        if($header){$header->setAttribute('ht',(string)max((float)$header->getAttribute('ht'),stripos($program,'SPES')!==false?60:90));$header->setAttribute('customHeight','1');}
        $archive[$path] = $doc->saveXML();
    }
    foreach (['xl/drawings/drawing1.xml','xl/drawings/drawing2.xml'] as $path) {
        if (!isset($archive[$path])) continue;
        $doc = new DOMDocument(); $doc->loadXML($archive[$path]->getContent(),LIBXML_NONET);
        $xp = new DOMXPath($doc);
        foreach ($xp->query('//*[local-name()="ext" and @cx and @cy]') as $extent) {
            $extent->setAttribute('cx','11430000'); $extent->setAttribute('cy','1298864');
        }
        $archive[$path] = $doc->saveXML();
    }
    $doc=new DOMDocument();$doc->loadXML($archive['xl/workbook.xml']->getContent(),LIBXML_NONET);
    $xp=new DOMXPath($doc);$xp->registerNamespace('m',$ns);
    foreach($xp->query('//m:workbookView') as $view){$view->setAttribute('activeTab','0');$view->setAttribute('firstSheet','0');}
    $archive['xl/workbook.xml']=$doc->saveXML();
}

function report_column_number(string $name): int {
    $value=0; foreach(str_split($name) as $letter) $value=$value*26+ord($letter)-64;
    return $value;
}

/** Render workbook cells, original header groups, merges and colors; omit its logo row. */
function report_template_sheets(string $program, array $records): array {
    $path=build_official_template_report($program,$records);
    try {
        $archive=new PharData($path,0,null,Phar::ZIP);
        $styleDoc=new DOMDocument();$styleDoc->loadXML($archive['xl/styles.xml']->getContent(),LIBXML_NONET);
        $sx=new DOMXPath($styleDoc);$sx->registerNamespace('m','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $styles=[];
        foreach($sx->query('/m:styleSheet/m:cellXfs/m:xf') as $xf) {
            $font=$sx->query('/m:styleSheet/m:fonts/m:font')->item((int)$xf->getAttribute('fontId'));
            $fill=$sx->query('/m:styleSheet/m:fills/m:fill')->item((int)$xf->getAttribute('fillId'));
            $color=$fill ? $sx->evaluate('string(m:patternFill/m:fgColor/@rgb)',$fill) : '';
            $styles[]='background:'.(preg_match('/^[A-Fa-f0-9]{8}$/',$color)?'#'.substr($color,2):'#fff').';font-weight:'.($font && $sx->query('m:b',$font)->length?'700':'400').';';
        }
        $sheets=[];$isMsme=stripos($program,'MSME')!==false;$isSpes=stripos($program,'SPES')!==false;
        foreach([1,2] as $sheet) {
            $entry='xl/worksheets/sheet'.$sheet.'.xml';if(!isset($archive[$entry]))continue;
            $doc=new DOMDocument();$doc->loadXML($archive[$entry]->getContent(),LIBXML_NONET);
            $xp=new DOMXPath($doc);$xp->registerNamespace('m','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $first=$isMsme?($sheet===1?3:5):($isSpes?3:9);
            $dataStart=$isMsme?($sheet===1?5:7):($isSpes?4:11);
            $lastColumn=$isMsme?($sheet===1?28:19):($isSpes?22:28);
            $firstColumn=$isMsme && $sheet===1?2:1;
            $hidden=[];foreach($xp->query('//m:cols/m:col[@hidden="1"]') as $col) for($c=(int)$col->getAttribute('min');$c<=(int)$col->getAttribute('max');$c++)$hidden[$c]=true;
            $columns=[];for($c=$firstColumn;$c<=$lastColumn;$c++)if(!isset($hidden[$c]))$columns[]=$c;
            $cells=[];$last=$dataStart-1;
            foreach($xp->query('//m:sheetData/m:row') as $row) {
                $r=(int)$row->getAttribute('r');if($r<$first)continue;$last=max($last,$r);
                foreach($xp->query('m:c',$row) as $cell) {
                    preg_match('/^([A-Z]+)([0-9]+)$/',$cell->getAttribute('r'),$parts);
                    $c=report_column_number($parts[1]);
                    $value=$cell->getAttribute('t')==='inlineStr'?$xp->evaluate('string(m:is)',$cell):$xp->evaluate('string(m:v)',$cell);
                    $cells[$r][$c]=['text'=>trim($value),'style'=>$styles[(int)$cell->getAttribute('s')]??''];
                }
            }
            $merges=[];$covered=[];
            foreach($xp->query('//m:mergeCell') as $merge) {
                if(!preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/',$merge->getAttribute('ref'),$m))continue;
                $left=report_column_number($m[1]);$right=report_column_number($m[3]);$top=(int)$m[2];$bottom=(int)$m[4];if($top<$first)continue;
                $visible=array_values(array_filter($columns,static fn($c)=>$c>=$left&&$c<=$right));if(!$visible)continue;
                $merges[$top][$visible[0]]=[$visible,count($visible),$bottom-$top+1];
                for($r=$top;$r<=$bottom;$r++)foreach($visible as $c)if($r!==$top||$c!==$visible[0])$covered[$r][$c]=true;
            }
            $html='';
            if(!$isMsme && !$isSpes){
                $html='<dl class="report-template-metadata">';
                foreach([3,4,5,6,7] as $metaRow){
                    $label=$xp->evaluate('string(//m:row[@r="'.$metaRow.'"]/m:c[@r="A'.$metaRow.'"]/m:is)');
                    $value=$xp->evaluate('string(//m:row[@r="'.$metaRow.'"]/m:c[@r="C'.$metaRow.'"]/m:is)');
                    if($value==='')$value=$xp->evaluate('string(//m:row[@r="'.$metaRow.'"]/m:c[@r="C'.$metaRow.'"]/m:v)');
                    $html.='<div><dt>'.htmlspecialchars($label,ENT_QUOTES,'UTF-8').'</dt><dd>'.htmlspecialchars($value,ENT_QUOTES,'UTF-8').'</dd></div>';
                }
                $html.='</dl>';
            }
            $html.='<table class="spreadsheet-table official-template-table"><colgroup>';
            foreach($columns as $index=>$c)$html.='<col data-template-column="'.$index.'">';
            $html.='</colgroup><thead>';$headerLabels=[];
            for($r=$first;$r<=$last;$r++) {
                if($r===$dataStart)$html.='</thead><tbody>';
                $html.='<tr>';
                foreach($columns as $index=>$c) {
                    if(isset($covered[$r][$c]))continue;$cell=$cells[$r][$c]??['text'=>'','style'=>''];
                    if($isMsme&&$sheet===1&&$r===4&&$c===2)$cell['text']='No.';
                    $merge=$merges[$r][$c]??[[$c],1,1];$start=array_search($merge[0][0],$columns,true);$end=$start+$merge[1]-1;
                    $tag=$r<$dataStart?'th':'td';
                    $html.='<'.$tag.' data-column-start="'.$start.'" data-column-end="'.$end.'" colspan="'.$merge[1].'" rowspan="'.$merge[2].'" style="'.htmlspecialchars($cell['style'],ENT_QUOTES,'UTF-8').'">'.htmlspecialchars(preg_replace('/[ \t]{2,}/',' ',$cell['text']),ENT_QUOTES,'UTF-8').'</'.$tag.'>';
                    if($r===$dataStart-1)$headerLabels[]=$cell['text'];
                }
                $html.='</tr>';
            }
            if($last<$dataStart)$html.='</thead><tbody><tr><td colspan="'.count($columns).'">No records match these filters.</td></tr>';
            $html.='</tbody></table>';
            $definitions=official_report_columns($program);$keys=[];
            foreach($columns as $c){
                if($isMsme && $sheet===1)$key=$c===2?'_number':($definitions[$c-3]['key']??'');
                elseif($isMsme)$key=$c===1?'_number':($definitions[$c===2?26:($c<=4?27:$c+23)]['key']??'');
                else $key=$c===1?'_number':($definitions[$c-2]['key']??'');
                $keys[]=$key;
            }
            $sheets[]=['name'=>$isMsme?($sheet===1?'I–III: Business & Owner':'IV–VII: Workers & Financial Information'):$program,'html'=>$html,'column_count'=>count($columns),'headers'=>$headerLabels,'column_keys'=>$keys];
        }
        return $sheets;
    } finally { unset($archive);if(is_file($path))unlink($path); }
}
