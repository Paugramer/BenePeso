# Exact replacement passages for BenePeso
These passages are already inserted in FINAL-PAPER-BENEPESO_REVISED.docx. The scope and Figure 3 include the team's clarification about face-to-face PESO submission and manual DOLE rechecking.
Do not replace or invent actual research results using these passages.

## Context of the Study

This study developed BENEPESO, a web-based beneficiary profiling, preliminary eligibility screening, application management, and verification system for PESO Vinzons. The system organizes program and beneficiary records in a centralized database and provides separate access for the Administrator, PESO Staff, and Registered Applicant or Beneficiary. Residents can submit applications and monitor recorded status online, while authorized PESO personnel review information, record documentary verification, and update program progress. The system is intended to reduce repetitive record searching and unnecessary status-inquiry visits; physical submission and verification of required documents remain part of the office process.


## Research Objectives

The study aims to develop BENEPESO: A Web-Based Beneficiary Profiling, Eligibility, and Verification System for PESO Programs in Vinzons. Specifically, this study aims to:

2. determine and implement the features of BENEPESO that support beneficiary profiling, preliminary eligibility screening, application review, requirements verification, program-progress tracking, and historical report generation for authorized PESO personnel; and

3. evaluate BENEPESO through functional testing, including automated checks, and User Acceptance Testing (UAT), guided by selected ISO/IEC 25010:2011 quality characteristics:

a. functional suitability;


## Research Questions

2. What features are needed to support beneficiary profiling, preliminary eligibility screening, requirements verification, program-progress tracking, and historical report generation in BENEPESO?

3. How is BENEPESO evaluated through functional testing and User Acceptance Testing (UAT), guided by the following selected ISO/IEC 25010:2011 quality characteristics?

a. functional suitability;


## Scope and Delimitations ? replace the entire section

This study focuses on BENEPESO, a web-based beneficiary profiling, preliminary eligibility screening, application management, and verification system for the Public Employment Service Office of Vinzons, Camarines Norte. The implemented system covers three program families: TUPAD, SPES, and MSME Profiling. Program categories contain descriptive information, while dated batches contain schedules, slots, venues, and configured eligibility requirements. Account information, applications, beneficiary details, program participation, requirements review, and reporting records are maintained in a centralized database.

The system supports three user roles: Administrator, PESO Staff, and Registered Applicant or Beneficiary. Registered users can create an account, securely sign in, maintain their own profile, view program batches, submit an online application subject to configured screening rules, and monitor recorded application and program-progress updates. Beneficiary name verification is available only after authentication and is restricted to the user's registered barangay and approved program batches associated with their own application. Results are limited and masked where applicable. A missing search result does not establish eligibility or replace official PESO confirmation.

The Administrator manages program information and batches, reviews staff-submitted batch requests, accepts or rejects applications, and manages staff and user account access. PESO Staff submit batch requests for administrator approval, encode or import beneficiary records, review requirements, and update permitted application details and program-progress information. Both authorized personnel roles can access historical reports, Excel exports, forms and templates, and relevant activity logs. Final application acceptance or rejection and account management are administrator functions. After acceptance, applicants bring physical requirements face to face to the PESO office within the recorded deadline. PESO personnel verify the documents and forward them to DOLE for rechecking. Personnel record applicable receipt, review, resubmission, and progress information in BENEPESO; the DOLE rechecking procedure takes place outside the website.

The implemented scope is limited to PESO programs within the Municipality of Vinzons. Preliminary eligibility screening uses configured rules and information encoded or submitted to the system; it does not authenticate records against DOLE or other national-government databases. Final office decisions and documentary verification remain the responsibility of authorized PESO personnel. Account registration currently requires applicants to be at least 18 years old. Program-specific age limits, sex restrictions, household rules, participation history, and other implemented local screening conditions apply where configured. These implementation rules must be distinguished from general program coverage stated in source forms.

