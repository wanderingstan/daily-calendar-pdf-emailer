<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

/**
 * Daily Calendar Printer Script
 *
 * Fetches Google Calendar events and emails a PDF to printer
 * Run via cron job daily
 */

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

// Load environment variables from .env file
// Place .env file OUTSIDE public_html for security
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} else {
    die("Error: .env file not found at {__DIR__}/.env\n");
}

// Configuration from environment variables
$CALENDAR_URL = $_ENV['CALENDAR_ICAL_URL'];
$PRINTER_EMAIL = $_ENV['PRINTER_EMAIL'];
$TIMEZONE = $_ENV['TIMEZONE'] ?? 'America/Denver';

// Email configuration from environment
$SMTP_HOST = $_ENV['SMTP_HOST'];
$SMTP_PORT = $_ENV['SMTP_PORT'];
$SMTP_USERNAME = $_ENV['SMTP_USERNAME'];
$SMTP_PASSWORD = $_ENV['SMTP_PASSWORD'];
$FROM_EMAIL = $_ENV['FROM_EMAIL'];
$FROM_NAME = $_ENV['FROM_NAME'] ?? 'Calendar Printer';

// Set timezone
date_default_timezone_set($TIMEZONE);

/**
 * Fetch and parse iCal data from Google Calendar
 */
function fetchCalendarEvents($calendarUrl) {
    $icalData = file_get_contents($calendarUrl);
    if ($icalData === false) {
        throw new Exception('Failed to fetch calendar data');
    }

    return parseICalEvents($icalData);
}

/**
 * Simple iCal parser for VEVENT entries
 */
function parseICalEvents($icalData) {
    $events = [];
    $lines = explode("\n", $icalData);
    $currentEvent = null;

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === 'BEGIN:VEVENT') {
            $currentEvent = [];
        } elseif ($line === 'END:VEVENT') {
            if ($currentEvent) {
                $events[] = $currentEvent;
                $currentEvent = null;
            }
        } elseif ($currentEvent !== null && strpos($line, ':') !== false) {
            list($key, $value) = explode(':', $line, 2);

            // Handle common iCal fields
            if ($key === 'SUMMARY') {
                $currentEvent['title'] = $value;
            } elseif ($key === 'DTSTART') {
                $currentEvent['start'] = parseICalDate($value);
            } elseif ($key === 'DTEND') {
                $currentEvent['end'] = parseICalDate($value);
            } elseif (strpos($key, 'DTSTART') === 0) {
                // Handle DTSTART with parameters like DTSTART;VALUE=DATE
                $currentEvent['start'] = parseICalDate($value);
                $currentEvent['all_day'] = strpos($key, 'VALUE=DATE') !== false;
            } elseif ($key === 'DESCRIPTION') {
                $currentEvent['description'] = $value;
            }
        }
    }

    return $events;
}

/**
 * Parse iCal date format to timestamp
 */
function parseICalDate($dateString) {
    // Remove timezone info if present (basic parsing)
    $dateString = preg_replace('/[TZ].*$/', '', $dateString);

    if (strlen($dateString) === 8) {
        // Date only format: YYYYMMDD
        return DateTime::createFromFormat('Ymd', $dateString)->getTimestamp();
    } elseif (strlen($dateString) >= 15) {
        // DateTime format: YYYYMMDDTHHMMSS
        return DateTime::createFromFormat('Ymd\THis', substr($dateString, 0, 15))->getTimestamp();
    }

    return time(); // fallback
}

/**
 * Filter events for today
 */
function getTodayEvents($events) {
    $today = date('Y-m-d');
    $todayEvents = [];

    foreach ($events as $event) {
        $eventDate = date('Y-m-d', $event['start']);
        if ($eventDate === $today) {
            $todayEvents[] = $event;
        }
    }

    // Sort by start time
    usort($todayEvents, function($a, $b) {
        return $a['start'] - $b['start'];
    });

    return $todayEvents;
}

/**
 * Create PDF using TCPDF library
 */
function createCalendarPDF($events) {
    // Create new PDF document
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

    // Set document information
    $pdf->SetCreator('Calendar Printer');
    $pdf->SetTitle('Daily Calendar - ' . date('F j, Y'));

    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);

    // Add a page
    $pdf->AddPage();

    // Set font
    $pdf->SetFont('helvetica', 'B', 20);

    // Title
    $pdf->Cell(0, 15, 'Daily Calendar', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 16);
    $pdf->Cell(0, 10, date('l, F j, Y'), 0, 1, 'C');
    $pdf->Ln(10);

    // Events
    if (empty($events)) {
        $pdf->SetFont('helvetica', 'I', 14);
        $pdf->Cell(0, 10, 'No events scheduled for today', 0, 1, 'C');
    } else {
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 8, 'Today\'s Events:', 0, 1, 'L');
        $pdf->Ln(5);

        foreach ($events as $event) {
            $pdf->SetFont('helvetica', 'B', 12);

            // Format time
            if (isset($event['all_day']) && $event['all_day']) {
                $timeStr = 'All Day';
            } else {
                $timeStr = date('g:i A', $event['start']);
                if (isset($event['end']) && $event['end'] != $event['start']) {
                    $timeStr .= ' - ' . date('g:i A', $event['end']);
                }
            }

            // Event title and time
            $pdf->Cell(0, 8, $timeStr, 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 11);
            $pdf->Cell(0, 6, $event['title'] ?? 'Untitled Event', 0, 1, 'L');

            // Description if available
            if (!empty($event['description'])) {
                $pdf->SetFont('helvetica', 'I', 9);
                $pdf->MultiCell(0, 5, substr($event['description'], 0, 200), 0, 'L');
            }

            $pdf->Ln(3);
        }
    }

    // Generate PDF string
    return $pdf->Output('', 'S');
}

/**
 * Send email with PDF attachment using PHPMailer
 */
function sendCalendarEmail($pdfContent, $printerEmail) {
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = $GLOBALS['SMTP_HOST'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $GLOBALS['SMTP_USERNAME'];
        $mail->Password   = $GLOBALS['SMTP_PASSWORD'];
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $GLOBALS['SMTP_PORT'];

        // Recipients
        $mail->setFrom($GLOBALS['FROM_EMAIL'], $GLOBALS['FROM_NAME']);
        $mail->addAddress($printerEmail);

        // Content
        $mail->isHTML(false);
        $mail->Subject = 'Daily Calendar - ' . date('F j, Y');
        $mail->Body    = 'Daily calendar printout attached.';

        // Add PDF attachment
        $mail->addStringAttachment($pdfContent, 'daily-calendar-' . date('Y-m-d') . '.pdf', 'base64', 'application/pdf');

        $mail->send();
        echo "Calendar email sent successfully!\n";

    } catch (Exception $e) {
        throw new Exception("Email could not be sent. Mailer Error: {$mail->ErrorInfo}");
    }
}

/**
 * Main execution
 */
try {
    echo "Fetching calendar events...\n";
    $events = fetchCalendarEvents($CALENDAR_URL);

    echo "Filtering today's events...\n";
    $todayEvents = getTodayEvents($events);

    echo "Found " . count($todayEvents) . " events for today.\n";

    echo "Creating PDF...\n";
    $pdfContent = createCalendarPDF($todayEvents);

    echo "Sending to printer...\n";
    sendCalendarEmail($pdfContent, $PRINTER_EMAIL);

    echo "Daily calendar printed successfully!\n";

} catch (Exception $e) {
    error_log("Calendar printer error: " . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>