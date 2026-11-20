param(
    [Parameter(Mandatory = $true)][string]$SdkPath,
    [string]$FixtureDirectory = ''
)
# Generate synthetic files first:
# php scripts/verify_excel_compatibility.php --fixtures storage/report_compatibility/validated
# Supply Microsoft's DocumentFormat.OpenXml 2.20 net46 DLL from its NuGet package.
$ErrorActionPreference = 'Stop'
if ($FixtureDirectory -eq '') {
    $FixtureDirectory = Join-Path (Split-Path -Parent $MyInvocation.MyCommand.Path) '../storage/report_compatibility/validated'
}
Add-Type -AssemblyName WindowsBase
Add-Type -Path (Resolve-Path -LiteralPath $SdkPath).Path
$officeValidator = New-Object DocumentFormat.OpenXml.Validation.OpenXmlValidator
$officeFailures = 0
$officeFiles = @(Get-ChildItem -LiteralPath $FixtureDirectory -Filter '*.xlsx')
if ($officeFiles.Count -eq 0) { throw 'No workbook fixtures found.' }
foreach ($officeFile in $officeFiles) {
    $officeDocument = [DocumentFormat.OpenXml.Packaging.SpreadsheetDocument]::Open($officeFile.FullName, $false)
    try {
        $officeErrors = @($officeValidator.Validate($officeDocument))
        $officeFailures += $officeErrors.Count
        foreach ($officeError in $officeErrors) {
            Write-Output ($officeFile.Name + ': ' + $officeError.Description + ' | ' + $officeError.Path.XPath)
        }
    } finally { $officeDocument.Dispose() }
}
Write-Output ('Microsoft Open XML validation: ' + $officeFiles.Count + ' workbooks, ' + $officeFailures + ' errors')
if ($officeFailures -gt 0) { exit 1 }