The system does not process payments, transfer funds, distribute financial assistance, or perform job matching. MSME capital, earnings, and accepted payment-method fields are descriptive profiling records rather than transactions processed by the website. Face-to-face requirements submission, PESO document handover to DOLE, and DOLE rechecking are manual office procedures outside the automated website boundary. Pending and active records are processed in the Application module. The Report module provides read-only historical records and exports for rejected applications and approved applications with terminal statuses, including Completed, Exam Failed, Not Qualified, or Cancelled. The system does not provide applicant self-service cancellation or unrestricted public beneficiary searches.


## Significance ? Beneficiaries

Beneficiaries. The system provides online access to available program information, application submission, and recorded status updates. Registered users can maintain their own profile and use restricted beneficiary verification within their barangay and applied program batches. These features are intended to reduce unnecessary trips for information and status inquiries, particularly for residents of remote or island barangays. Accepted applicants still need to follow PESO instructions for physical requirements submission and verification. Any claimed reduction in travel cost, waiting time, or processing time requires supporting evaluation data.


## Figure 1 introduction, caption and interpretation

Figure 1 organizes selected related literature according to features discussed in the study. The matrix retains the researchers' feature coding for comparison; a check mark represents a feature identified in the original literature review, while NR means that the feature was not established in that coding. NR must not be interpreted as proof that the feature is absent from a source system. Individual classifications require confirmation against the original publications.

Figure 1. Feature-Coverage Matrix of Selected Related Literature

The matrix provides a structured comparison of the selected literature across system access, security and digital identity, centralized data storage, profile management, eligibility verification, online applications, dashboards, program tracking, and reporting. It is used to organize the review and identify areas for closer investigation rather than to establish that a study or system lacks an unreported feature. Conclusions about a research gap should be based on verified source descriptions and the local operational requirements of PESO Vinzons. The matrix does not constitute a measured comparison of system quality or proof that BENEPESO is superior to the reviewed systems.


## Evaluation heading and opening

Evaluation of Beneficiary Profiling, Eligibility, and Verification Systems

Evaluation of BENEPESO considers both implemented behavior and user experience. Functional testing compares actual results with expected outcomes for defined tasks, while User Acceptance Testing (UAT) assesses whether intended users can complete role-appropriate tasks. The evaluation is guided by the selected ISO/IEC 25010:2011 product-quality characteristics of functional suitability, usability, performance efficiency, and security (International Organization for Standardization [ISO], 2011). Selecting these characteristics does not imply evaluation of the entire standard or ISO certification. Technical test evidence and respondent perceptions should be reported separately.


## Software Development Methodology

The study adopted an iterative and incremental development approach guided by the Rational Unified Process (RUP). RUP organizes development into four phases: Inception, Elaboration, Construction, and Transition (IBM, n.d.). Requirements analysis, design, implementation, and testing may be revisited across iterations. Specific agile practices should be described separately when supported by the team's development records.

The approach allows requirements and interfaces to be refined through stakeholder feedback and repeated testing. Figure 2 illustrates the four RUP phases and the changing emphasis of development disciplines across iterations. Review and maintenance activities in the project schedule are project-specific activities rather than an additional standard RUP phase.

Figure 2. Rational Unified Process Phases and Disciplines (framework reference: IBM, n.d.)


## Elaboration and Figure 3 explanation

The Elaboration Phase focused on system architecture, information flow, database structure, and interface design. The implemented account, program-batch, beneficiary, requirements-review, and reporting structures guided the database design. Interface prototypes represented the distinct responsibilities of the Administrator, PESO Staff, and Registered Applicant or Beneficiary. The context-level data flow diagram in Figure 3 defines the implemented software boundary and the information exchanged with these users. Detailed database-design artifacts should reflect the current schema and distinguish logical relationships from enforced database constraints.

