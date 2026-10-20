# BenePeso paper versus implemented system

Reviewed: October 20, 2026, Asia/Manila.

Source: `C:\Users\paulo\Downloads\FINAL-PAPER-BENEPESO (1).docx`.

The original paper was not modified. Review covered Chapters 1–3, both tables, all four numbered figures, the appendix use-case diagram, six appendix form images, and the mathematical expression stored in Word's equation XML. Locations below use section/figure names because Word pagination can change by computer. The comparison uses the current project at commit `6bf79dc` and read-only configuration queries. No application changes were made for this review.

Update from the research team: requirements are submitted face to face to PESO, verified by PESO, and forwarded to DOLE for rechecking. DOLE is part of the manual office workflow. Earlier integration comments concern the software boundary, not absence of a DOLE procedure. The revised paper now includes this clarification.

## Overall finding

The title, general problem, profiling purpose, centralized records, online applications, and local PESO focus broadly match the system. The paper is not yet fully aligned with the implementation. The highest-priority corrections are the role descriptions, DFD, use-case diagram, distinction between online application and physical requirements submission, and the evaluation table/method terminology.

System behavior is evidence of what the website implements. It does not prove that local eligibility policies have been formally approved, that interviews or UAT occurred, or that the cited studies support particular claims. Those require office/research records and source documents.

## 1. Corrections to prioritize before defense

| Priority | Paper location | Finding | Correction |
| --- | --- | --- | --- |
| Critical | Chapter 1, Scope and Delimitations | Says two primary roles, then gives staff full control. The system has three roles with different permissions. | Name Administrator, PESO Staff, and Registered Applicant/Beneficiary separately. Staff cannot manage accounts or give final application acceptance/rejection. |
| Critical | Figure 3, Data Flow Diagram | Mixes flowchart decision diamonds, data stores, organizational actions, and external DOLE decisions. It does not faithfully model the implemented system. | Redraw an implemented-system DFD. Separate external/manual PESO/DOLE business procedures if they are needed for context. |
| Critical | Figure 3 | Shows DOLE approval/validation and reporting paths as if they are implemented interactions. | State that there is no DOLE portal/API/database integration in BenePeso. Exported reports may support manual submission by staff. |
| Critical | Appendix 1, Use Case Diagram | Gives beneficiaries Cancel Application and implies in-system requirements submission. | Remove Cancel Application from implemented use cases. Describe physical requirements submission outside the software boundary; keep staff recording/review inside. |
| High | Scope; Significance for Beneficiaries | Suggests a resident first searches their name to establish eligibility and applies only when absent. | Explain that name verification is restricted to the user's barangay AND approved program batches already linked to their application. A missing search result is not proof of eligibility. |
| High | Table 2 | Agree interval is `3.41 - 4.420`; narrative says `3.41–4.20`. | Use `3.41–4.20` consistently. Correct `Strongly Agre e` to `Strongly Agree`. |
| High | Objectives, questions, evaluation sections | Uses Automation Evaluation, Functional Testing, and UAT inconsistently. | Use one method description, distinguish automated functional tests from respondent ratings, and state the ISO edition and selected characteristics. |
| High | Figure 1 and accompanying conclusion | A feature comparison is presented as proof that other studies lack features without evidence for individual cells. | Cite an evidence location for each assessed feature; distinguish absent from not reported. Consider renaming it a literature feature-comparison matrix. |
| High | Chapter 3 and final-paper completeness | File contains Chapters 1–3 and appendices but no evaluation results, conclusions, questionnaire, or test-result tables. | Confirm the required defense format. If a complete final manuscript is required, include actual results and required later chapters; do not invent results. |
| Medium | Appendix forms and implemented eligibility | SPES form mentions ages 15–30, but signup requires 18+ and current SPES category is 18–30. TUPAD form mentions senior fitness certification; current checklist uses PWD/work-limitation declarations. | Explain local implemented restrictions and reconcile them with office-approved policy. Do not silently rewrite an official source form. |

## 2. What the system actually supports

| Capability | Administrator | PESO Staff | Registered applicant/beneficiary |
| --- | --- | --- | --- |
| Password login and role-specific pages | Yes | Yes | Yes; phone/email and Google integration also exist |
| Program categories/details and batch management | Manages program details, adds/edits batches, accepts/rejects pending batch requests | Submits batch requests for admin approval | Views available programs/batches |
| Manual records and CSV/XLSX imports | Yes | Yes | Submits own online application |
| Application acceptance/rejection | Yes | No final acceptance/rejection action | Views recorded outcome |
| Requirements recording/review and program progress | Yes | Yes | Views updates; physical requirements are brought to PESO |
| Profile and activity history | Administrative account context | Staff account context | Own profile, password/photo changes, own activity history |
| Historical report/export and forms/templates | Yes | Yes | No administrative report-generation access |
| Manage accounts | Creates/edits staff; bans/restores staff and user access | No | No |

