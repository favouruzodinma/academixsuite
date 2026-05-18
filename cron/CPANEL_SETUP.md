# Quick Setup Guide for cPanel

## Step 1: Run Database Migration

### Option A: Using phpMyAdmin (Recommended)
1. Login to cPanel
2. Click **phpMyAdmin**
3. Select your database (e.g., `academixsuite`)
4. Click **Import** tab
5. Click **Choose File** and select:
   ```
   database/migrations/002_create_cron_tables.sql
   ```
6. Click **Go**
7. Wait for success message

### Option B: Using Terminal/SSH
```bash
mysql -u your_username -p your_database < database/migrations/002_create_cron_tables.sql
```

---

## Step 2: Set Up Cron Jobs in cPanel

1. Login to cPanel
2. Navigate to **Advanced** → **Cron Jobs**
3. Under **Add New Cron Job**, add each of the following:

### Cron Job 1: Process Email Queue
- **Common Settings**: Custom
- **Minute**: `*/5`
- **Hour**: `*`
- **Day**: `*`
- **Month**: `*`
- **Weekday**: `*`
- **Command**:
  ```
  /usr/bin/php /home/YOUR_USERNAME/public_html/cron/cron.php process_email_queue
  ```
  ⚠️ Replace `YOUR_USERNAME` with your actual cPanel username

### Cron Job 2: Process School Trials
- **Common Settings**: Custom
- **Minute**: `0`
- **Hour**: `*`
- **Day**: `*`
- **Month**: `*`
- **Weekday**: `*`
- **Command**:
  ```
  /usr/bin/php /home/YOUR_USERNAME/public_html/cron/cron.php process_school_trials
  ```

### Cron Job 3: Process Student Suspensions
- **Common Settings**: Custom
- **Minute**: `0`
- **Hour**: `*`
- **Day**: `*`
- **Month**: `*`
- **Weekday**: `*`
- **Command**:
  ```
  /usr/bin/php /home/YOUR_USERNAME/public_html/cron/cron.php process_student_suspensions
  ```

### Cron Job 4: Publish Scheduled Announcements
- **Common Settings**: Custom
- **Minute**: `*/15`
- **Hour**: `*`
- **Day**: `*`
- **Month**: `*`
- **Weekday**: `*`
- **Command**:
  ```
  /usr/bin/php /home/YOUR_USERNAME/public_html/cron/cron.php publish_scheduled_announcements
  ```

### Cron Job 5: Retry Failed Emails
- **Common Settings**: Custom
- **Minute**: `0`
- **Hour**: `*/6`
- **Day**: `*`
- **Month**: `*`
- **Weekday**: `*`
- **Command**:
  ```
  /usr/bin/php /home/YOUR_USERNAME/public_html/cron/cron.php retry_failed_emails
  ```

### Cron Job 6: Cleanup Old Logs
- **Common Settings**: Custom
- **Minute**: `0`
- **Hour**: `2`
- **Day**: `*`
- **Month**: `*`
- **Weekday**: `*`
- **Command**:
  ```
  /usr/bin/php /home/YOUR_USERNAME/public_html/cron/cron.php cleanup_old_logs
  ```

---

## Step 3: Find Your PHP Path (If needed)

If `/usr/bin/php` doesn't work, you need to find the correct PHP path:

### Method 1: Using Terminal/SSH
```bash
which php
```

### Method 2: Using cPanel PHP Selector
1. In cPanel, go to **Software** → **Select PHP Version**
2. Note the PHP version (e.g., `7.4`, `8.0`, `8.1`)
3. Common paths:
   - `/opt/cpanel/ea-php74/root/usr/bin/php`
   - `/opt/cpanel/ea-php80/root/usr/bin/php`
   - `/opt/cpanel/ea-php81/root/usr/bin/php`
   - `/usr/local/bin/php`

### Method 3: Create a Test File
1. Create file: `public_html/phppath.php`
2. Add content:
   ```php
   <?php
   echo PHP_BINARY;
   ```
3. Visit: `https://yourdomain.com/phppath.php`
4. Copy the path shown
5. Delete the test file

---

## Step 4: Test the Setup

### Test via SSH (Recommended)
```bash
# Navigate to your project
cd /home/YOUR_USERNAME/public_html

# Test the dispatcher
php cron/cron.php

# Test email queue processing (dry run)
php cron/cron.php process_email_queue --dry-run

# Test school trial expiry processing (dry run)
php cron/cron.php process_school_trials --dry-run

# Test student suspensions (dry run)
php cron/cron.php process_student_suspensions --dry-run
```

