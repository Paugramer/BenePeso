param(
    [Parameter(Mandatory = $true)][string]$DataPath,
    [Parameter(Mandatory = $true)][string]$TemplatePath,
    [Parameter(Mandatory = $true)][string]$OutputDocx,
    [Parameter(Mandatory = $true)][string]$OutputPdf,
    [Parameter(Mandatory = $true)][ValidateSet('2', '2a')][string]$Form
)

$ErrorActionPreference = 'Stop'

function Add-FormText {
    param(
        [Parameter(Mandatory = $true)]$Document,
        [AllowEmptyString()][string]$Text,
        [double]$Left,
        [double]$Top,
        [double]$Width,
        [double]$Height = 12,
        [double]$FontSize = 8,
        [bool]$Bold = $false,
        [int]$Alignment = 0
    )

    if ([string]::IsNullOrWhiteSpace($Text)) {
        return
    }

    $shape = $Document.Shapes.AddTextbox(1, $Left, $Top, $Width, $Height)
    $shape.RelativeHorizontalPosition = 1
    $shape.RelativeVerticalPosition = 1
    $shape.WrapFormat.Type = 3
    $shape.Fill.Visible = 0
    $shape.Line.Visible = 0
    $shape.LockAnchor = -1
    $shape.TextFrame.MarginLeft = 0
    $shape.TextFrame.MarginRight = 0
    $shape.TextFrame.MarginTop = 0
    $shape.TextFrame.MarginBottom = 0
    $shape.TextFrame.AutoSize = 0
    $shape.TextFrame.TextRange.Text = $Text
    $shape.TextFrame.TextRange.Font.Name = 'Arial'
    $shape.TextFrame.TextRange.Font.Size = $FontSize
    $shape.TextFrame.TextRange.Font.Bold = $(if ($Bold) { -1 } else { 0 })
    $shape.TextFrame.TextRange.ParagraphFormat.Alignment = $Alignment
    $shape.TextFrame.TextRange.ParagraphFormat.SpaceAfter = 0
    $shape.TextFrame.TextRange.ParagraphFormat.SpaceBefore = 0
}

function Add-FormMark {
    param(
        [Parameter(Mandatory = $true)]$Document,
        [bool]$Checked,
        [double]$Left,
        [double]$Top
    )

    if ($Checked) {
        Add-FormText -Document $Document -Text 'X' -Left $Left -Top $Top -Width 9 -Height 10 -FontSize 8 -Bold $true -Alignment 1
    }
}

function Add-FormPhoto {
    param(
        [Parameter(Mandatory = $true)]$Document,
        [string]$Path,
        [double]$Left,
        [double]$Top,
        [double]$Width,
        [double]$Height
    )

    if ([string]::IsNullOrWhiteSpace($Path) -or -not (Test-Path -LiteralPath $Path)) {
        return
    }

    $shape = $Document.Shapes.AddPicture($Path, $false, $true, $Left, $Top, $Width, $Height)
    $shape.RelativeHorizontalPosition = 1
    $shape.RelativeVerticalPosition = 1
    $shape.WrapFormat.Type = 3
    $shape.LockAnchor = -1
    $shape.LockAspectRatio = 0
}

function Is-Choice {
    param([string]$Value, [string[]]$Choices)
    $normalized = ($Value -replace '[^a-zA-Z0-9]', '').ToLowerInvariant()
    foreach ($choice in $Choices) {
        if ($normalized -eq (($choice -replace '[^a-zA-Z0-9]', '').ToLowerInvariant())) {
            return $true
        }
    }
    return $false
}

function Has-Choice {
    param([string]$Value, [string[]]$Choices)
    $normalized = $Value.ToLowerInvariant()
    foreach ($choice in $Choices) {
        if ($normalized.Contains($choice.ToLowerInvariant())) {
            return $true
        }
    }
    return $false
}

function Value-OrBlank {
    param($Value)
    if ($null -eq $Value) { return '' }
    return [string]$Value
}

$word = $null
$document = $null

