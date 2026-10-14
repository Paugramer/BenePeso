"""Create blank report/import assets without modifying the supplied originals.

Run: python scripts/prepare_phase2_templates.py C:/Users/paulo/Downloads
Only retained header cells and styles are copied from report workbooks; data,
shared strings, people metadata and calculation caches are never published.
"""
from pathlib import Path
import csv
import sys
import zipfile
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'
REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships'
PKG = 'http://schemas.openxmlformats.org/package/2006/relationships'
ET.register_namespace('', NS)
ET.register_namespace('r', REL)

def xml(node):
    return ET.tostring(node, encoding='utf-8', xml_declaration=True)

def package(sheet, styles, theme, label, branded=False):
    files = {
        '_rels/.rels': f'<Relationships xmlns="{PKG}"><Relationship Id="rId1" Type="{REL}/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml': f'<workbook xmlns="{NS}" xmlns:r="{REL}"><sheets><sheet name="{label}" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels': f'<Relationships xmlns="{PKG}"><Relationship Id="rId1" Type="{REL}/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="{REL}/styles" Target="styles.xml"/><Relationship Id="rId3" Type="{REL}/theme" Target="theme/theme1.xml"/></Relationships>',
        'xl/worksheets/sheet1.xml': sheet,
        'xl/styles.xml': styles,
        'xl/theme/theme1.xml': theme,
    }
    overrides = [('workbook.xml', 'spreadsheetml.sheet.main'), ('worksheets/sheet1.xml', 'spreadsheetml.worksheet'), ('styles.xml', 'spreadsheetml.styles'), ('theme/theme1.xml','theme')]
    if branded:
        files['xl/worksheets/_rels/sheet1.xml.rels'] = f'<Relationships xmlns="{PKG}"><Relationship Id="rIdHeader" Type="{REL}/drawing" Target="../drawings/drawing1.xml"/></Relationships>'
        files['xl/drawings/_rels/drawing1.xml.rels'] = f'<Relationships xmlns="{PKG}"><Relationship Id="rId1" Type="{REL}/image" Target="../media/peso_header.png"/></Relationships>'
        # Fit the existing 2200:250 letterhead into the first row.
        files['xl/drawings/drawing1.xml'] = f'<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="{REL}"><xdr:oneCellAnchor><xdr:from><xdr:col>0</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>0</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from><xdr:ext cx="6600000" cy="750000"/><xdr:pic><xdr:nvPicPr><xdr:cNvPr id="1" name="PESO Vinzons letterhead"/><xdr:cNvPicPr/></xdr:nvPicPr><xdr:blipFill><a:blip r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill><xdr:spPr><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/></xdr:oneCellAnchor></xdr:wsDr>'
        files['xl/media/peso_header.png'] = (ROOT/'assets/peso_official_report_header.png').read_bytes()
        overrides.append(('drawings/drawing1.xml','drawing'))
    files['[Content_Types].xml'] = '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/>' + ''.join(f'<Override PartName="/xl/{path}" ContentType="application/vnd.openxmlformats-officedocument.{kind}+xml"/>' for path,kind in overrides) + '</Types>'
    return files

def write_package(path, files):
    with zipfile.ZipFile(path,'w',zipfile.ZIP_DEFLATED) as archive:
        for name, data in files.items(): archive.writestr(name,data)