Main evidence: `auth.php`, `process_login.php`, `process_signup.php`, `admin_program.php`, `peso_staff_program.php`, `admin_application.php`, `peso_staff_application.php`, `admin_accounts.php`, `programs.php`, `verification.php`, `profile.php`, and `forms_downloads.php`.

Permanent program families are TUPAD, SPES, and MSME Profiling. Database program rows represent dated batches. Replace vague repeated references to “TUPAD and other initiatives” with the actual three families where describing implemented scope. Do not imply users can create arbitrary new program families.

## 3. Figure-by-figure review

### Figure 1: Existing Related System

This is a comparison matrix rather than a statistical results graph. The topics listed mostly correspond to real BenePeso capabilities, but the marks assigned to other studies cannot be validated from BenePeso code.

Issues to correct:

- Use `Centralized Database`, not `Centralized Data Based`.
- Use consistent author spellings, including Ivanković, Achungura, and Riansyah, and consistent author-year formatting across the matrix, discussion, and references.
- Some sources are reviews or analyses rather than implementations of a comparable beneficiary system. Describe the comparison as literature coverage unless the source actually describes and evaluates an implemented system.
- Add a legend: for example, check = explicitly documented; dash = explicitly absent; NR = not reported. An empty cell alone cannot establish that a system lacks a feature.
- Add source-page/section evidence for checked and absent features. Do not infer online applications, profile management, or report generation merely because a paper discusses digital identity or dashboards.
- If useful, add a BenePeso column with evidence from the implemented modules. This does not establish superiority on its own.
- Replace the categorical conclusion “no single system fully incorporates all essential features” with a narrower conclusion supported by the selected sources and coding criteria.

Suggested caption: `Figure 1. Comparison of Features Reported in Selected Related Studies.`

### Figure 2: Rational Unified Process

The image shows the four RUP phases and overlapping disciplines. It is generally appropriate for discussing an iterative RUP approach.

Change “Agile Methodology, specifically the Rational Unified Process” to a precise statement such as: “The study adopted an iterative and incremental development approach guided by the Rational Unified Process (RUP).” Mention agile practices separately only if the team actually used and documented them.

The Gantt chart's additional Review Phase should be identified as project-specific review/maintenance activity, not a fifth standard RUP phase. Credit the source of the reproduced/adapted figure and ensure the reference is present.

Primary reference: IBM identifies Inception, Elaboration, Construction, and Transition as the four RUP phases: https://www.ibm.com/docs/en/rational-clearquest/10.0.8?topic=settings-project-planning

### Figure 3: Data Flow Diagram

This needs the most substantial revision.

Specific mismatches:

1. `DOLE Approved?`, validation feedback, and automatic DOLE reporting have no matching implemented agency interface. If these describe actual office practice, put them in a separately labeled business-process diagram with a clear software boundary.
2. `Has Account?` appears after eligibility checking even though login is required to apply. It should not determine acceptance of an already submitted application.
3. Rejected applications remain stored beneficiary/application records with `approval_status='Rejected'`; they are not simply discarded.
4. Application records are inserted into `beneficiaries`; there is no separate active `applications` table in the current database. A logical DFD may use logical stores, but its data dictionary must explain the mapping rather than implying separate databases.
5. Account tables, program tables, beneficiary records, and reporting details are in one MySQL database. Distinguish logical data stores from physically separate databases.
6. The diagram omits important implemented flows: staff batch request → admin batch review, admin application decision, requirements deadline/checklist/resubmission, status updates, and historical report/export.
7. Decision diamonds and yes/no control branches make this read as a mixed flowchart. Use one defined DFD notation consistently, numbered processes, labeled data flows, and recognizable data stores. If retained as a flowchart, rename it and still provide the promised DFD separately.
8. Chapter 3 calls this figure an activity schedule in the paragraph immediately before it. Replace that sentence with a system-data-flow introduction.

Suggested implemented DFD processes:

