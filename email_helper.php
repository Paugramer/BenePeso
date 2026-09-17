<?php
require_once __DIR__ . '/spes_lifecycle_helper.php';
// email_helper.php

// Adjust these paths if your PHPMailer folder is located somewhere else
require_once "PHPMailer/src/PHPMailer.php";
require_once "PHPMailer/src/SMTP.php";
require_once "PHPMailer/src/Exception.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function benepeso_recipient_name(string ...$candidates): string {
    foreach ($candidates as $candidate) {
        $name = trim((string)preg_replace('/\s+/u', ' ', $candidate));
        if ($name !== '' && !preg_match('/^(?:bene\s*peso|benepeso|peso vinzons|user)$/i', $name)) return $name;
    }
    return 'Applicant';
}

/**
 * Reusable function to send beautifully formatted BENEPESO emails
 * 
 * @param string $to_email     The email address of the recipient
 * @param string $subject      The subject line of the email
 * @param string $headline     The big bold text inside the email body
 * @param string $body_content The main HTML content/message of the email
 * @return boolean             Returns true if email sent successfully, false otherwise
 */
function sendBENEPESOEmail($to_email, $subject, $headline, $body_content, &$error_message = null) {
    $error_message = null;
    $to_email = trim((string)$to_email);

    if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "The recipient email address is invalid.";
        error_log("BENEPESO Email Error: Invalid recipient address.");
        return false;
    }

    // Retry once because local SMTP connections can occasionally fail due to DNS/network delays.
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $mail = new PHPMailer(true);

        try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = "smtp.gmail.com";
        $mail->SMTPAuth = true;

        // Configure credentials through environment variables; never commit passwords.
        $smtp_username = getenv("BENEPESO_SMTP_USERNAME") ?: "lguvinzonspeso@gmail.com";
        $mail->Username = $smtp_username;
        $mail->Password = getenv("BENEPESO_SMTP_PASSWORD") ?: "";

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->Timeout = 20;
        $mail->Hostname = "gmail.com";

        // Sender and Recipient
        $mail->setFrom($smtp_username, "PESO Vinzons");
        $mail->Sender = $smtp_username;
        $mail->addReplyTo($smtp_username, "PESO Vinzons");
        $mail->addAddress($to_email);

        // Content Setup
        $mail->isHTML(true);
        $mail->CharSet = "UTF-8";
        $mail->Encoding = "base64";
        $mail->MessageID = sprintf(
            '<benepeso.%s.%s@gmail.com>',
            gmdate('YmdHis'),
            bin2hex(random_bytes(8))
        );
        $mail->XMailer = "PESO Vinzons Notification Service";
        $mail->Subject = $subject;
        
        $current_year = date("Y");

        // Professional Green BENEPESO HTML Template
        $mail->Body = "
        <div style='font-family: Arial, sans-serif; background-color: #f4f8f5; padding: 40px 20px; color: #163524;'>
            <div style='max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.05); border: 1px solid #dbe6df;'>
                
                <!-- Header -->
                <div style='background-color: #1f7a54; padding: 30px; text-align: center;'>
                    <h1 style='color: #ffffff; margin: 0; font-size: 26px; letter-spacing: 1px; font-weight: 800;'>PESO Vinzons</h1>
                    <p style='color: #e6f4ed; margin: 5px 0 0; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;'>Official Applicant Notice</p>
                </div>
                
                <!-- Body Content -->
                <div style='padding: 40px 30px; text-align: left;'>
                    <h2 style='margin-top: 0; color: #145339; font-size: 22px; font-weight: 800; text-align: center;'>{$headline}</h2>
                    <div style='color: #66786f; font-size: 15px; line-height: 1.6; margin-bottom: 30px;'>
                        {$body_content}
                    </div>
                </div>
                
                <!-- Footer -->
                <div style='background-color: #f9fbf9; padding: 25px; text-align: center; border-top: 1px solid #dbe6df;'>
                    <p style='color: #9ab0a3; font-size: 12px; margin: 0; line-height: 1.5;'>
                        © {$current_year} PESO Vinzons<br>Public Employment Service Office • Municipality of Vinzons, Camarines Norte
                    </p>
                </div>
                
            </div>
        </div>";

        // Plain text fallback for email clients that do not support HTML
        $mail->AltBody = strip_tags(str_replace(['<br>', '</p>', '</h1>', '</h2>'], ["\n", "\n\n", "\n\n", "\n\n"], $headline . "\n" . $body_content));

        $mail->send();
        $masked_email = preg_replace('/(^.).*(@.*$)/', '$1***$2', $to_email);
        error_log("BENEPESO Email Accepted: recipient={$masked_email}; subject={$subject}; message_id=" . $mail->getLastMessageID());
        return true;

        } catch (Exception $e) {
            $error_message = $mail->ErrorInfo ?: $e->getMessage();
            error_log("BENEPESO Email Error (attempt {$attempt}): " . $error_message);

            if ($attempt < 2) {
                usleep(500000);
            }
        }
    }

    return false;
}