Figure 3 distinguishes two connected aspects of the service: the information exchanges with the BENEPESO website and the external manual document-verification procedure. The software context shows the Administrator, PESO Staff, and Registered Applicant or Beneficiary. In the office procedure, the applicant submits physical requirements face to face at PESO; PESO personnel verify the documents and forward them to DOLE for rechecking. This external agency review is part of the overall service workflow and is not an automated connection to the DOLE information system.

Figure 3. BENEPESO System Context and External Document-Verification Workflow

The Administrator supplies program and batch instructions, application decisions, and account-management instructions and receives review queues, records, summaries, and reports. PESO Staff submit batch requests, encoded or imported application information, requirements-review findings, and permitted progress updates and receive records, review feedback, forms, and reports. Registered applicants and beneficiaries supply account and profile information, applications, and scoped verification requests and receive available-program information, screening messages, requirements instructions, and recorded status updates. The application decision is maintained separately from program progress. BENEPESO stores these records centrally and supports authorized reporting and notifications. Physical requirements are submitted face to face and verified by PESO before being forwarded to DOLE for rechecking. DOLE is therefore an external participant in the office workflow, while website access and recorded updates remain the responsibility of authorized PESO personnel. The diagram does not imply a DOLE login, automated agency API, or a separate DOLE approval status implemented in the website.


## Schedule interpretation

Figure 4 presents the January–December 2026 project schedule from initial requirements work to review and future maintenance planning. It shows the intended timing of activities and their overlap. The chart should be read as a schedule rather than proof that every activity was completed. Actual completion dates, stakeholder consultations, testing records, and deployment evidence must support any claims of completed work. Activities after October 20, 2026 remain planned unless separately documented.

Transition activities in the schedule include debugging, corrections, additional functions, user testing, hosting preparation, final testing, and system evaluation. The bars indicate the planned windows for these tasks; actual completion and deployment dates should be recorded separately. These activities prepare the system for intended use and provide feedback for corrections. They do not, by themselves, establish that user acceptance or production deployment has been completed.

As of the October 2026 review point, the schedule places the project in final testing and evaluation activities. Any reported results must be drawn from completed test records and actual respondent feedback. Developer smoke tests and regression checks can support technical verification but do not replace UAT conducted with the study's respondents.

The schedule includes review and future maintenance planning from October through December 2026. These are project-specific activities following and overlapping transition work, not a fifth standard RUP phase. Future activities include reviewing functionality and performance, identifying improvements, and documenting lessons learned. Their completion should be reported according to actual records rather than inferred from planned chart bars.


## Evaluation method and data analysis

The evaluation framework combines functional testing, including automated checks where applicable, with User Acceptance Testing (UAT), guided by selected ISO/IEC 25010:2011 characteristics: functional suitability, usability, performance efficiency, and security. Functional tests compare actual behavior against defined expected results. Performance checks require stated conditions and measured outcomes, while security checks examine specific controls and access restrictions. UAT uses tasks appropriate to each role and questionnaire responses to assess user experience and acceptance. Technical findings and user ratings must be reported separately and supported by their corresponding evidence.

Questionnaire responses are analyzed using a five-point Likert scale from 1 (Strongly Disagree) to 5 (Strongly Agree). Item means summarize respondent perceptions regarding the selected quality characteristics. These ratings are interpreted separately from measured response times, functional test outcomes, and security-check findings. The number of valid responses must be stated for each item, including how missing or not-applicable answers are handled. Means are rounded to two decimal places before applying the interpretation intervals in Table 2.


## Statistical treatment

For each questionnaire item, the frequency-weighted mean is computed using the expression below. W represents the frequency of a rating category, X represents the numerical rating, and N equals the sum of the valid response frequencies for that item. Accordingly, the expression is equivalent to the sum of frequency multiplied by rating divided by the total valid responses. Any aggregation across items or respondent groups should state its weighting method, and excluded or not-applicable responses should be accounted for explicitly.

W = Frequency of responses in a rating category

N = Total valid responses for the item (sum of frequencies)
