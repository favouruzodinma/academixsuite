# Cron Job System Documentation

## Table of Contents
1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Installation](#installation)
4. [Available Tasks](#available-tasks)
5. [cPanel Setup](#cpanel-setup)
6. [Usage Examples](#usage-examples)
7. [Monitoring & Troubleshooting](#monitoring--troubleshooting)
8. [API Reference](#api-reference)

---

## Overview

This is a production-ready cron job system for the AcademixSuite platform. It handles:

- **Email Queue Processing**: Batch email sending with retry logic
- **Scheduled Emails**: Send emails at specific future times
- **School Trial Expiry**: Suspend expired school trials with email notifications
- **Student Account Suspension**: Automated suspension with notifications
- **Scheduled Announcements**: Publish announcements at specified times
- **Bulk Email Campaigns**: Send emails to multiple recipients
- **Automatic Cleanup**: Remove old logs and data

### Key Features

✅ **File-based locking** - Prevents concurrent execution  
✅ **Comprehensive logging** - File and database logging  
✅ **Error handling** - Graceful failure with retry logic  
✅ **Idempotent operations** - Safe to run multiple times  
✅ **Security** - CLI-only execution, web access blocked  
✅ **Monitoring** - Execution history and statistics  

---

## Architecture

### Directory Structure

```
cron/
├── cron.php                    # Central dispatcher (main entry point)
├── .htaccess                   # Security (blocks web access)
├── lib/
│   ├── CronLock.php           # File-based locking mechanism
│   └── CronLogger.php         # Logging system
├── tasks/
│   ├── process_email_queue.php
│   ├── process_school_trials.php
│   ├── process_student_suspensions.php
│   ├── publish_scheduled_announcements.php
│   ├── retry_failed_emails.php
│   └── cleanup_old_logs.php
├── locks/                      # Lock files (auto-created)
└── logs/                       # Log files (auto-created)
    └── cron.log
```

### Database Tables

| Table | Purpose |
|-------|---------|
| `email_queue` | Stores emails to be sent |
| `bulk_email_campaigns` | Manages bulk email campaigns |
| `scheduled_announcements` | Announcements to publish later |
| `student_suspension_queue` | Students to suspend |
| `cron_logs` | Detailed cron execution logs |
| `cron_execution_history` | Summary of each cron run |
| `email_suppression_list` | Bounced/unsubscribed emails |
| `cron_schedules` | Dynamic cron schedule configuration |

---

## Installation

### Step 1: Run Database Migration

```bash
# Navigate to your project directory
cd /home/username/public_html

# Run the migration
mysql -u your_username -p your_database < database/migrations/002_create_cron_tables.sql
```

Or use phpMyAdmin:
1. Login to phpMyAdmin
2. Select your database
3. Go to "Import" tab
4. Upload `database/migrations/002_create_cron_tables.sql`
5. Click "Go"

### Step 2: Set Permissions

```bash
# Make cron.php executable
chmod +x cron/cron.php

# Ensure directories are writable
chmod 755 cron/locks
chmod 755 cron/logs
```

### Step 3: Test Installation

```bash
# Test the dispatcher
php cron/cron.php

# Test a specific task (dry run)
php cron/cron.php process_email_queue --dry-run
```

---

## Available Tasks

### 1. process_email_queue

**Purpose**: Process pending emails from the queue  
**Schedule**: Every 5 minutes  
**Cron**: `*/5 * * * *`

**Options**:
- `--limit=N` - Process N emails (default: 50)
- `--dry-run` - Simulate without sending

**Example**:
```bash
php cron/cron.php process_email_queue --limit=100
```

---

### 2. process_school_trials

**Purpose**: Suspend schools whose trial has ended and notify the school administrator  
**Schedule**: Every hour  
**Cron**: `0 * * * *`

**Options**:
- `--dry-run` - Simulate without suspending or sending email
- `--limit=N` - Process N schools (default: 100)

**Example**:
```bash
php cron/cron.php process_school_trials --dry-run
```

**Features**:
- ✅ Idempotent status update
- ✅ Skips schools with active subscriptions
- ✅ Sends the shared professional trial-ended email template
- ✅ Writes platform audit logs when available

---

### 3. process_student_suspensions

**Purpose**: Suspend student accounts based on suspension queue  
**Schedule**: Every hour  
**Cron**: `0 * * * *`

**Options**:
- `--dry-run` - Simulate without suspending
- `--limit=N` - Process N suspensions (default: 100)

**Example**:
```bash
php cron/cron.php process_student_suspensions --dry-run
```

**Features**:
- ✅ Idempotent (safe to run multiple times)
- ✅ Sends email notification to student
- ✅ Supports temporary and permanent suspensions

---

### 4. publish_scheduled_announcements

**Purpose**: Publish announcements at scheduled time  
**Schedule**: Every 15 minutes  
**Cron**: `*/15 * * * *`

**Options**:
- `--dry-run` - Simulate without publishing
- `--limit=N` - Process N announcements (default: 50)

**Example**:
```bash
php cron/cron.php publish_scheduled_announcements
```

---

### 5. retry_failed_emails

**Purpose**: Retry emails that failed to send  
**Schedule**: Every 6 hours  
**Cron**: `0 */6 * * *`

**Options**:
- `--limit=N` - Process N emails (default: 100)
- `--dry-run` - Simulate without retrying

**Features**:
- ✅ Exponential backoff (waits longer between retries)
- ✅ Respects max_attempts limit
- ✅ Automatically marks as permanently failed after max attempts

**Example**:
```bash
php cron/cron.php retry_failed_emails
```

---

### 6. cleanup_old_logs

**Purpose**: Clean up old logs and data  
**Schedule**: Daily at 2 AM  
**Cron**: `0 2 * * *`

**Options**:
- `--logs-days=N` - Keep logs for N days (default: 30)
- `--emails-days=N` - Keep emails for N days (default: 7)
- `--history-days=N` - Keep execution history for N days (default: 90)
- `--dry-run` - Simulate without deleting

**Example**:
```bash
php cron/cron.php cleanup_old_logs --emails-days=14
```

---

## cPanel Setup

### Method 1: Using cPanel Cron Jobs Interface

1. **Login to cPanel**
2. **Navigate to**: Advanced → Cron Jobs
3. **Add New Cron Job** for each task:

#### Process Email Queue (Every 5 minutes)
```
Minute: */5
Hour: *
Day: *
Month: *
Weekday: *
Command: /usr/bin/php /home/username/public_html/cron/cron.php process_email_queue
```

#### Process Student Suspensions (Every hour)
```
Minute: 0
Hour: *
Day: *
Month: *
Weekday: *
Command: /usr/bin/php /home/username/public_html/cron/cron.php process_student_suspensions
```

#### Publish Scheduled Announcements (Every 15 minutes)
```
Minute: */15
Hour: *
Day: *
Month: *
Weekday: *
Command: /usr/bin/php /home/username/public_html/cron/cron.php publish_scheduled_announcements
```

#### Retry Failed Emails (Every 6 hours)
```
Minute: 0
Hour: */6
Day: *
Month: *
Weekday: *
Command: /usr/bin/php /home/username/public_html/cron/cron.php retry_failed_emails
```

#### Cleanup Old Logs (Daily at 2 AM)
```
Minute: 0
Hour: 2
Day: *
Month: *
Weekday: *
Command: /usr/bin/php /home/username/public_html/cron/cron.php cleanup_old_logs
```

### Method 2: Using SSH (Advanced)

```bash
# Edit crontab
crontab -e

# Add these lines:
*/5 * * * * /usr/bin/php /home/username/public_html/cron/cron.php process_email_queue
0 * * * * /usr/bin/php /home/username/public_html/cron/cron.php process_school_trials
0 * * * * /usr/bin/php /home/username/public_html/cron/cron.php process_student_suspensions
*/15 * * * * /usr/bin/php /home/username/public_html/cron/cron.php publish_scheduled_announcements
0 */6 * * * /usr/bin/php /home/username/public_html/cron/cron.php retry_failed_emails
0 2 * * * /usr/bin/php /home/username/public_html/cron/cron.php cleanup_old_logs
```

### Finding PHP Path

If `/usr/bin/php` doesn't work, find the correct path:

```bash
which php
# or
whereis php
```

Common paths:
- `/usr/bin/php`
- `/usr/local/bin/php`
- `/opt/cpanel/ea-php74/root/usr/bin/php` (cPanel)

---

## Usage Examples

### Adding Emails to Queue (PHP Code)

```php
<?php
require_once __DIR__ . '/includes/autoload.php';

$db = Database::getPlatformConnection();

// Add immediate email
$stmt = $db->prepare("
    INSERT INTO email_queue 
    (to_email, to_name, subject, html_content, text_content, priority, type, status, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())
");

$stmt->execute([
    'student@example.com',
    'John Doe',
    'Welcome to AcademixSuite',
    '<h1>Welcome!</h1><p>Your account is ready.</p>',
    'Welcome! Your account is ready.',
    5, // priority (1=highest, 10=lowest)
    'welcome'
]);

echo "Email queued successfully!\n";
```

### Scheduling an Email

```php
<?php
// Schedule email for tomorrow at 9 AM
$scheduledFor = date('Y-m-d 09:00:00', strtotime('+1 day'));

$stmt = $db->prepare("
    INSERT INTO email_queue 
    (to_email, subject, html_content, scheduled_for, priority, type, status, created_at, updated_at)
    VALUES (?, ?, ?, ?, 3, 'scheduled', 'pending', NOW(), NOW())
");

$stmt->execute([
    'student@example.com',
    'Reminder: Class Tomorrow',
    '<p>Don\'t forget your class tomorrow!</p>',
    $scheduledFor
]);
```

### Scheduling a Student Suspension

```php
<?php
// Suspend student tomorrow due to payment expiration
$scheduledFor = date('Y-m-d H:i:s', strtotime('+1 day'));

$stmt = $db->prepare("
    INSERT INTO student_suspension_queue 
    (school_id, student_id, reason, suspension_type, scheduled_for, status, created_at, updated_at)
    VALUES (?, ?, 'payment_expired', 'temporary', ?, 'pending', NOW(), NOW())
");

$stmt->execute([
    123, // school_id
    456, // student_id
    $scheduledFor
]);
```

### Scheduling an Announcement

```php
<?php
// Schedule announcement for next Monday at 8 AM
$scheduledFor = date('Y-m-d 08:00:00', strtotime('next Monday'));

$stmt = $db->prepare("
    INSERT INTO scheduled_announcements 
    (school_id, title, content, type, target_audience, scheduled_for, send_email, status, created_at, updated_at)
    VALUES (?, ?, ?, 'general', 'all', ?, 1, 'scheduled', NOW(), NOW())
");

$stmt->execute([
    123, // school_id (NULL for platform-wide)
    'Important Update',
    'We have an important announcement for all students...',
    $scheduledFor
]);
```

---

## Monitoring & Troubleshooting

### Check Cron Logs

```bash
# View recent cron activity
tail -f cron/logs/cron.log

# View last 100 lines
tail -n 100 cron/logs/cron.log

# Search for errors
grep ERROR cron/logs/cron.log
```

### Check Database Logs

```sql
-- Recent cron executions
SELECT * FROM cron_execution_history 
ORDER BY started_at DESC 
LIMIT 20;

-- Failed executions
SELECT * FROM cron_execution_history 
WHERE status = 'failed' 
ORDER BY started_at DESC;

-- Recent errors
SELECT * FROM cron_logs 
WHERE level = 'ERROR' 
ORDER BY created_at DESC 
LIMIT 50;

-- Email queue statistics
SELECT status, COUNT(*) as count 
FROM email_queue 
GROUP BY status;
```

### Check Lock Status

```bash
# List active locks
ls -la cron/locks/

# View lock details
cat cron/locks/process_email_queue.lock
```

### Common Issues

#### Issue: Cron not executing

**Solutions**:
1. Check PHP path: `which php`
2. Check file permissions: `ls -la cron/cron.php`
3. Check cPanel cron job list
4. Check error logs: `tail /var/log/cron`

#### Issue: "Task is already running"

**Cause**: Lock file exists from previous run

**Solutions**:
1. Wait for task to complete
2. If stuck, manually remove lock: `rm cron/locks/task_name.lock`

#### Issue: Emails not sending

**Solutions**:
1. Check email queue: `SELECT * FROM email_queue WHERE status = 'failed'`
2. Check email service configuration
3. Check suppression list: `SELECT * FROM email_suppression_list`
4. Run with dry-run to test: `php cron/cron.php process_email_queue --dry-run`

---

## API Reference

### CronLock Class

```php
$lock = new CronLock('task_name');

// Acquire lock
if ($lock->acquire()) {
    // Task is not running, proceed
    // ... do work ...
    $lock->release();
} else {
    // Task is already running
}

// Check if locked
if ($lock->isLocked()) {
    // Task is running
}

// Get lock info
$info = $lock->getLockInfo();
// Returns: ['task', 'pid', 'locked_at', 'hostname']
```

### CronLogger Class

```php
$logger = new CronLogger('task_name', $dbConnection);

// Log levels
$logger->info('Message', ['context' => 'data']);
$logger->warning('Warning message');
$logger->error('Error message', ['error' => $e->getMessage()]);
$logger->success('Success message');

// Task lifecycle logging
$logger->logTaskStart();
$logger->logTaskComplete($executionTime, $memoryUsed);
$logger->logTaskFailure($exception, $executionTime);
```

### Creating Custom Tasks

Create a new file in `cron/tasks/your_task.php`:

```php
<?php
/**
 * Custom Task Description
 * 
 * SCHEDULE: When to run
 * CRON: Cron expression
 */

function executeTask($options, $logger) {
    $logger->info("Starting custom task");
    
    try {
        // Your task logic here
        $db = Database::getPlatformConnection();
        
        // ... do work ...
        
        $logger->success("Task completed");
        
        return [
            'processed' => 10,
            'succeeded' => 10,
            'failed' => 0
        ];
        
    } catch (Exception $e) {
        $logger->error("Task failed: " . $e->getMessage());
        throw $e;
    }
}
```

Then run it:
```bash
php cron/cron.php your_task
```

---

## Security Best Practices

1. ✅ **Never expose cron directory to web** - .htaccess blocks access
2. ✅ **Use CLI only** - cron.php checks for CLI execution
3. ✅ **Secure database credentials** - Store in config files outside web root
4. ✅ **Validate all inputs** - Even from cron jobs
5. ✅ **Use prepared statements** - Prevent SQL injection
6. ✅ **Log all actions** - For audit trail
7. ✅ **Implement locking** - Prevent concurrent execution
8. ✅ **Set proper file permissions** - 755 for directories, 644 for files

---

## Performance Tips

1. **Batch Processing**: Process emails in batches (default: 50)
2. **Rate Limiting**: Add delays between emails to avoid overwhelming mail server
3. **Cleanup Regularly**: Run cleanup_old_logs to prevent database bloat
4. **Monitor Memory**: Check execution history for memory usage
5. **Optimize Queries**: Use indexes on frequently queried columns
6. **Use Caching**: Cache frequently accessed data

---

## Support

For issues or questions:
1. Check logs: `cron/logs/cron.log`
2. Check database: `cron_logs` and `cron_execution_history` tables
3. Run with `--dry-run` to test
4. Contact system administrator

---

**Last Updated**: February 2026  
**Version**: 1.0.0