- 1.0 Authenticate and maintain account/profile.
- 2.0 Manage program categories and batches; route staff requests to administrator review.
- 3.0 Check configured eligibility and submit/encode/import applications.
- 4.0 Review applications and record administrator decisions.
- 5.0 Record/review requirements, request resubmission, and update program progress.
- 6.0 Retrieve scoped verification results, dashboard summaries, historical reports, and exports.
- 7.0 Record audit activity and prepare/send notification messages.

Logical stores can map to accounts (`admins`, `peso_staff`, `users`), programs (`program_categories`, `programs`), application/beneficiary records (`beneficiaries` plus program details/history), review/acceptance records, reporting details, audit logs, and notification outbox. DOLE is not an implemented authenticated actor.

### Figure 4: Schedule of Activities

The January–December 2026 chart generally matches the sequence discussed in the text. This is a project schedule, not proof that work was completed in those months.

- Add a legend distinguishing actual/completed work from planned work. As of October 20, November/December activities cannot all be described as completed.
- State exact activity dates in a companion table if bar endpoints are ambiguous. Transition activities visually end around the October/November boundary while the narrative says through November; make both representations agree.
- Label review as a project-specific activity, consistent with the four-phase RUP description.
- Keep Domain Registration and Webhosting Setup as actual only if the team has records of the deployment; local working code does not establish the purchase or live-hosting date.
- Keep the timeline narrative faithful to research records. Repository changes alone cannot verify January interviews or earlier development dates.

### Appendix 1: Use Case Diagram

The three actors are appropriate and contradict the two-role Scope paragraph. Most staff/admin goals are recognizable, and the applicant's own activity log does exist in `profile.php`.

Required changes:

- Remove applicant `Cancel Application`: no implemented self-service cancellation action was found. Legacy `Cancelled` status is not evidence of this capability.
- Move physical `Submit Requirements` outside the software boundary, or replace it with the actual applicant goal `View Requirements and Submission Deadline`. Inside the system, staff/admin record document receipt and verify the checklist.
- Replace `Add / Edit / Delete Program Details` with implemented operations such as edit category details, create/edit batches, and accept/reject pending batches. No program deletion action was found in the current handlers.
- Add staff `Submit Batch Request` and administrator `Approve/Reject Batch Request`.
- Show administrator access to requirements review/progress updates, not only staff access, unless a documented shared reviewer actor/generalization represents both.
- Add import and Forms & Templates as significant implemented capabilities if the diagram is intended to describe the complete system.
- Do not use `include`/`extend` arrows to imply that eligibility review, later physical submission, and receiving a decision happen inside one synchronous application-submission action. Model separately timed user goals separately.
- Use lowercase `<<include>>` and `<<extend>>` consistently; retain these relationships only where their meaning is justified. Optional validation feedback is an alternate/exception flow, not necessarily a separate actor goal.
- “Generate Reports and Analytics” should mean the actual historical-report exports and dashboard descriptive summaries, not predictive analytics.

### Appendices 2–5: Program forms

The MSME form broadly matches implemented business, owner, operations, employment, capital, assistance, and challenges fields. Recording earnings or accepted payment methods does not mean the website processes payments. The no-payment-processing delimitation remains appropriate.

The SPES application and oath forms correspond to forms available in the system. However, the SPES application image says `Regional Office No. 12`, while the paper is for PESO Vinzons. Confirm with the office that the supplied form is the appropriate template; do not present an unexplained out-of-region header as a customized Vinzons form.

The printed SPES form states ages 15–30. The current website requires 18+ for account creation; the current SPES master category is configured for 18–30. Describe this as the current local implementation, explain any approved local restriction, and reconcile it with office requirements. Do not claim that the website accepts 15–17-year-old online applicants.

The TUPAD form mentions a fitness-to-work certificate for senior applicants aged 60 and above. The implemented checklist adds a fitness certificate based on PWD or work limitation declarations, not solely age. This needs office-policy reconciliation; neither the form nor the software should be treated as automatically authoritative over the other.

Label appendix images as source/reference forms unless they are actual screenshots of the online forms. Similar fields do not make the layouts identical. Confirm source credits and readable resolution.

## 4. Content and methodology review

### Chapter 1: scope, eligibility and verification

Keep the local PESO focus, centralized records, absence of national-government-database integration, exclusion of job matching, and exclusion of actual payment processing.

Clarify these boundaries:

- Eligibility checking applies configured local rules to stored/submitted information. It is preliminary screening; office review and physical document verification still matter.
- Account registration, application submission, acceptance, document verification, and completion are separate events.
- Search is not an unrestricted public eligibility search. A signed-in user can search within their barangay and an approved batch already linked to their application; results are limited/masked.
- Reduce unnecessary trips for status inquiries; do not claim elimination of all office visits because physical requirements submission is part of the implemented flow.
- Explain both the application decision and program-progress status fields.
- Include all three implemented program families explicitly.

