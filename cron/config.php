<?php
/**
 * Cron Configuration
 * Configuration settings for cron tasks
 */

return [
    // Email processing settings
    'email' => [
        'batch_size' => 50,              // Number of emails to process per batch
        'max_attempts' => 3,             // Maximum send attempts before marking as failed
        'retry_delay' => 300,            // Delay between retries in seconds (5 minutes)
        'batch_delay' => 2,              // Delay between batches in seconds
        'cleanup_days' => 30,            // Days to keep sent emails before cleanup
    ],
    
    // Account suspension settings
    'suspension' => [
        'grace_period_days' => 7,        // Days after subscription ends before suspension
        'check_trial_expiry' => true,    // Check for expired trial accounts
        'check_subscription_expiry' => true, // Check for expired subscriptions
        'send_notification' => true,     // Send email notification on suspension
    ],
    
    // Announcement publishing settings
    'announcements' => [
        'batch_size' => 20,              // Number of announcements to process per run
        'advance_publish_minutes' => 5,  // Publish announcements up to X minutes early
    ],
    
    // Locking settings
    'lock' => [
        'max_age' => 3600,               // Maximum lock age in seconds (1 hour)
        'stale_check' => true,           // Check for and remove stale locks
    ],
    
    // Logging settings
    'logging' => [
        'max_file_size' => 10485760,     // Maximum log file size (10MB)
        'keep_rotated_files' => 5,       // Number of rotated log files to keep
        'log_to_database' => true,       // Also log to database
        'retention_days' => 30,          // Days to keep database logs
    ],
    
    // Performance settings
    'performance' => [
        'memory_limit' => '256M',        // Memory limit for cron tasks
        'max_execution_time' => 300,     // Maximum execution time (5 minutes)
    ],
];
