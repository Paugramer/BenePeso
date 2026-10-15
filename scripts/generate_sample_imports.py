"""Generate six distinct, fully populated demonstration workbooks. Never writes to DB."""
from pathlib import Path
import copy
import datetime as dt
import zipfile
import xml.etree.ElementTree as ET
import generate_phase6_dummy_dataset as generator

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'deliverables' / 'sample_imports'
NS = generator.NS
EXTRA = ['Night Market Stall/Booth No.', 'Date Started', 'Night Market Two-Day Earnings',
         'Night Market Total Earnings', 'Website/Social Media', 'Email Address', 'Owner Contact Number']
FIRST = ['Isabel', 'Antonio', 'Beatriz', 'Gabriel', 'Camille', 'Luis', 'Diana', 'Marco', 'Patricia', 'Enrique']
LAST = ['Bautista', 'Fernandez', 'Villanueva', 'Domingo', 'Aguilar', 'Salazar', 'Valdez', 'Rosales',
        'Cabrera', 'Soriano', 'Dizon', 'Marquez', 'Tolentino', 'Pascual', 'Alcantara']
original_sample = generator.sample


def column(number):
    result = ''
    while number:
        number, remainder = divmod(number - 1, 26)
        result = chr(65 + remainder) + result
    return result


def complete_templates():
    for program in ['MSME', 'TUPAD']:
        path = ROOT / 'assets' / 'templates' / f'{program}_Import_Blank.xlsx'
        with zipfile.ZipFile(path) as archive:
            files = {name: archive.read(name) for name in archive.namelist()}
        sheet = ET.fromstring(files['xl/worksheets/sheet1.xml'])
        rows = sheet.find(f'{{{NS}}}sheetData')
        header = list(rows)[0]
        labels = [''.join(cell.itertext()) for cell in header]
        if program == 'TUPAD':
            for cell, label in zip(header, labels):
                if label == 'Dependents':
                    cell.find(f'{{{NS}}}is/{{{NS}}}t').text = 'Dependent Name'
        else:
            for label in EXTRA:
                if label in labels:
                    continue
                index = len(header) + 1
                for row in rows:
                    cell = copy.deepcopy(list(row)[-1])
                    cell.set('r', column(index) + row.get('r'))
                    for child in list(cell):
                        cell.remove(child)
                    if row.get('r') == '1':
                        cell.set('t', 'inlineStr')
                        ET.SubElement(ET.SubElement(cell, f'{{{NS}}}is'), f'{{{NS}}}t').text = label
                    else:
                        cell.attrib.pop('t', None)
                    row.append(cell)
                cols = sheet.find(f'{{{NS}}}cols')
                ET.SubElement(cols, f'{{{NS}}}col', {'min': str(index), 'max': str(index), 'width': '28', 'customWidth': '1'})
                labels.append(label)
        dimension = sheet.find(f'{{{NS}}}dimension')
        if dimension is not None:
            dimension.set('ref', 'A1:' + column(len(header)) + str(len(rows)))
        files['xl/worksheets/sheet1.xml'] = ET.tostring(sheet, encoding='utf-8', xml_declaration=True)
        with zipfile.ZipFile(path, 'w', zipfile.ZIP_DEFLATED) as archive:
            for name, data in files.items():
                archive.writestr(name, data)


