# Account ban and activity log check

Reviewed October 20, 2026.

## Ban enforcement

Password login and Google sign-in handlers explicitly reject Banned accounts. Protected pages verify the current account status in the database rather than trusting a session identifier. API role checks remove banned roles from the session. Remember-me restoration rejects banned users/staff and revokes the rejected token.

A temporary-table regression test passed for active accounts, banning during an existing session, API/page role denial, valid remember-me token denial/revocation, and restored account access. No real account was banned or changed. This test does not perform a live Google sign-in or enter a real user's password.

The avatar-serving endpoint previously checked session identity only. It now uses the same active-account guard and clears a banned role before returning 404. Other protected application/profile routes already use active-role checks. A ban takes effect on the next protected request; it does not remotely erase information already displayed in an open page. Public informational pages remain public.

Test: `php scripts/verify_account_ban.php`.

## Activity logging

Logging works, and the database contains stored authentication, application, profile, status-update, and ban/unban events. Existing regression tests confirm staff/admin attribution and atomic import/audit rollback with synthetic temporary tables.

Current handlers log these significant actions:

- Login/logout; beneficiary application submission and program viewing.
- Application acceptance/rejection and individual/bulk progress updates.
- Requirements checklist review, resubmission requests and bulk remarks.
- Supplemental reporting-detail updates and import completion/rejection.
- Staff account creation/edit, account ban/unban, beneficiary profile and password changes.
- Staff batch-request creation.

Coverage is not complete. The current admin program handler has no logging call for its category/batch edits or batch review decisions; the admin manual beneficiary save path lacks a corresponding audit call; staff manual record creation and separate avatar updates also lack consistent logging. Report viewing/download is not comprehensively audited. Older stored events have inconsistent module/action labels and should not be silently rewritten as part of this change.

No claim is made that every event is logged or that existing logging failures always block the underlying action. A separate targeted change is needed to close these gaps. The current commit does not refactor these workflows.

Test: `php scripts/verify_application_audit.php`.