Current status flows:

| Program | Successful progress sequence |
| --- | --- |
| TUPAD | Waiting for Requirements → Documents Submitted → Orientation → Ongoing → Salary Distribution → Completed |
| New SPES | Waiting for Requirements → Documents Submitted → Examination Scheduled → Examination Passed → Orientation → Ongoing → Completed |
| Returning SPES | Waiting for Requirements → Documents Submitted → Orientation → Ongoing → Completed |
| MSME | Waiting for Requirements → Documents Submitted → Orientation → Ongoing → Completed |

`Pending`, `Approved`, and `Rejected` are application decisions. The display labels Waiting for Requirements/Documents Submitted map to stored progress values `Not Yet Availed`/`Requirements Received`. Rejected and approved terminal records enter historical Report. Pending/active records stay in Application. Not Qualified/Cancelled remain recognized historical values but are not currently offered as normal accepted-progress transitions.

Evidence: `beneficiary_lifecycle_helper.php`, `historical_report_helper.php`, `application_acceptance_helper.php`, and `verification.php`.

### Research evaluation and ISO terminology

- Replace vague `Automation Evaluation` with the evaluation actually performed: automated functional testing plus UAT, if both were performed.
- Use `functional suitability`, not an inconsistent mixture of functionality and unrelated labels, when naming the selected ISO quality characteristic.
- The selected set of functional suitability, usability, performance efficiency, and security is compatible with the 2011 product-quality terminology. Identify `ISO/IEC 25010:2011` explicitly if that is the basis of the instrument; newer editions exist. Do not claim full compliance or certification based on four selected characteristics.
- Primary reference: https://www.iso.org/standard/35733.html and the official 2011 terminology at https://www.iso.org/obp/ui/?_escaped_fragment_=iso%3Astd%3Aiso-iec%3A25010%3Aed-1%3Av1%3Aen
- A Likert survey describes respondent perceptions. It does not alone prove security, measured response speed, or correct program operation. Add actual test cases/expected versus actual outcomes, measured performance under stated conditions, and scoped security checks where those results are claimed.
- Today's developer smoke/regression checks are not a substitute for documented UAT with your actual respondents. Do not assign them invented UAT scores.
- State which tasks the administrator, staff, and beneficiaries performed, because their permissions and relevant survey items differ.

### Tables and statistical treatment

Table 1 totals are correct: 1 + 9 + 20 = 30. The displayed 3%, 30%, and 67% are acceptable whole-number rounding. For consistency, show 3.33%, 30.00%, and 66.67% if using two decimals. Respondent counts must be supported by actual recruitment/attendance/consent records; the database cannot validate the research sample.

Table 2 needs the Agree interval corrected to 3.41–4.20 and the Strongly Agree spelling repaired. Specify that interval interpretation is applied to means rounded to two decimal places, or define precise continuous bounds, to avoid ambiguity for values such as 4.205.

The Word equation is present as x-bar = sum(WX)/N. It was not missing merely because a text extractor omitted it. Clarify W: if it represents response frequency, define it that way and state that N equals the total frequency for that item. A clearer equivalent is `mean = Σ(f_i × r_i) / Σf_i`, where r is the rating and f is the frequency. Explain how missing/not-applicable responses and any unequal item weights are handled. Arbitrary weights cannot generally be divided by respondent count without justification.

### Internal consistency, missing evidence and references

- The file contains Chapters 1–3, references and appendices. There is no Chapter 4/5 evaluation-results/conclusions section in the supplied file. Whether those are required depends on your institution and defense stage.
- Chapter 3 says an ERD was produced, but no ERD appears among the embedded figures/appendices. Include it if required or if you want the claimed database design to be assessable. Use the current database and distinguish physical foreign keys from logical relationships.
- Correction after inspecting Word structured fields: the original document already contains a populated Table of Contents. Preserve it and update its entries/page numbers after revision; it was omitted by the first top-level paragraph extraction.
- Replace the heading `Evaluation of Recruitment and Employment System` with one matching this beneficiary profiling/eligibility system.
- Use completed tense for activities actually performed, future tense only for planned activities. Current sources/population/data-analysis passages mix both.
- Introduction citations Putra et al. (2023), Pöhn and Hommel (2020), Sari et al. (2023), and Latupeirissa et al. (2024) have no corresponding reference entry in this file. The Chapter 2 discussions of Sarwosri et al. (2023), Llovido et al. (2024), and Keibach and Shayesteh (2022) also lack matching entries. Add verified complete entries or remove/replace the unsupported citation and associated claim.
- Check uncited reference entries in the reverse direction, and follow the institution's required style for author lists, volume/issue, pages and DOI/URL.
- The individual studies and every check mark in Figure 1 were not independently source-verified in this review. Treat them as needing a separate source audit; their inclusion in a bibliography is not proof of existence or claim support.
- Identify the actual frontend/backend API endpoints if discussing API integration. Do not let that phrase imply DOLE or national-database integration.
- Qualify real-time dashboard claims. Current dashboard summaries are built from stored data when pages load; chart animation is not evidence of continuous server push or real-time monitoring.
- Describe expected improvements as aims unless measured results support achieved reductions in processing time, expenses, bias or error rates.

