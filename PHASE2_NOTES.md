# Phase 2: Applications, historical reports and forms

- `admin_application.php` and `peso_staff_application.php` retain the existing transaction workflow and role permissions. Pending applications remain in the review queue, including imported legacy records that have not yet been accepted.
- The existing `*_beneficiaries.php` routes now display read-only historical reports. Rejected records and accepted records marked Completed, Not Qualified, Exam Failed or Cancelled are historical. Report queries, the report API and Excel exports share this condition.
- Forms & Downloads lists printable forms, blank report templates and blank import templates for all three programs. Guidelines PDFs use the saved information from accepted program batches; they do not introduce new eligibility rules.
- SPES/TUPAD/MSME Excel exports use sanitized copies of the supplied templates and the existing PESO letterhead. MSME preserves both sheets and product/employment blocks. Phone numbers and IDs are exported as text. SPES reports map rejection, Not Qualified and Exam Failed to DISQUALIFIED; cancellation preserves the recorded acceptance decision. TUPAD's unlabelled F/G columns remain blank; its second remarks column is optional.
- Report Details is available within accepted, active applications. It records actual SPES employment dates/assignment, explicit parent/guardian overrides, remarks and MSME night-market earnings. Missing fields remain blank. Batch schedule dates are not used as actual employment dates.
- `database_update_phase2_reporting.sql` adds a separate reporting-details table. This additive table is created on first use and was applied locally; no beneficiary records were deleted or migrated.
- The source workbooks in Downloads are untouched. Published blank assets contain no source beneficiary/business rows, shared-string caches or author metadata. Import templates are separate from report templates.

Validation: `C:\xampp\php\php.exe scripts\verify_phase2_exports.php` checks template positions, decision/date mapping, leading zeroes, duplicate product prices, extended product blocks, both MSME sheets and blank import parsing.