def blank_report(source, destination, first_data_row, label, sheet_index=1, numbering_column='A', block_size=1):
    with zipfile.ZipFile(source) as archive:
        strings = [''.join(item.itertext()) for item in ET.fromstring(archive.read('xl/sharedStrings.xml')).findall(f'{{{NS}}}si')]
        sheet = ET.fromstring(archive.read(f'xl/worksheets/sheet{sheet_index}.xml'))
        # Remove unsupported/original links, printer binaries, extensions and drawings.
        keep = {'dimension','sheetViews','sheetFormatPr','cols','sheetData','mergeCells','pageMargins','pageSetup','printOptions'}
        for child in list(sheet):
            if child.tag.split('}')[-1] not in keep: sheet.remove(child)
        for element in sheet.iter():
            for attr in list(element.attrib):
                if attr.startswith('{') and not attr.startswith('{'+NS+'}'): del element.attrib[attr]
        data = sheet.find(f'{{{NS}}}sheetData')
        for row in data:
            number = int(row.attrib['r'])
            for cell in row:
                style = cell.attrib.get('s')
                ref = cell.attrib['r']
                if number >= first_data_row:
                    cell.clear(); cell.set('r',ref)
                    if style is not None: cell.set('s',style)
                    if ref.startswith(numbering_column) and ref[len(numbering_column):].isdigit() and (number-first_data_row)%block_size==0:
                        ET.SubElement(cell,f'{{{NS}}}v').text = str((number-first_data_row)//block_size+1)
                elif cell.attrib.get('t') == 's':
                    value = strings[int(cell.find(f'{{{NS}}}v').text)]
                    for child in list(cell): cell.remove(child)
                    cell.set('t','inlineStr'); inline=ET.SubElement(cell,f'{{{NS}}}is');ET.SubElement(inline,f'{{{NS}}}t').text=value
        first = data.find(f'{{{NS}}}row[@r="1"]')
        if first is None: first=ET.Element(f'{{{NS}}}row',{'r':'1'});data.insert(0,first)
        first.set('ht','64');first.set('customHeight','1')
        ET.SubElement(sheet,f'{{{NS}}}drawing',{f'{{{REL}}}id':'rIdHeader'})
        files=package(xml(sheet),archive.read('xl/styles.xml'),archive.read('xl/theme/theme1.xml'),label,True)
    write_package(destination,files)
    print(destination.name+': blank data, original headers/styles, PESO letterhead')

def blank_msme(source):
    output=ROOT/'assets/report_templates/MSME_Report_Blank.xlsx'
    second=ROOT/'assets/report_templates/MSME_Second_Blank.xlsx'
    blank_report(source,output,5,'I-III',numbering_column='B',block_size=10)
    blank_report(source,second,7,'IV-VI',sheet_index=2,block_size=10)
    with zipfile.ZipFile(output) as z: files={n:z.read(n) for n in z.namelist()}
    with zipfile.ZipFile(second) as z:
        files['xl/worksheets/sheet2.xml']=z.read('xl/worksheets/sheet1.xml')
        files['xl/worksheets/_rels/sheet2.xml.rels']=z.read('xl/worksheets/_rels/sheet1.xml.rels')
    files['xl/workbook.xml']=f'<workbook xmlns="{NS}" xmlns:r="{REL}"><sheets><sheet name="I-III" sheetId="1" r:id="rId1"/><sheet name="IV-VI" sheetId="2" r:id="rId4"/></sheets></workbook>'
    files['xl/_rels/workbook.xml.rels']=files['xl/_rels/workbook.xml.rels'].replace(b'</Relationships>',f'<Relationship Id="rId4" Type="{REL}/worksheet" Target="worksheets/sheet2.xml"/></Relationships>'.encode())
    files['[Content_Types].xml']=files['[Content_Types].xml'].replace(b'</Types>',b'<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>')
    write_package(output,files)
    second.unlink()

def blank_import(program):
    sample=ROOT/'sample_import_files'/f'{program}_Import_Sample.csv'
    with sample.open(encoding='utf-8-sig',newline='') as stream: headers=next(csv.reader(stream))
    sheet=ET.Element(f'{{{NS}}}worksheet');data=ET.SubElement(sheet,f'{{{NS}}}sheetData');row=ET.SubElement(data,f'{{{NS}}}row',{'r':'1'})
    for index,header in enumerate(headers,1):
        n=index;col=''
        while n: n,k=divmod(n-1,26);col=chr(65+k)+col
        cell=ET.SubElement(row,f'{{{NS}}}c',{'r':col+'1','t':'inlineStr'});ET.SubElement(ET.SubElement(cell,f'{{{NS}}}is'),f'{{{NS}}}t').text=header
    # Reuse safe styles/theme from the sanitized report, never sample records.
    with zipfile.ZipFile(ROOT/'assets/report_templates/SPES_Report_Blank.xlsx') as z:
        files=package(xml(sheet),z.read('xl/styles.xml'),z.read('xl/theme/theme1.xml'),program+' Import')
    write_package(ROOT/'assets/templates'/f'{program}_Import_Blank.xlsx',files)

if __name__=='__main__':
    source=Path(sys.argv[1])
    blank_report(source/'SPES_report_template.xlsx',ROOT/'assets/report_templates/SPES_Report_Blank.xlsx',4,'SPES BENEFICIARIES')
    blank_report(source/'Tupad_report_template.xlsx',ROOT/'assets/report_templates/TUPAD_Report_Blank.xlsx',11,'TUPAD BENEFICIARIES')
    blank_msme(source/'MSME_Report_template.xlsx')
    for program in ['SPES','TUPAD','MSME']: blank_import(program)
