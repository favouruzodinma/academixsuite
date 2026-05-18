#!/bin/bash
################################################################################
# EMAIL QUEUE WORKER - CRON JOB SETUP SCRIPT
# Automated setup for email queue background worker
# 
# Usage: bash setup_cron.sh
# 
# This script will:
# 1. Detect your server environment
# 2. Find PHP CLI path
# 3. Create log directories
# 4. Test worker script
# 5. Generate cron job command
# 6. Optionally add to crontab
################################################################################

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Script configuration
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
WORKER_SCRIPT="$PROJECT_DIR/workers/email_queue_worker.php"
LOG_DIR="$PROJECT_DIR/logs"

echo -e "${BLUE}╔════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║   EMAIL QUEUE WORKER - CRON JOB SETUP                     ║${NC}"
echo -e "${BLUE}║   AcademixSuite Background Email Processing                ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════════╝${NC}"
echo ""

################################################################################
# Step 1: Detect Environment
################################################################################
echo -e "${YELLOW}[1/7]${NC} Detecting server environment..."

if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS_NAME=$NAME
    OS_VERSION=$VERSION_ID
    echo -e "${GREEN}✓${NC} Detected: $OS_NAME $OS_VERSION"
else
    OS_NAME="Unknown"
    echo -e "${YELLOW}⚠${NC} Could not detect OS version"
fi

################################################################################
# Step 2: Find PHP CLI
################################################################################
echo -e "${YELLOW}[2/7]${NC} Finding PHP CLI..."

PHP_PATH=$(which php 2>/dev/null)

if [ -z "$PHP_PATH" ]; then
    # Try common paths
    for path in /usr/bin/php /usr/local/bin/php /opt/cpanel/ea-php81/root/usr/bin/php /opt/cpanel/ea-php80/root/usr/bin/php; do
        if [ -f "$path" ]; then
            PHP_PATH=$path
            break
        fi
    done
fi

if [ -z "$PHP_PATH" ]; then
    echo -e "${RED}✗${NC} PHP CLI not found!"
    echo -e "  Please install PHP CLI or specify the path manually."
    exit 1
fi

echo -e "${GREEN}✓${NC} Found PHP: $PHP_PATH"

# Check PHP version
PHP_VERSION=$($PHP_PATH -v | head -n 1 | cut -d " " -f 2)
echo -e "  Version: $PHP_VERSION"

################################################################################
# Step 3: Verify Project Structure
################################################################################
echo -e "${YELLOW}[3/7]${NC} Verifying project structure..."

if [ ! -f "$WORKER_SCRIPT" ]; then
    echo -e "${RED}✗${NC} Worker script not found: $WORKER_SCRIPT"
    exit 1
fi

echo -e "${GREEN}✓${NC} Worker script found"
echo -e "  Path: $WORKER_SCRIPT"

################################################################################
# Step 4: Create Log Directory
################################################################################
echo -e "${YELLOW}[4/7]${NC} Setting up log directory..."

if [ ! -d "$LOG_DIR" ]; then
    mkdir -p "$LOG_DIR"
    echo -e "${GREEN}✓${NC} Created log directory: $LOG_DIR"
else
    echo -e "${GREEN}✓${NC} Log directory exists: $LOG_DIR"
fi

# Create log files
touch "$LOG_DIR/email_worker.log"
touch "$LOG_DIR/email_worker_error.log"
chmod 644 "$LOG_DIR/email_worker.log"
chmod 644 "$LOG_DIR/email_worker_error.log"

echo -e "${GREEN}✓${NC} Log files created"

################################################################################
# Step 5: Test Worker Script
################################################################################
echo -e "${YELLOW}[5/7]${NC} Testing worker script..."

# Make worker executable
chmod +x "$WORKER_SCRIPT"

# Test PHP syntax
if $PHP_PATH -l "$WORKER_SCRIPT" > /dev/null 2>&1; then
    echo -e "${GREEN}✓${NC} PHP syntax check passed"
else
    echo -e "${RED}✗${NC} PHP syntax error in worker script"
    $PHP_PATH -l "$WORKER_SCRIPT"
    exit 1
fi

# Test execution
echo -e "  Running test execution..."
TEST_OUTPUT=$($PHP_PATH "$WORKER_SCRIPT" 2>&1)
TEST_EXIT_CODE=$?