### Test via cPanel Terminal
1. In cPanel, click **Terminal**
2. Run the same commands as above

---

## Step 5: Verify Cron Jobs Are Running

### Check Cron Job Execution
1. Wait 5-15 minutes after setup
2. In cPanel, go to **Cron Jobs**
3. Scroll down to **Current Cron Jobs**
4. Check the **Last Run** column

### Check Log Files
1. In cPanel, go to **File Manager**
2. Navigate to: `public_html/cron/logs/`
3. Open `cron.log`
4. Look for recent entries

### Check Database
1. In cPanel, go to **phpMyAdmin**
2. Select your database
3. Run this query:
   ```sql
   SELECT * FROM cron_execution_history 
   ORDER BY started_at DESC 
   LIMIT 10;
   ```
4. You should see recent executions

---

## Step 6: Monitor Email Queue

### Add a Test Email
1. In phpMyAdmin, run:
   ```sql
   INSERT INTO email_queue 
   (to_email, subject, html_content, text_content, priority, type, status, created_at, updated_at)
   VALUES 
   ('your-email@example.com', 'Test Email', '<h1>Test</h1>', 'Test', 5, 'test', 'pending', NOW(), NOW());
   ```

2. Wait 5 minutes (next cron run)

3. Check if email was sent:
   ```sql
   SELECT * FROM email_queue ORDER BY created_at DESC LIMIT 10;
   ```

4. Status should change from `pending` to `sent`

---

## Troubleshooting

### Issue: Cron jobs not running

**Check 1: PHP Path**
- Verify PHP path is correct
- Try different paths from Step 3

**Check 2: File Permissions**
```bash
chmod +x /home/YOUR_USERNAME/public_html/cron/cron.php
chmod 755 /home/YOUR_USERNAME/public_html/cron/locks
chmod 755 /home/YOUR_USERNAME/public_html/cron/logs
```

**Check 3: Email Notifications**
- In cPanel Cron Jobs, enter your email in "Cron Email" field
- You'll receive emails when cron jobs run (or fail)

### Issue: "Permission denied" error

**Solution**:
```bash
chmod +x /home/YOUR_USERNAME/public_html/cron/cron.php
```

### Issue: "Database connection failed"

**Solution**:
- Check `config/database.php` settings
- Verify database credentials
- Ensure database exists

### Issue: Emails not sending

**Check 1: Email Queue**
```sql
SELECT * FROM email_queue WHERE status = 'failed';
```

**Check 2: Email Service**
- Verify email configuration in `config/mail.php`
- Check SMTP settings
- Test email sending manually

**Check 3: Suppression List**
```sql
SELECT * FROM email_suppression_list;
```

---

## Email Notifications for Cron Jobs

To receive email notifications when cron jobs run:

1. In cPanel → Cron Jobs
2. Find **Cron Email** field at the top
3. Enter your email address
4. Click **Update Email**

You'll now receive emails for:
- ✅ Successful cron executions
- ❌ Failed cron executions
- ⚠️ Any errors or warnings

---

## Next Steps

1. ✅ Monitor logs for the first 24 hours
2. ✅ Test each cron job with `--dry-run` flag
3. ✅ Set up email notifications
4. ✅ Review execution history in database
5. ✅ Adjust schedules if needed

---

## Quick Reference: Cron Schedules

| Task | Schedule | Frequency |
|------|----------|-----------|
| process_email_queue | `*/5 * * * *` | Every 5 minutes |
| process_school_trials | `0 * * * *` | Every hour |
| process_student_suspensions | `0 * * * *` | Every hour |
| publish_scheduled_announcements | `*/15 * * * *` | Every 15 minutes |
| retry_failed_emails | `0 */6 * * *` | Every 6 hours |
| cleanup_old_logs | `0 2 * * *` | Daily at 2 AM |

---

## Support

If you encounter issues:
1. Check `cron/logs/cron.log`
2. Check `cron_execution_history` table
3. Run tasks manually with `--dry-run`
4. Contact your system administrator

---

**Setup Complete!** 🎉

Your cron job system is now ready to handle:
- ✅ Automated email sending
- ✅ Student account suspensions
- ✅ Scheduled announcements
- ✅ Email retries
- ✅ Automatic cleanup