function queueBENEPESOStatusEmail(mysqli $conn, int $beneficiary_id, string $status, string $custom_message = '', string $schedule_date = '', string $schedule_place = '', string $last_error = ''): bool {
    $conn->query("CREATE TABLE IF NOT EXISTS notification_outbox (
        notification_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        dedupe_key CHAR(64) NOT NULL UNIQUE,
        beneficiary_id INT NOT NULL,
        status_name VARCHAR(80) NOT NULL,
        custom_message TEXT NULL,
        schedule_date VARCHAR(20) NULL,
        schedule_place VARCHAR(255) NULL,
        attempts INT NOT NULL DEFAULT 0,
        last_error VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_notification_outbox_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $dedupe = hash('sha256', implode('|', [$beneficiary_id, $status, $custom_message, $schedule_date, $schedule_place]));
    $stmt = $conn->prepare("INSERT INTO notification_outbox (dedupe_key,beneficiary_id,status_name,custom_message,schedule_date,schedule_place,last_error) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE last_error=VALUES(last_error), updated_at=NOW()");
    if (!$stmt) return false;
    $last_error = substr($last_error, 0, 500);
    $stmt->bind_param('sisssss', $dedupe, $beneficiary_id, $status, $custom_message, $schedule_date, $schedule_place, $last_error);
    $queued = $stmt->execute();
    $stmt->close();
    return $queued;
}

/**
 * Sends an availment status notification using the beneficiary's current database record.
 */
function sendBENEPESOStatusEmail(mysqli $conn, int $beneficiary_id, string $status, &$error_message = null, string $custom_message = '', string $schedule_date = '', string $schedule_place = ''): bool {
    $error_message = null;
    $stmt = $conn->prepare(
        "SELECT COALESCE(NULLIF(TRIM(b.email), ''), NULLIF(TRIM(b.business_email), '')) AS email,
                COALESCE(
                    NULLIF(TRIM(CONCAT_WS(' ', NULLIF(TRIM(b.first_name), ''), NULLIF(TRIM(b.last_name), ''))), ''),
                    NULLIF(TRIM(b.full_name), ''),
                    NULLIF(TRIM(CONCAT_WS(' ', NULLIF(TRIM(u.first_name), ''), NULLIF(TRIM(u.last_name), ''))), ''),
                    'Applicant'
                ) AS beneficiary_name,
                COALESCE(p.program_name, 'PESO Vinzons Program') AS program_name
         FROM beneficiaries b
         LEFT JOIN programs p ON p.program_id = b.program_id
         LEFT JOIN users u ON u.user_id = b.user_id
         WHERE b.beneficiary_id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        $error_message = "Unable to prepare the applicant contact lookup.";
        error_log("BENEPESO Status Email Error: " . $error_message);
        return false;
    }

    $stmt->bind_param("i", $beneficiary_id);
    $stmt->execute();
    $applicant = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$applicant) {
        $error_message = "Applicant record was not found.";
        error_log("BENEPESO Status Email Error: beneficiary_id={$beneficiary_id}; {$error_message}");
        return false;
    }

    $email = trim((string)($applicant['email'] ?? ''));
    $beneficiary_name = benepeso_recipient_name((string)($applicant['beneficiary_name'] ?? ''));
    $safe_name = htmlspecialchars($beneficiary_name, ENT_QUOTES, 'UTF-8');
    $safe_program = htmlspecialchars((string)$applicant['program_name'], ENT_QUOTES, 'UTF-8');
    $safe_status = htmlspecialchars(trim($status), ENT_QUOTES, 'UTF-8');
    $safe_custom_message = nl2br(htmlspecialchars(trim($custom_message), ENT_QUOTES, 'UTF-8'));
    $schedule_date_text = trim($schedule_date);
    $parsed_schedule_date = DateTime::createFromFormat('!Y-m-d', $schedule_date_text);
    if ($parsed_schedule_date && $parsed_schedule_date->format('Y-m-d') === $schedule_date_text) {
        $schedule_date_text = $parsed_schedule_date->format('F j, Y');
    }
    $safe_schedule_date = htmlspecialchars($schedule_date_text, ENT_QUOTES, 'UTF-8');
    $safe_schedule_place = htmlspecialchars(trim($schedule_place), ENT_QUOTES, 'UTF-8');
    $normalized_status = strtolower(trim($status));
    $program_key = strtoupper((string)$applicant['program_name']);
    $is_spes = strpos($program_key, 'SPES') !== false;
    $is_returning_spes = $is_spes && spes_beneficiary_is_returning($conn, $beneficiary_id);

    switch ($normalized_status) {
        case 'requirements resubmission':
            $subject = $applicant['program_name'] . " Document Resubmission Required";
            $headline = "Please resubmit your requirements";
            $message = "
                <p>PESO Vinzons reviewed the requirements you submitted for <strong>{$safe_program}</strong>, but one or more documents must be corrected or replaced.</p>
                <p><strong>Reason and instructions:</strong><br>{$safe_custom_message}</p>
                <p>Please bring the corrected requirements to the PESO Vinzons office. Your application will remain under document review until the replacement requirements are received.</p>
            ";
            break;

        case 'requirements received':
        case 'requirements recieved':
            $subject = $applicant['program_name'] . " Documents Submitted";
            $headline = "Documents submitted";
            $message = "
                <p>Your document submission for <strong>{$safe_program}</strong> has been recorded by PESO Vinzons.</p>
                <p>Your documents are now under verification. Please keep your registered contact number active in case the office needs additional information.</p>
            ";
            break;

        case 'ongoing':
            $subject = $applicant['program_name'] . " Participation Update";
            $headline = "Program participation started";
            $message = "
                <p>Your participation in <strong>{$safe_program}</strong> is now recorded as <strong>Ongoing</strong>.</p>
                <p>Please follow the schedule and instructions issued by PESO Vinzons and keep all program-related documents for your records.</p>
            ";
            break;

        case 'completed':
            $classification = $is_spes ? spes_beneficiary_classification($conn, $beneficiary_id) : 'none';
            if ($classification === 'graduate') {
                $subject = "Congratulations - You are now a SPES Graduate";
                $headline = "SPES Graduate status recorded";
                $message = "<p>Your participation in <strong>{$safe_program}</strong> has been recorded as <strong>Completed</strong>.</p><p>Congratulations! PESO Vinzons now recognizes you as a <strong>SPES Graduate</strong>. Thank you for your participation in the program.</p><p>Please retain your SPES forms, grades, and supporting documents for your records.</p>";
            } elseif ($classification === 'baby') {
                $subject = "Congratulations - You are now a SPES Baby";
                $headline = "Welcome to the SPES Baby community";
                $message = "<p>Your participation in <strong>{$safe_program}</strong> has been recorded as <strong>Completed</strong>.</p><p>You are now recognized as a <strong>SPES Baby</strong>. For a future SPES batch, you will not need to take the qualifying examination again.</p><p>When a new batch opens, update your SPES form and prepare your latest semester grades and the other documents required by PESO Vinzons.</p>";
            } else {
                $subject = $applicant['program_name'] . " Completion Notice";
                $headline = "Program completion recorded";
                $message = "<p>Your participation in <strong>{$safe_program}</strong> has been recorded as <strong>Completed</strong>.</p><p>Thank you for participating. Please retain your program documents and monitor your account for any final notice from PESO Vinzons.</p>";
            }
            break;

        case 'orientation':
            $subject = $applicant['program_name'] . " Verification Passed and Orientation Schedule";
            $headline = "You passed verification - orientation scheduled";
            $message = "
                <p>Your submitted requirements have been successfully verified by PESO Vinzons. You passed the document-verification stage and may now proceed to orientation for <strong>{$safe_program}</strong>.</p>
                <p>Your orientation is scheduled on <strong>{$safe_schedule_date}</strong> at <strong>{$safe_schedule_place}</strong>.</p>
                <p>Please arrive on time and bring the documents specified by PESO Vinzons. If you cannot attend, contact the office before the scheduled date.</p>
            ";
            break;

        case 'examination':
            $subject = $applicant['program_name'] . " Face-to-Face Examination Schedule";
            $headline = "Your face-to-face examination has been scheduled";
            $message = "
                <p>Your examination for the <strong>{$safe_program}</strong> program will be conducted <strong>face-to-face</strong>.</p>
                <p><strong>Date:</strong> {$safe_schedule_date}<br><strong>Venue:</strong> {$safe_schedule_place}</p>
                <p>Please arrive at least 15 minutes early and bring a valid ID, a pen, and any documents requested by the PESO office. The examination must be taken in person at the stated venue.</p>
                <p>If you cannot attend, contact the PESO Vinzons office before the examination date. Do not reply to this automated email.</p>
            ";
            break;

        case 'exam passed':
            $subject = $applicant['program_name'] . " Examination Result - Passed";
            $headline = "You passed the SPES examination";
            $message = "
                <p>Your examination result for <strong>{$safe_program}</strong> has been recorded as <strong>Passed</strong>.</p>
                <p>Please wait for your official assignment, start schedule, and any further instructions from PESO Vinzons. Keep your registered contact details active.</p>
            ";
            break;

        case 'exam failed':
            $subject = $applicant['program_name'] . " Examination Result";
            $headline = "Your SPES examination result is available";
            $message = "
                <p>Your examination result for <strong>{$safe_program}</strong> has been recorded as <strong>Not Passed</strong>.</p>
                <p>This means you will not proceed to placement for the current application. You may contact PESO Vinzons if you need clarification about the result or future application opportunities.</p>
            ";
            break;

        case 'salary distribution':
            $subject = $applicant['program_name'] . " Salary Distribution Schedule";
            $headline = "Salary distribution announcement";
            $message = "
                <p>The salary distribution for the <strong>{$safe_program}</strong> program is scheduled on <strong>{$safe_schedule_date}</strong> at <strong>{$safe_schedule_place}</strong>.</p>
                <p>Please bring a valid ID and any other documents specified by PESO Vinzons. Follow the instructions provided at the distribution venue.</p>
            ";
            break;

        case 'not qualified':
            $subject = $applicant['program_name'] . " Qualification Update";
            $headline = "Application not qualified";
            $message = "
                <p>After review, your application for <strong>{$safe_program}</strong> has been recorded as <strong>Not Qualified</strong>.</p>
                <p>You may contact PESO Vinzons if you need clarification regarding the result.</p>
            ";
            break;

        case 'cancelled':
            $subject = $applicant['program_name'] . " Participation Cancelled";
            $headline = "Your program participation was cancelled";
            $message = "
                <p>Your participation in the <strong>{$safe_program}</strong> program has been marked as <strong>Cancelled</strong>.</p>
                <p>If you believe this status was recorded incorrectly, please contact PESO Vinzons promptly.</p>
            ";
            break;

        case 'not yet availed':
            $subject = $applicant['program_name'] . " - Approved, Next Step Pending";
            $headline = "Approved - awaiting the next step";
            if (strpos($program_key, 'TUPAD') !== false) {
                $message = "<p>Your application for <strong>{$safe_program}</strong> is approved.</p><p>Your next step is in-person document submission and verification at PESO Vinzons. Follow the document instructions issued by the office before deployment.</p>";
            } elseif (strpos($program_key, 'SPES') !== false) {
                $message = $is_returning_spes
                    ? "<p>Your updated SPES record for <strong>{$safe_program}</strong> is approved.</p><p>As a returning <strong>SPES Baby</strong>, you are exempt from taking the SPES examination again. Please prepare your latest semester grades and all current requirements, then follow PESO Vinzons' document-submission instructions.</p>"
                    : "<p>Your application for <strong>{$safe_program}</strong> is approved.</p><p>Please wait for the official examination schedule and instructions from PESO Vinzons. Keep your registered contact details active.</p>";
            } elseif (strpos($program_key, 'MSME') !== false) {
                $message = "<p>Your profiling record for <strong>{$safe_program}</strong> is approved.</p><p>PESO Vinzons will contact you if another verification step or office action is required. Keep your registered contact details active.</p>";
            } else {
                $message = "<p>Your application for <strong>{$safe_program}</strong> is approved.</p><p>Please wait for the official schedule or next-step instructions from PESO Vinzons.</p>";
            }
            break;

        default:
            $subject = $applicant['program_name'] . " Status Update: " . trim($status);
            $headline = "Your application status has changed";
            $message = "
                <p>Your status for the <strong>{$safe_program}</strong> program has been updated to <strong>{$safe_status}</strong>.</p>
                <p>Please log in to your account or contact PESO Vinzons if you require further information.</p>
            ";
            break;
    }

    if ($safe_custom_message !== '') {
        $message .= "<p><strong>Additional instructions from PESO Vinzons:</strong><br>{$safe_custom_message}</p>";
    }

    $body = "<p>Dear {$safe_name},</p>" . $message;

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'The applicant has no valid email address.';
        error_log("BENEPESO Status Email Error: beneficiary_id={$beneficiary_id}; {$error_message}");
        // A missing/invalid address is permanent until the record is corrected;
        // retrying it every minute cannot succeed and only creates dead queue jobs.
        return false;
    }

    $sent = sendBENEPESOEmail($email, $subject, $headline, $body, $error_message);
    if (!$sent) {
        queueBENEPESOStatusEmail($conn, $beneficiary_id, $status, $custom_message, $schedule_date, $schedule_place, (string)$error_message);
    }
    return $sent;
}
?>