try {
    $data = Get-Content -LiteralPath $DataPath -Raw -Encoding UTF8 | ConvertFrom-Json
    Copy-Item -LiteralPath $TemplatePath -Destination $OutputDocx -Force

    $word = New-Object -ComObject Word.Application
    $word.Visible = $false
    $word.DisplayAlerts = 0
    $document = $word.Documents.Open($OutputDocx, $false, $false)

    if ($Form -eq '2') {
        Add-FormText $document (Value-OrBlank $data.last_name) 34 149 125 12 8 $true
        Add-FormText $document (Value-OrBlank $data.first_name) 188 149 96 12 8 $true
        Add-FormText $document (Value-OrBlank $data.middle_name) 306 149 112 12 8 $true
        Add-FormText $document (Value-OrBlank $data.extension_name) 421 149 48 12 8 $true
        Add-FormText $document (Value-OrBlank $data.gsis_beneficiary_name) 34 163 132 11 7.5 $false
        Add-FormText $document (Value-OrBlank $data.gsis_relationship) 226 163 115 11 7.5 $false
        Add-FormPhoto $document (Value-OrBlank $data.photo_path) 480 146 83 104

        Add-FormText $document (Value-OrBlank $data.birthdate) 34 177 78 11 7.5 $true
        Add-FormText $document (Value-OrBlank $data.place_of_birth) 188 177 157 11 7.2 $true
        Add-FormText $document (Value-OrBlank $data.citizenship) 405 177 82 11 7.5 $true
        Add-FormText $document (Value-OrBlank $data.contact_no) 34 191 132 11 7.5 $true
        Add-FormText $document (Value-OrBlank $data.email) 260 191 210 11 7.2 $true
        Add-FormText $document (Value-OrBlank $data.social_media) 34 207 430 11 7.2 $false

        Add-FormMark $document (Is-Choice $data.civil_status @('Single')) 69 229
        Add-FormMark $document (Is-Choice $data.civil_status @('Married')) 112 229
        Add-FormMark $document (Is-Choice $data.civil_status @('Widow', 'Widower', 'Widow/er')) 156 229
        Add-FormMark $document (Is-Choice $data.civil_status @('Separated')) 207 229
        Add-FormMark $document (Is-Choice $data.sex @('Male')) 292 229
        Add-FormMark $document (Is-Choice $data.sex @('Female')) 333 229
        Add-FormMark $document (Is-Choice $data.spes_type @('Student')) 40 249
        Add-FormMark $document (Is-Choice $data.spes_type @('ALS Student', 'ALS')) 93 249
        Add-FormMark $document (Is-Choice $data.spes_type @('Out-of-school Youth', 'OSY')) 166 249

        Add-FormMark $document (Has-Choice $data.parents_status @('Living Together')) 35 277
        Add-FormMark $document (Has-Choice $data.parents_status @('Solo Parent', 'Single Parent')) 102 277
        Add-FormMark $document (Has-Choice $data.parents_status @('Separated')) 274 277
        Add-FormMark $document (Has-Choice $data.parents_status @('Person With Disability', 'PWD')) 440 277
        Add-FormMark $document (Has-Choice $data.parents_status @('Senior Citizen')) 35 298
        Add-FormMark $document (Has-Choice $data.parents_status @('Sugar Plantation Worker')) 102 298
        Add-FormMark $document (Has-Choice $data.parents_status @('Indigenous People')) 274 298
        Add-FormMark $document (Has-Choice $data.parents_status @('Displaced Worker')) 440 298
        Add-FormMark $document (Has-Choice $data.parents_status @('OFW')) 35 317

        Add-FormText $document (Value-OrBlank $data.present_address) 34 327 430 11 7.2 $true
        Add-FormText $document (Value-OrBlank $data.permanent_address) 34 341 430 11 7.2 $true
        Add-FormText $document (Value-OrBlank $data.father_name) 34 355 120 11 7.2 $true
        Add-FormText $document (Value-OrBlank $data.father_contact) 181 355 102 11 7.2 $true
        Add-FormText $document (Value-OrBlank $data.father_occupation) 352 355 110 11 7.2 $true
        Add-FormText $document (Value-OrBlank $data.mother_name) 34 369 120 11 7.2 $true
        Add-FormText $document (Value-OrBlank $data.mother_contact) 181 369 102 11 7.2 $true
        Add-FormText $document (Value-OrBlank $data.mother_occupation) 352 369 110 11 7.2 $true

        $educationRows = @(
            @{ top = 409; school = $data.elementary_school; course = $data.elementary_degree; level = $data.elementary_level; attendance = $data.elementary_attendance },
            @{ top = 429; school = $data.secondary_school; course = $data.secondary_degree; level = $data.secondary_level; attendance = $data.secondary_attendance },
            @{ top = 450; school = $data.senior_high_school; course = $data.senior_high_degree; level = $data.senior_high_level; attendance = $data.senior_high_attendance },
            @{ top = 471; school = $data.tertiary_school; course = $data.tertiary_degree; level = $data.tertiary_level; attendance = $data.tertiary_attendance },
            @{ top = 491; school = $data.tech_voc_school; course = $data.tech_voc_degree; level = $data.tech_voc_level; attendance = $data.tech_voc_attendance }
        )
        foreach ($row in $educationRows) {
            Add-FormText $document (Value-OrBlank $row.school) 108 $row.top 105 16 6.5 $false 1
            Add-FormText $document (Value-OrBlank $row.course) 226 $row.top 111 16 6.5 $false 1
            Add-FormText $document (Value-OrBlank $row.level) 347 $row.top 74 16 6.5 $false 1
            Add-FormText $document (Value-OrBlank $row.attendance) 428 $row.top 103 16 6.5 $false 1
        }

        Add-FormText $document (Value-OrBlank $data.special_skills) 34 615 430 12 7.2 $false
        $historyTops = @(641, 656, 670, 684)
        for ($i = 0; $i -lt 4; $i++) {
            $history = $data.history[$i]
            if ($null -ne $history) {
                Add-FormText $document (Value-OrBlank $history.establishment) 171 $historyTops[$i] 145 11 6.8 $false 1
                Add-FormText $document (Value-OrBlank $history.year) 328 $historyTops[$i] 75 11 6.8 $false 1
                Add-FormText $document (Value-OrBlank $history.spes_id) 414 $historyTops[$i] 120 11 6.8 $false 1
            }
        }
        Add-FormText $document (Value-OrBlank $data.other_information) 34 713 500 24 6.8 $false
        Add-FormText $document (Value-OrBlank $data.full_name) 93 767 165 12 7.2 $true 1
        Add-FormText $document (Value-OrBlank $data.application_date) 446 767 100 12 7.2 $true 1
    }
    else {
        Add-FormText $document (Value-OrBlank $data.full_name) 112 246 180 13 9 $true 1
        Add-FormText $document (Value-OrBlank $data.age) 315 246 30 13 9 $true 1
        Add-FormText $document (Value-OrBlank $data.present_address) 112 265 394 24 8 $true 1
        Add-FormText $document (Value-OrBlank $data.application_day) 176 555 28 12 8 $true 1
        Add-FormText $document (Value-OrBlank $data.application_month_year) 270 555 125 12 8 $true 1
        Add-FormText $document (Value-OrBlank $data.full_name) 93 603 175 13 8.5 $true 1
        Add-FormText $document (Value-OrBlank $data.manager_name) 82 683 190 13 8.5 $true 1
        Add-FormText $document (Value-OrBlank $data.director_name) 333 683 190 13 8.5 $true 1
        Add-FormText $document (Value-OrBlank $data.application_date) 95 730 145 12 8 $false 1
        Add-FormText $document (Value-OrBlank $data.application_date) 350 730 145 12 8 $false 1
    }

    $document.Save()
    $document.ExportAsFixedFormat($OutputPdf, 17)
}
finally {
    if ($null -ne $document) {
        $document.Close($false)
        [void][System.Runtime.InteropServices.Marshal]::FinalReleaseComObject($document)
    }
    if ($null -ne $word) {
        $word.Quit()
        [void][System.Runtime.InteropServices.Marshal]::FinalReleaseComObject($word)
    }
    [GC]::Collect()
    [GC]::WaitForPendingFinalizers()
}