def sample(program, number):
    data = original_sample(program, (number - 1) % 100 + 1)
    first, last = FIRST[(number - 1) % 10], LAST[(number - 1) // 10]
    middle = ['Lopez', 'Velasco', 'Serrano', 'Medina'][number % 4]
    age = 18 + number % 5 if program == 'SPES' else 26 + number % 29
    born = dt.date(2026 - age, 1 + number % 9, 1 + number % 27)
    email = f'sample{number:03d}@example.invalid'
    phone = '09' + str(600000000 + number)
    values = {
        'First Name': first, 'Last Name': last, 'Middle Name': middle, 'Extension Name': 'None',
        'Owner Full Name': f'{first} {middle} {last}', 'Date of Birth': born.isoformat(), 'Age': str(age),
        'Sex': 'Female' if number % 2 else 'Male', 'Email Address': email,
        'Contact Number': phone, 'Landline/Mobile Number': phone, 'Owner Contact Number': phone,
        'Business Contact Details': email, 'Business/Trade Name': f'{last} Community Store {number:03d}',
        'Dependents': f'{FIRST[(number + 2) % 10]} {middle} {last}',
        'Dependent Name': f'{FIRST[(number + 2) % 10]} {middle} {last}',
        'Dependent Relationship': 'Child', 'ID Number': f'SAMPLE-ID-{number:06d}',
        'Business Permit No.': f'SAMPLE-BP-{number:06d}', 'DTI Registration No.': f'SAMPLE-DTI-{number:06d}',
        'Tax Identification No.': f'SAMPLE-TIN-{number:06d}', 'Night Market Stall/Booth No.': f'NM-{number:03d}',
        'Date Started': f'2025-{1 + number % 9:02d}-01', 'Night Market Two-Day Earnings': str(1800 + number * 10),
        'Night Market Total Earnings': str(25000 + number * 100),
        'Website/Social Media': f'https://example.invalid/store/{number}', 'Social Media': f'https://example.invalid/student/{number}',
        'GSIS Beneficiary/Policy Details': f'Roberto {last} / SAMPLE-POLICY-{number:03d}',
        "Father's Name": f'Roberto {middle} {last}', "Mother's Name": f'Teresa {middle} {last}',
        "Father's Contact": '09' + str(700000000 + number), "Mother's Contact": '09' + str(800000000 + number),
        'Elementary Attendance': f'{born.year + 6}-{born.year + 12}',
        'Secondary Attendance': f'{born.year + 12}-{born.year + 18}',
        'Tertiary Attendance': f'{born.year + 18}-2026', 'Tertiary Level': str(min(4, age - 17)) + ' Year',
        'Technical/Vocational School': 'Vinzons Community Training Center',
        'Technical/Vocational Course': 'Computer Systems Servicing', 'Technical/Vocational Level': 'NC II',
        'Technical/Vocational Attendance': '2026-01 to 2026-03',
    }
    data.update({generator.key(k): v for k, v in values.items()})
    # Explicit "None" is used for genuinely inapplicable fields, never unexplained blank cells.
    return {key: value if value != '' else 'None' for key, value in data.items()}


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    complete_templates()
    generator.OUT = OUT
    generator.sample = sample
    names = []
    for index, program in enumerate(['MSME', 'SPES', 'TUPAD']):
        for batch in [1, 2]:
            first = index * 50 + (batch - 1) * 25 + 1
            generator.workbook(program, range(first, first + 25))
            destination = OUT / f'Sample{batch}_{program.lower()}.xlsx'
            (OUT / f'{program}_Import.xlsx').replace(destination)
            # The deliverable contains exactly two workbooks per program, not duplicate CSV copies.
            (OUT / f'{program}_Import.csv').unlink()
            names.append(destination.name)
    (OUT / 'README.txt').write_text(
        'SYNTHETIC DEMONSTRATION DATA ONLY\n'
        'Six XLSX files: Sample1 and Sample2 for MSME, SPES and TUPAD.\n'
        '25 beneficiaries per file; 150 distinct beneficiaries overall. No shared records between files.\n'
        'Every template column is filled. None means not applicable. TUPAD dependent values are people names.\n'
        'MSME files include stall number, date started, earnings and contact/social details.\n'
        'Names, IDs and contacts are fictional. Emails use example.invalid and cannot receive mail.\n'
        'Use a TEST batch in the matching program. No database records were seeded.\n'
        'Approval and workflow status come from the application and selected batch status.\n'
        'To test duplicates, re-upload the SAME file to the SAME batch/status after the first import.\n'
        'The modal reports the actual unchanged/closed count; if blocked, the whole import is not saved.\n'
        'Active records with changed details may be updated; they are counted as updated, not unchanged duplicates.\n', encoding='utf-8')
    with zipfile.ZipFile(OUT / 'Sample_Import_Files.zip', 'w', zipfile.ZIP_DEFLATED) as archive:
        for name in names + ['README.txt']:
            archive.write(OUT / name, name)
    print('Created six distinct workbooks: 25 complete records each, 150 unique beneficiaries. No DB writes.')


if __name__ == '__main__':
    main()
