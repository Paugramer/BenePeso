# Requirements Submitted report

Access: Admin or PESO Staff → Report → Requirements Submitted. Available from the Report directory and each program's report tabs.

The report includes all recorded physical submissions, with Not Reviewed, Under Review, Needs Resubmission, or Verified / Complete checklist status. Later-stage and closed records remain included when a submission record exists. Legacy approved Documents Submitted records are also included; unknown submission dates are shown as Not recorded. Later progress alone is not treated as proof of a submission or verified checklist.

Filters cover program, batch, barangay, requirements-review status, and name/barangay/batch search. The list, Print and Excel use the same read-only dataset. The screen shows 25 rows per page; Print and Excel include every matching record. Output buttons carry the current form values, including search text not yet applied to the screen.

Dates reflect the latest recorded receipt, including resubmissions; the existing storage does not preserve a full submission-event history. Requirements Submitted and Verified / Complete do not certify forwarding to, return from, or approval by DOLE. No DOLE workflow status or beneficiary status is automatically changed by this report.

Validation completed:

- Syntax checks for the new PHP/report helper/test and both modified report pages.
- Temporary-table tests covering submission evidence, later/closed records, legacy/latest dates, review labels, nine individual filters, and combined filters.
- A 53-record temporary-table controller test: page 3 displayed 3 records, while Print/Excel included all 53; barangay filtering produced 27 in both outputs, and an empty filter produced 0.
- Twelve actual-page Print/Excel comparisons across Admin and Staff, plus 16 authenticated HTTP checks.
- Desktop/mobile browser checks, current-filter link-click checks, and intercepted print invocation; no actual printer job was sent.
- Existing official-template export and acceptance-notice regression checks passed.
- Before/after fingerprints confirmed unchanged beneficiary, user, staff, checklist, document, supplemental-report, notification and audit-log contents during the page audit.

No schema migration, beneficiary edits, or live notification sending was required. This feature is separate from bulk application acceptance; the current Needs Approval view has Select All but no Accept Selected action.