## 5. Ready-to-paste replacements

These passages describe implemented behavior. Research dates, respondent counts and policy approvals must still reflect your own evidence.

### Scope and user roles

BENEPESO is a web-based beneficiary profiling, preliminary eligibility screening, application management, and verification system developed for the Public Employment Service Office of Vinzons, Camarines Norte. The implemented system covers three program families: TUPAD, SPES, and MSME Profiling. It uses a centralized database to maintain account information, program batches, application records, program participation, requirements review, and historical reports.

The system supports three user roles: Administrator, PESO Staff, and Registered Applicant or Beneficiary. The Administrator manages program information, reviews staff-submitted batch requests, accepts or rejects applications, manages staff and user account access, and accesses monitoring and reporting functions. PESO Staff encode or import records, submit batch requests for administrator approval, review requirements, and update permitted program-progress information. Registered applicants and beneficiaries manage their own profiles, view available program batches, submit online applications, and monitor their recorded application and program status.

Name verification is available only to authenticated beneficiaries and is limited to their registered barangay and approved program batches associated with their own applications. A search result reflects stored records and does not by itself establish program eligibility. Preliminary screening uses configured program rules and submitted information; application decisions and documentary verification remain subject to authorized PESO review. Accepted applicants submit the required physical documents at the PESO office within the recorded deadline.

The system does not integrate with DOLE or other national-government databases for automatic validation or approval. It supports data recording and report exports for authorized personnel but does not perform payment processing, fund transfers, or job matching. Capital, earnings, and payment-method information collected for MSME profiling are descriptive records rather than financial transactions processed by the website.

### Report coverage

The Application module supports pending and active application processing, requirements review, and program-progress updates. The Report module provides read-only historical records and exports for rejected applications and approved applications with terminal statuses, including Completed, Exam Failed, Not Qualified, or Cancelled. Application decision and program-progress status are maintained separately.

### Replacement Figure 3 narrative, after redrawing the figure

Figure 3 presents the implemented information flow among the Administrator, PESO Staff, and Registered Applicant or Beneficiary. Applicants register, maintain their profiles, and submit applications for approved program batches. The system checks configured eligibility and duplicate/active-application conditions before recording a submission. Authorized staff and administrators review application information, while the Administrator records acceptance or rejection. After acceptance, PESO personnel record receipt and verification of physical requirements, request resubmission when necessary, and update program-specific progress. Stored records support scoped beneficiary verification, dashboard summaries, notifications, audit logs, and historical report exports. External agency approval and physical document handover are outside the automated system boundary.

### Software development methodology

The study adopted an iterative and incremental development approach guided by the Rational Unified Process (RUP), consisting of Inception, Elaboration, Construction, and Transition. Requirements analysis, design, implementation, and testing were revisited across iterations according to stakeholder feedback. Subsequent review and maintenance activities were included in the project schedule as project-specific activities rather than an additional standard RUP phase.

Use the last paragraph only if it accurately reflects the team's actual methodology and records.

## 6. Defense preparation checklist

1. Correct the scope to three roles and align it with the use-case diagram.
2. Redraw Figure 3 to match the actual website; separate manual/DOLE procedures.
3. Remove unsupported cancellation and deletion use cases; clarify physical requirements submission.
4. Correct Table 2 and align evaluation terminology/ISO edition.
5. Reconcile SPES age/local-office form details and TUPAD certificate rules with PESO.
6. Populate the contents; add the ERD and actual evaluation evidence required for your defense stage.
7. Repair missing references and substantiate Figure 1's feature assignments.
8. Rehearse explaining application decisions versus progress statuses and active processing versus historical reports.