if [ $TEST_EXIT_CODE -eq 0 ]; then
    echo -e "${GREEN}✓${NC} Worker script executed successfully"
else
    echo -e "${RED}✗${NC} Worker script failed with exit code: $TEST_EXIT_CODE"
    echo -e "  Output:"
    echo "$TEST_OUTPUT" | sed 's/^/    /'
    exit 1
fi

################################################################################
# Step 6: Generate Cron Command
################################################################################
echo -e "${YELLOW}[6/7]${NC} Generating cron job command..."

CRON_COMMAND="* * * * * cd $PROJECT_DIR && $PHP_PATH workers/email_queue_worker.php >> logs/email_worker.log 2>&1"

echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}Cron Job Command:${NC}"
echo ""
echo -e "${YELLOW}$CRON_COMMAND${NC}"
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════════${NC}"
echo ""

################################################################################
# Step 7: Add to Crontab (Optional)
################################################################################
echo -e "${YELLOW}[7/7]${NC} Crontab configuration..."
echo ""

read -p "Do you want to add this cron job to your crontab now? (y/n): " -n 1 -r
echo ""

if [[ $REPLY =~ ^[Yy]$ ]]; then
    # Check if cron job already exists
    if crontab -l 2>/dev/null | grep -q "email_queue_worker.php"; then
        echo -e "${YELLOW}⚠${NC} Cron job already exists in crontab"
        echo ""
        read -p "Do you want to replace it? (y/n): " -n 1 -r
        echo ""
        
        if [[ $REPLY =~ ^[Yy]$ ]]; then
            # Remove old entry and add new one
            (crontab -l 2>/dev/null | grep -v "email_queue_worker.php"; echo "$CRON_COMMAND") | crontab -
            echo -e "${GREEN}✓${NC} Cron job updated successfully"
        else
            echo -e "${YELLOW}⚠${NC} Keeping existing cron job"
        fi
    else
        # Add new cron job
        (crontab -l 2>/dev/null; echo "$CRON_COMMAND") | crontab -
        echo -e "${GREEN}✓${NC} Cron job added successfully"
    fi
    
    echo ""
    echo -e "${GREEN}Current crontab:${NC}"
    crontab -l | grep -v "^#" | grep -v "^$"
else
    echo -e "${YELLOW}⚠${NC} Cron job not added to crontab"
    echo -e "  You can add it manually later using: ${YELLOW}crontab -e${NC}"
fi

################################################################################
# Summary
################################################################################
echo ""
echo -e "${BLUE}╔════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║   SETUP COMPLETE                                           ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "${GREEN}✓${NC} PHP CLI: $PHP_PATH"
echo -e "${GREEN}✓${NC} Worker Script: $WORKER_SCRIPT"
echo -e "${GREEN}✓${NC} Log Directory: $LOG_DIR"
echo -e "${GREEN}✓${NC} Worker Test: Passed"
echo ""

if [[ $REPLY =~ ^[Yy]$ ]]; then
    echo -e "${GREEN}✓${NC} Cron job is now active and will run every minute"
    echo ""
    echo -e "${YELLOW}Next Steps:${NC}"
    echo -e "  1. Wait 1-2 minutes for the first execution"
    echo -e "  2. Check logs: ${YELLOW}tail -f $LOG_DIR/email_worker.log${NC}"
    echo -e "  3. Monitor queue: ${YELLOW}mysql -u root -p -e \"SELECT status, COUNT(*) FROM email_queue GROUP BY status\" academixsuite${NC}"
else
    echo -e "${YELLOW}Manual Setup Required:${NC}"
    echo -e "  1. Run: ${YELLOW}crontab -e${NC}"
    echo -e "  2. Add this line:"
    echo -e "     ${YELLOW}$CRON_COMMAND${NC}"
    echo -e "  3. Save and exit"
fi

echo ""
echo -e "${BLUE}Monitoring Commands:${NC}"
echo -e "  • View logs:        ${YELLOW}tail -f $LOG_DIR/email_worker.log${NC}"
echo -e "  • Check cron jobs:  ${YELLOW}crontab -l${NC}"
echo -e "  • Queue status:     ${YELLOW}mysql -u root -p -e \"SELECT status, COUNT(*) FROM email_queue GROUP BY status\" academixsuite${NC}"
echo ""
echo -e "${GREEN}Setup completed successfully!${NC}"
echo ""
