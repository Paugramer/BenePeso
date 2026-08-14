<?php
// email_helper.php

// Adjust these paths if your PHPMailer folder is located somewhere else
require_once "PHPMailer/src/PHPMailer.php";
require_once "PHPMailer/src/SMTP.php";
require_once "PHPMailer/src/Exception.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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
        $mail->setFrom($smtp_username, "BENEPESO");
        $mail->Sender = $smtp_username;
        $mail->addReplyTo($smtp_username, "BENEPESO Support");
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
        $mail->XMailer = "BENEPESO Notification Service";
        $mail->Subject = $subject;
        
        $current_year = date("Y");

        // Professional Green BENEPESO HTML Template
        $mail->Body = "
        <div style='font-family: Arial, sans-serif; background-color: #f4f8f5; padding: 40px 20px; color: #163524;'>
            <div style='max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.05); border: 1px solid #dbe6df;'>
                
                <!-- Header -->
                <div style='background-color: #1f7a54; padding: 30px; text-align: center;'>
                    <h1 style='color: #ffffff; margin: 0; font-size: 26px; letter-spacing: 1px; font-weight: 800;'>BENEPESO</h1>
                    <p style='color: #e6f4ed; margin: 5px 0 0; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;'>System Notification</p>
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
                        © {$current_year} BENEPESO • Public Employment Service Office<br>Municipality of Vinzons, Camarines Norte
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

/**
 * Sends an availment status notification using the beneficiary's current database record.
 */
function sendBENEPESOStatusEmail(mysqli $conn, int $beneficiary_id, string $status, &$error_message = null, string $custom_message = '', string $schedule_date = '', string $schedule_place = ''): bool {
    $error_message = null;
    $stmt = $conn->prepare(
        "SELECT COALESCE(NULLIF(TRIM(b.email), ''), NULLIF(TRIM(b.business_email), '')) AS email,
                COALESCE(NULLIF(TRIM(b.first_name), ''), NULLIF(TRIM(b.full_name), ''), 'Applicant') AS first_name,
                COALESCE(p.program_name, 'BENEPESO Program') AS program_name
         FROM beneficiaries b
         LEFT JOIN programs p ON p.program_id = b.program_id
         WHERE b.beneficiary_id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        $error_message = "Unable to prepare the applicant email lookup.";
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
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "The applicant has no valid email address.";
        error_log("BENEPESO Status Email Error: beneficiary_id={$beneficiary_id}; {$error_message}");
        return false;
    }

    $safe_name = htmlspecialchars((string)($applicant['first_name'] ?: 'Applicant'), ENT_QUOTES, 'UTF-8');
    $safe_program = htmlspecialchars((string)$applicant['program_name'], ENT_QUOTES, 'UTF-8');
    $safe_status = htmlspecialchars(trim($status), ENT_QUOTES, 'UTF-8');
    $safe_custom_message = nl2br(htmlspecialchars(trim($custom_message), ENT_QUOTES, 'UTF-8'));
    $safe_schedule_date = htmlspecialchars(trim($schedule_date), ENT_QUOTES, 'UTF-8');
    $safe_schedule_place = htmlspecialchars(trim($schedule_place), ENT_QUOTES, 'UTF-8');
    $normalized_status = strtolower(trim($status));

    switch ($normalized_status) {
        case 'requirements received':
        case 'requirements recieved':
            $subject = $applicant['program_name'] . " Documents Received";
            $headline = "We received your requirements";
            $message = "
                <p>Your submitted requirements for the <strong>{$safe_program}</strong> program have been received by the PESO office.</p>
                <p>Our team will review and verify your documents. Please keep your registered contact number available in case additional information is needed.</p>
            ";
            break;

        case 'ongoing':
            $subject = $applicant['program_name'] . " Participation Is Now Ongoing";
            $headline = "Your program participation has started";
            $message = "
                <p>Your participation in the <strong>{$safe_program}</strong> program is now officially <strong>Ongoing</strong>.</p>
                <p>Please follow the schedule and instructions provided by the PESO office. Attend all required activities and keep any documents issued during the program.</p>
            ";
            break;

        case 'completed':
            $subject = $applicant['program_name'] . " Successfully Completed";
            $headline = "Your program has been completed";
            $message = "
                <p>Your participation in the <strong>{$safe_program}</strong> program has been recorded as <strong>Completed</strong>.</p>
                <p>Thank you for participating. Please keep your program records and monitor your BENEPESO account for any final announcements or follow-up instructions.</p>
            ";
            break;

        case 'orientation':
            $subject = $applicant['program_name'] . " Orientation Schedule";
            $headline = "Your orientation has been scheduled";
            $message = "
                <p>Your orientation for the <strong>{$safe_program}</strong> program is scheduled on <strong>{$safe_schedule_date}</strong> at <strong>{$safe_schedule_place}</strong>.</p>
                <p>Please arrive on time and bring any documents requested by the PESO office.</p>
            ";
            break;

        case 'salary distribution':
            $subject = $applicant['program_name'] . " Salary Distribution Schedule";
            $headline = "Salary distribution announcement";
            $message = "
                <p>The salary distribution for the <strong>{$safe_program}</strong> program is scheduled on <strong>{$safe_schedule_date}</strong> at <strong>{$safe_schedule_place}</strong>.</p>
                <p>Please bring a valid ID and follow the instructions provided by the PESO office.</p>
            ";
            break;

        case 'not qualified':
            $subject = $applicant['program_name'] . " Qualification Update";
            $headline = "Application not qualified";
            $message = "
                <p>After evaluation, your application for the <strong>{$safe_program}</strong> program has been marked as <strong>Not Qualified</strong>.</p>
                <p>Please contact the PESO office if you need clarification about this decision.</p>
            ";
            break;

        case 'cancelled':
            $subject = $applicant['program_name'] . " Participation Cancelled";
            $headline = "Your program participation was cancelled";
            $message = "
                <p>Your participation in the <strong>{$safe_program}</strong> program has been marked as <strong>Cancelled</strong>.</p>
                <p>If you believe this was recorded incorrectly or need further clarification, please contact the PESO office as soon as possible.</p>
            ";
            break;

        case 'not yet availed':
            $subject = $applicant['program_name'] . " Next Steps Pending";
            $headline = "Please wait for your program schedule";
            $message = "
                <p>Your availment for the <strong>{$safe_program}</strong> program has not started yet.</p>
                <p>Please keep your registered contact details active and wait for the official schedule or further instructions from the PESO office.</p>
            ";
            break;

        default:
            $subject = $applicant['program_name'] . " Status Update: " . trim($status);
            $headline = "Your application status has changed";
            $message = "
                <p>Your status for the <strong>{$safe_program}</strong> program has been updated to <strong>{$safe_status}</strong>.</p>
                <p>Please log in to your BENEPESO account or contact the PESO office if you require further information.</p>
            ";
            break;
    }

    if ($safe_custom_message !== '') {
        $message .= "<p><strong>Message from PESO:</strong><br>{$safe_custom_message}</p>";
    }

    $body = "<p>Dear {$safe_name},</p>" . $message;

    return sendBENEPESOEmail($email, $subject, $headline, $body, $error_message);
}
?>
