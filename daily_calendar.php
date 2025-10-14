<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

/**
 * Daily Calendar Printer Script
 *
 * Fetches Google Calendar events and emails a PDF to printer
 * Run via cron job daily
 *
 * Usage:
 *   php daily_calendar.php              - Send to printer via email
 *   php daily_calendar.php --test       - Save PDF to file for testing
 *   php daily_calendar.php --output=filename.pdf - Save to specific file
 */

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use ICal\ICal;

// Parse command line arguments
$testMode = false;
$outputFile = null;

if (isset($argv)) {
    foreach ($argv as $arg) {
        if ($arg === '--test') {
            $testMode = true;
            $outputFile = 'calendar-test-' . date('Y-m-d') . '.pdf';
        } elseif (strpos($arg, '--output=') === 0) {
            $testMode = true;
            $outputFile = substr($arg, 9);
        }
    }
}

// Load environment variables from .env file
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} else {
    die("Error: .env file not found at " . __DIR__ . "/.env\n");
}

// Configuration from environment variables
// Support multiple calendar URLs separated by commas
$CALENDAR_URLS = array_map('trim', explode(',', $_ENV['CALENDAR_ICAL_URL']));
$PRINTER_EMAIL = $_ENV['PRINTER_EMAIL'];
$TIMEZONE = $_ENV['TIMEZONE'] ?? 'America/Denver';
$CALENDAR_TITLE = $_ENV['CALENDAR_TITLE'] ?? 'Daily Calendar';

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
 * Fetch and parse iCal data from multiple calendars
 */
function fetchAllCalendarEvents($calendarUrls) {
    $allEvents = [];

    foreach ($calendarUrls as $index => $url) {
        try {
            echo "Fetching calendar " . ($index + 1) . " of " . count($calendarUrls) . "...\n";
            echo "  URL: " . $url . "\n";
            $events = fetchCalendarEvents($url);
            echo "  Found " . count($events) . " events (including recurring instances)\n";

            // Tag each event with its source URL for debugging
            foreach ($events as &$event) {
                $event['source_url'] = $url;
            }

            $allEvents = array_merge($allEvents, $events);
        } catch (Exception $e) {
            echo "  Warning: Failed to fetch calendar from " . substr($url, 0, 50) . "...: " . $e->getMessage() . "\n";
            // Continue with other calendars even if one fails
        }
    }

    return $allEvents;
}

/**
 * Fetch and parse iCal data from Google Calendar using ICal library
 */
function fetchCalendarEvents($calendarUrl) {
    global $TIMEZONE;

    try {
        $ical = new ICal('ICal.ics', array(
            'defaultSpan'                 => 2,     // Default span in years
            'defaultTimeZone'             => $TIMEZONE,
            'defaultWeekStart'            => 'MO',
            'disableCharacterReplacement' => false,
            'filterDaysAfter'             => null,  // Don't filter - we'll do it ourselves
            'filterDaysBefore'            => null,  // Don't filter - we'll do it ourselves
            'skipRecurrence'              => false, // IMPORTANT: process recurring events
        ));

        $ical->initUrl($calendarUrl);

        $events = [];
        $icalEvents = $ical->events();

        foreach ($icalEvents as $event) {
            // Get the start timestamp
            $startTimestamp = $ical->iCalDateToUnixTimestamp($event->dtstart);
            $endTimestamp = isset($event->dtend) ? $ical->iCalDateToUnixTimestamp($event->dtend) : null;

            // Determine if all-day by comparing start and end times
            $isAllDay = false;
            if ($endTimestamp && $startTimestamp) {
                // If times are exactly midnight and 24 hours apart, likely all-day
                $startHour = date('H', $startTimestamp);
                $startMin = date('i', $startTimestamp);
                $isAllDay = ($startHour == '00' && $startMin == '00');
            }

            $events[] = [
                'title' => $event->summary ?? 'Untitled Event',
                'start' => $startTimestamp,
                'end' => $endTimestamp,
                'description' => $event->description ?? '',
                'all_day' => $isAllDay,
            ];
        }

        return $events;

    } catch (Exception $e) {
        throw new Exception('Failed to fetch calendar data: ' . $e->getMessage());
    }
}

/**
 * Filter events for today
 */
