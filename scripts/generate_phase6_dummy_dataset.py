"""Generate 100 synthetic beneficiaries in the existing import formats; no DB writes."""
from pathlib import Path
import copy
import csv
import datetime as dt
import re
import zipfile
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'deliverables' / 'phase6_dummy_dataset'
NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'
ET.register_namespace('', NS)
ET.register_namespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships')
TODAY = dt.date(2026, 10, 15)
FIRST = ['Ana', 'Miguel', 'Maria', 'Jose', 'Carla', 'Paolo', 'Elena', 'Rafael', 'Sofia', 'Daniel']
LAST = ['Santos', 'Reyes', 'Cruz', 'Garcia', 'Ramos', 'Mendoza', 'Torres', 'Flores', 'Castillo', 'Navarro']
BARANGAYS = ['Sabang', 'Aguit-It', 'Sula', 'Santo Domingo', 'Banocboc', 'Guinacutan', 'Matango', 'Barangay I (Pob.)']


def key(text):
    return re.sub('[^a-z0-9]', '', text.lower())


def sample(program, number):
    first, last = FIRST[(number - 1) % 10], LAST[(number - 1) // 10]
    middle = ['Dela Cruz', 'Aquino', 'Villanueva', 'Mercado'][number % 4]
    age = 18 + number % 7 if program == 'SPES' else 25 + number % 31
    dob = dt.date(TODAY.year - age, 1 + number % 9, 1 + number % 27)
    barangay = BARANGAYS[number % len(BARANGAYS)]
    contact = '09' + str(100000000 + number)
    address = f'Purok {1 + number % 5}, {barangay}, Vinzons, Camarines Norte'
    products = ['Rice and groceries', 'Bread and pastries', 'Fresh fish', 'Vegetables', 'Handwoven bags'][number % 5]
    data = {
        'Last Name': last, 'First Name': first, 'Middle Name': middle,
        'Extension Name': '', 'Owner Full Name': f'{first} {middle} {last}',
        'Sex': 'Female' if number % 2 else 'Male', 'Date of Birth': dob.isoformat(), 'Age': str(age),
        'Civil Status': 'Single' if program == 'SPES' or number % 3 else 'Married',
        'Contact Number': contact, 'Landline/Mobile Number': contact,
        'Business Contact Details': contact, 'Email Address': f'demo{number:03d}@example.invalid',
        'Complete Address': address, 'Permanent Address': address, 'Barangay': barangay,
        'Occupation': ['Vendor', 'Farm worker', 'Construction worker', 'Fisherfolk'][number % 4],
        'Skills': 'Basic carpentry; gardening', 'Special Skills': 'Computer literacy; customer service',
        'Family Income': str(8000 + number % 10 * 500),
        'Estimated Monthly Income': str(5000 + number % 10 * 500), 'Dependents': str(number % 5),
        'ID Type': 'National ID', 'ID Number': f'DEMO-{number:06d}',
        'Type of Beneficiary': 'Underemployed', 'Dependent Relationship': 'Child',
        'Interested in Employment': 'Yes', 'Business/Trade Name': f'DEMO {last} {products.split(" and ")[0]} Store {number:03d}',
        'Type of Ownership': 'Sole Proprietorship', 'Nature of Business': 'Retail',
        'Primary Products Offered': products, 'Product Price': str(25 + number % 10 * 5),
        'Year Business Started': str(2017 + number % 8), 'Business Permit No.': f'DEMO-BP-{number:05d}',
        'Valid Until': '2027-12-31', 'DTI Registration No.': f'DEMO-DTI-{number:05d}',
        'Tax Identification No.': f'DEMO-TIN-{number:05d}', 'Educational Attainment': 'High School Graduate',
        'Work Experience': 'Retail sales and inventory management', 'Business Assets Owned': 'Shelves; weighing scale',
        'Utility Needs': 'Electricity; water', 'Number of Workers': '4', 'Male Employees': '2', 'Female Employees': '2',
        'Regular Employees': '2', 'Seasonal Employees': '1', 'Contractual Employees': '0', 'Family Workers': '1',
        'Skills Needed': 'Bookkeeping; digital marketing', 'Regular Daily Earnings': '650',
        'Current Capital': str(30000 + number * 500), 'Source of Capital': 'Personal Savings',
        'Business Size': 'Micro', 'Initial Capital': '20000', 'Modes of Payment': 'Cash; GCash',
        'Distribution Channels': 'Direct selling', 'Previously Availed Assistance': 'No',
        'Assistance Availed': 'None', 'Past Programs': 'None', 'Programs Needed': 'Entrepreneurship training',
        'Challenges Encountered': 'Limited working capital', 'Place of Birth': 'Vinzons, Camarines Norte',
        'Citizenship': 'Filipino', 'Social Media': '', 'Student Status': 'Student', 'Parent Status': 'Living Together',
        'GSIS Beneficiary/Policy Details': 'None', "Father's Name": f'Roberto {last}',
        "Father's Contact": contact, "Father's Occupation": 'Farm worker',
        "Mother's Name": f'Teresa {last}', "Mother's Contact": contact, "Mother's Occupation": 'Vendor',
        'Elementary School': 'Vinzons Central Elementary School', 'Elementary Honors': 'None',
        'Elementary Level': 'Grade 6', 'Elementary Attendance': '2012-2018',
        'Secondary School': 'Vinzons Pilot High School', 'Secondary Track/Strand': 'General Academic Strand',
        'Secondary Level': 'Grade 12', 'Secondary Attendance': '2018-2024',
        'Tertiary School': 'Camarines Norte State College', 'Tertiary Course': 'BS Information Technology',
        'Tertiary Level': '2nd Year', 'Tertiary Attendance': '2024-2026',
        'Technical/Vocational School': 'None', 'Technical/Vocational Course': 'None',
        'Technical/Vocational Level': 'None', 'Technical/Vocational Attendance': 'None', 'SPES History': 'None',
    }
    return {key(k): v for k, v in data.items()}


def workbook(program, numbers):
    source = ROOT / 'assets' / 'templates' / f'{program}_Import_Blank.xlsx'
    with zipfile.ZipFile(source) as archive:
        files = {name: archive.read(name) for name in archive.namelist()}
    sheet = ET.fromstring(files['xl/worksheets/sheet1.xml'])
    rows = sheet.find(f'{{{NS}}}sheetData')
    header = rows.find(f'{{{NS}}}row')
    strings = []
    if 'xl/sharedStrings.xml' in files:
        strings = [''.join(si.itertext()) for si in ET.fromstring(files['xl/sharedStrings.xml'])]
    headers, columns = [], []
    for cell in header:
        value = ''.join(cell.find(f'{{{NS}}}is').itertext()) if cell.get('t') == 'inlineStr' else cell.findtext(f'{{{NS}}}v', '')
        if cell.get('t') == 's':
            value = strings[int(value)]
        headers.append(value)
        columns.append(re.sub(r'\d+', '', cell.get('r')))
    style_row = list(rows)[1]
    for row in list(rows)[1:]:
        rows.remove(row)
    values = []
    for index, number in enumerate(numbers, 2):
        data = sample(program, number)
        missing = [h for h in headers if key(h) not in data]
        if missing:
            raise ValueError(f'Unmapped template fields: {missing}')
        record = [data[key(h)] for h in headers]
        values.append(record)
        row = copy.deepcopy(style_row)
        row.set('r', str(index))
        for col, cell, value in zip(columns, row, record):
            style = cell.get('s')
            cell.clear()
            cell.set('r', f'{col}{index}')
            if style is not None:
                cell.set('s', style)
            cell.set('t', 'inlineStr')
            ET.SubElement(ET.SubElement(cell, f'{{{NS}}}is'), f'{{{NS}}}t').text = value
        rows.append(row)
    dimension = sheet.find(f'{{{NS}}}dimension')
    if dimension is None:
        dimension = ET.Element(f'{{{NS}}}dimension')
        sheet.insert(0, dimension)
    dimension.set('ref', f'A1:{columns[-1]}{len(values) + 1}')
    files['xl/worksheets/sheet1.xml'] = ET.tostring(sheet, encoding='utf-8', xml_declaration=True)
    with zipfile.ZipFile(OUT / f'{program}_Import.xlsx', 'w', zipfile.ZIP_DEFLATED) as archive:
        for name, data in files.items():
            archive.writestr(name, data)
    with (OUT / f'{program}_Import.csv').open('w', encoding='utf-8-sig', newline='') as handle:
        writer = csv.writer(handle)
        writer.writerow(headers)
        writer.writerows(values)


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    scenarios = []
    groups = [('MSME', range(1, 35)), ('SPES', range(35, 68)), ('TUPAD', range(68, 101))]
    for program, numbers in groups:
        workbook(program, numbers)
        for number in numbers:
            data = sample(program, number)
            approval, stage = [('Accepted', 'Waiting for Requirements'), ('Accepted', 'Documents Submitted'),
                               ('Accepted', 'Orientation'), ('Accepted', 'Ongoing'), ('Accepted', 'Completed'),
                               ('Rejected', 'Waiting for Requirements'), ('Pending', 'Waiting for Requirements')][number % 7]
            scenarios.append([f'DEMO-{number:03d}', program, data['ownerfullname'], data['barangay'], approval, stage])
    with (OUT / 'Workflow_Scenarios.csv').open('w', encoding='utf-8-sig', newline='') as handle:
        writer = csv.writer(handle)
        writer.writerow(['Sample ID', 'Program', 'Full Name', 'Barangay', 'Target Approval', 'Target Workflow Status'])
        writer.writerows(scenarios)
    (OUT / 'README.txt').write_text(
        'SYNTHETIC TEST DATA ONLY - 100 beneficiaries\n'
        'MSME: 34; SPES: 33; TUPAD: 33. Generated as of 2026-10-15.\n\n'
        'Each XLSX uses the existing import template, header order, widths and styles.\n'
        'CSV files contain the SAME records: choose XLSX OR CSV; do not import both.\n'
        'No database records were created. Names, IDs, addresses and contacts are fictional.\n'
        'Email addresses use example.invalid and cannot receive mail.\n\n'
        'Import into a TEST copy using the matching program/batch.\n'
        'Workflow_Scenarios.csv is a reference, NOT an import file. Approval and workflow\n'
        'statuses are controlled by the application, not by extra spreadsheet columns.\n'
        'Admin imports are Accepted; staff imports are Pending. The import modal sets\n'
        'the workflow status for the entire batch. Split/filter records if needed.\n'
        'For checklist/workflow testing, follow legal transitions and verify requirements\n'
        'before advancing; use the application approval controls for Pending/Rejected.\n'
        'Completed records appear in Report; active records appear in Application.\n', encoding='utf-8')
    with zipfile.ZipFile(OUT / 'Beneficiary_Test_Dataset_100.zip', 'w', zipfile.ZIP_DEFLATED) as archive:
        for name in ['README.txt', 'Workflow_Scenarios.csv'] + [f'{p}_Import.{e}' for p, _ in groups for e in ['xlsx', 'csv']]:
            archive.write(OUT / name, name)
    print('Generated 100 synthetic beneficiaries (34 MSME, 33 SPES, 33 TUPAD); database untouched.')


if __name__ == '__main__':
    main()
