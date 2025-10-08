# Daily Calendar Printer Setup

## Required PHP Libraries

Install these libraries using Composer:

```bash
composer require phpmailer/phpmailer
composer require tecnickcom/tcpdf
composer require vlucas/phpdotenv
```

## Folder Structure (Recommended for Security)

```
~/                              (your home directory)
├── .env                        (sensitive config - NOT in public_html!)
├── calendar_printer/           (your script directory)
│   ├── daily_calendar.php
│   ├── composer.json
│   └── vendor/
└── public_html/                (web-accessible files)
    └── (your website files)
```

## Configuration Steps

### 1. Create .env File
Create a file called `.env` in your home directory (one level above public_html):

```bash
# Calendar Configuration
CALENDAR_ICAL_URL="https://calendar.google.com/calendar/ical/vern.and.anabeth%40gmail.com/public/basic.ics"
PRINTER_EMAIL="vern.and.stans.printer@print.epsonconnect.com"
TIMEZONE="America/Denver"

# SMTP Configuration
SMTP_HOST="smtp.gmail.com"
SMTP_PORT="587"
SMTP_USERNAME="your-email@gmail.com"
SMTP_PASSWORD="your-app-password-here"
FROM_EMAIL="your-email@gmail.com"
FROM_NAME="Calendar Printer"
```

**Important:** 
- Replace the values with your actual credentials
- For the calendar URL, get the "Secret address in iCal format" from Google Calendar settings
- For Gmail: Enable 2FA and generate an App Password

### 2. Secure the .env File
Set proper permissions so only you can read it:

```bash
chmod 600 ~/.env
```

### 3. Get Your Calendar iCal URL
1. Go to Google Calendar settings
2. Find the calendar you want to share
3. Go to "Integrate calendar" section
4. Copy the "Secret address in iCal format" URL
5. Put it in your .env file

### 4. Set Up Cron Job
Add this to your crontab to run daily at 7 AM:

```bash
crontab -e
```

Add this line (adjust the path to match your setup):
```
0 7 * * * /usr/bin/php /home/yourusername/calendar_printer/daily_calendar.php
```

## Testing

Run the script manually first to test:
```bash
php daily_calendar.php
```

## Troubleshooting

### Common Issues:
1. **Calendar not found**: Make sure the iCal URL is correct and accessible
2. **Email not sending**: Check SMTP credentials and Gmail app password
3. **PDF creation fails**: Ensure TCPDF is installed via Composer
4. **Permission errors**: Make sure PHP has write permissions for temporary files

### Debug Mode:
Add this to the top of the script for more verbose output:
```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

## Security Notes

- ✅ Keep `.env` file OUTSIDE of public_html (not web-accessible)
- ✅ Set `.env` permissions to 600 (only you can read it)
- ✅ Never commit `.env` to version control (add to .gitignore if using git)
- ✅ Use Gmail App Passwords, not your main password
- ✅ Regularly update PHP libraries via Composer
- ✅ Make sure the script file isn't publicly accessible via web browser

## Why This Approach is Better

**Security Benefits:**
- Credentials are separate from code
- .env file is outside web root (can't be accessed via browser)
- Easy to update credentials without touching code
- Different environments can use different .env files
- No risk of accidentally sharing credentials in code

## Printer Requirements

Make sure your Epson printer:
- Is connected to Epson Connect
- Has the correct email address configured
- Is set to automatically print incoming emails