function getTodayEvents($events) {
    $today = date('Y-m-d');
    $todayEvents = [];

    echo "\nDEBUG: Looking for events on: $today\n";
    echo "DEBUG: First 5 events from all calendars:\n";

    $count = 0;
    foreach ($events as $event) {
        if (!isset($event['start'])) {
            continue; // Skip events without start time
        }

        $eventDate = date('Y-m-d', $event['start']);

        // Debug first few events
        // if ($count < 5) {
        //     $sourceUrl = isset($event['source_url']) ? $event['source_url'] : 'unknown';
        //     echo "  - " . ($event['title'] ?? 'No title') . " on $eventDate\n";
        //     echo "    Source: $sourceUrl\n";
        //     $count++;
        // }

        if ($eventDate === $today) {
            $todayEvents[] = $event;
        }
    }

    // Show today's events with their sources
    if (count($todayEvents) > 0) {
        echo "\nDEBUG: Events found for TODAY ($today):\n";
        foreach ($todayEvents as $event) {
            $timeStr = date('g:i A', $event['start']);
            $sourceUrl = isset($event['source_url']) ? $event['source_url'] : 'unknown';
            echo "  - $timeStr: " . ($event['title'] ?? 'No title') . "\n";
            echo "    Source: $sourceUrl...\n";
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
    global $CALENDAR_TITLE;

    // Create new PDF document
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

    // Set document information
    $pdf->SetCreator('Calendar Printer');
    $pdf->SetTitle($CALENDAR_TITLE . ' - ' . date('F j, Y'));

    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);

    // Add a page
    $pdf->AddPage();

    // Title - MUCH larger font (was 20, now 40)
    $pdf->SetFont('helvetica', 'B', 40);
    $pdf->Cell(0, 25, $CALENDAR_TITLE, 0, 1, 'C');

    // Date - MUCH larger font (was 16, now 32)
    $pdf->SetFont('helvetica', '', 32);
    $pdf->Cell(0, 20, date('l, F j, Y'), 0, 1, 'C');
    $pdf->Ln(15);

    // Events
    if (empty($events)) {
        $pdf->SetFont('helvetica', 'I', 24);
        $pdf->Cell(0, 15, 'No events scheduled for today', 0, 1, 'C');
    } else {
        $pdf->SetFont('helvetica', 'B', 24);
        $pdf->Cell(0, 12, 'Today\'s Events:', 0, 1, 'L');
        $pdf->Ln(8);

        foreach ($events as $event) {
            // Time - MUCH larger and bold (was 12, now 28)
            $pdf->SetFont('helvetica', 'B', 28);

            // Format time
            if (isset($event['all_day']) && $event['all_day']) {
                $timeStr = 'All Day';
            } else {
                $timeStr = date('g:i A', $event['start']);
                if (isset($event['end']) && $event['end'] != $event['start']) {
                    $timeStr .= ' - ' . date('g:i A', $event['end']);
                }
            }

            $pdf->Cell(0, 14, $timeStr, 0, 1, 'L');

            // Event title - DARK BLUE and MUCH larger (was 11, now 24)
            $pdf->SetFont('helvetica', 'B', 24);
            $pdf->SetTextColor(0, 51, 102); // Dark blue color
            $pdf->Cell(0, 12, $event['title'] ?? 'Untitled Event', 0, 1, 'L');

            // Reset color to black for description
            $pdf->SetTextColor(0, 0, 0);

            // Description if available - larger (was 9, now 18)
            if (!empty($event['description'])) {
                $pdf->SetFont('helvetica', '', 18);
                $pdf->MultiCell(0, 9, substr($event['description'], 0, 200), 0, 'L');
            }

            $pdf->Ln(8);
        }
    }

    // Return PDF object (not string) for flexibility
    return $pdf;
}

/**
 * Save PDF to file
 */
function savePDFToFile($pdf, $filename) {
    $pdf->Output(__DIR__ . '/' . $filename, 'F');
    echo "PDF saved to: " . __DIR__ . '/' . $filename . "\n";
}

/**
 * Send email with PDF attachment using PHPMailer
 */
function sendCalendarEmail($pdf, $printerEmail) {
    // Get PDF as string
    $pdfContent = $pdf->Output('', 'S');

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
    if ($testMode) {
        echo "Running in TEST mode - PDF will be saved to file\n";
    }

    echo "Fetching calendar events from " . count($CALENDAR_URLS) . " calendar(s)...\n";
    $events = fetchAllCalendarEvents($CALENDAR_URLS);

    echo "\nTotal events fetched: " . count($events) . "\n";

    echo "Filtering today's events...\n";
    $todayEvents = getTodayEvents($events);

    echo "Found " . count($todayEvents) . " events for today.\n";

    echo "Creating PDF...\n";
    $pdf = createCalendarPDF($todayEvents);

    if ($testMode) {
        // Save to file for testing
        savePDFToFile($pdf, $outputFile);
        echo "Test complete! Check the PDF file.\n";
    } else {
        // Send to printer via email
        echo "Sending to printer...\n";
        sendCalendarEmail($pdf, $PRINTER_EMAIL);
        echo "Daily calendar printed successfully!\n";
    }

} catch (Exception $e) {
    error_log("Calendar printer error: " . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>