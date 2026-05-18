<?php
/**
 * Cron Lock Manager
 * Implements file-based locking to prevent concurrent execution of cron tasks
 */

class CronLock {
    private $lockDir;
    private $lockFile;
    private $taskName;
    private $lockHandle;
    private $maxLockAge = 3600; // 1 hour in seconds
    
    public function __construct($taskName) {
        $this->taskName = $taskName;
        $this->lockDir = __DIR__ . '/../locks';
        $this->lockFile = $this->lockDir . '/' . $taskName . '.lock';
        
        // Create locks directory if it doesn't exist
        if (!is_dir($this->lockDir)) {
            mkdir($this->lockDir, 0755, true);
        }
    }
    
    /**
     * Acquire lock for the task
     * @return bool True if lock acquired, false if task already running
     */
    public function acquire() {
        // Check for stale locks and remove them
        $this->removeStalelock();
        
        // Try to acquire lock
        $this->lockHandle = fopen($this->lockFile, 'c');
        
        if (!$this->lockHandle) {
            return false;
        }
        
        // Try to get exclusive lock (non-blocking)
        if (!flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($this->lockHandle);
            $this->lockHandle = null;
            return false;
        }
        
        // Write process info to lock file
        $lockData = [
            'task' => $this->taskName,
            'pid' => getmypid(),
            'locked_at' => date('Y-m-d H:i:s'),
            'hostname' => gethostname()
        ];
        
        ftruncate($this->lockHandle, 0);
        fwrite($this->lockHandle, json_encode($lockData, JSON_PRETTY_PRINT));
        fflush($this->lockHandle);
        
        return true;
    }
    
    /**
     * Release the lock
     */
    public function release() {
        if ($this->lockHandle) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
            
            // Remove lock file
            if (file_exists($this->lockFile)) {
                unlink($this->lockFile);
            }
        }
    }
    
    /**
     * Remove stale locks (older than maxLockAge)
     */
    private function removeStaleLock() {
        if (!file_exists($this->lockFile)) {
            return;
        }
        
        $fileAge = time() - filemtime($this->lockFile);
        
        if ($fileAge > $this->maxLockAge) {
            // Lock is stale, remove it
            unlink($this->lockFile);
        }
    }
    
    /**
     * Check if task is currently locked
     * @return bool
     */
    public function isLocked() {
        if (!file_exists($this->lockFile)) {
            return false;
        }
        
        // Check if lock is stale
        $fileAge = time() - filemtime($this->lockFile);
        if ($fileAge > $this->maxLockAge) {
            return false;
        }
        
        // Try to open and lock the file
        $handle = fopen($this->lockFile, 'r');
        if (!$handle) {
            return false;
        }
        
        $locked = !flock($handle, LOCK_EX | LOCK_NB);
        
        if (!$locked) {
            flock($handle, LOCK_UN);
        }
        
        fclose($handle);
        
        return $locked;
    }
    
    /**
     * Get lock information
     * @return array|null
     */
    public function getLockInfo() {
        if (!file_exists($this->lockFile)) {
            return null;
        }
        
        $content = file_get_contents($this->lockFile);
        return json_decode($content, true);
    }
    
    /**
     * Destructor - ensure lock is released
     */
    public function __destruct() {
        $this->release();
    }
}
