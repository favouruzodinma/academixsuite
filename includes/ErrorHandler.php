<?php
/**
 * Custom Error Handler
 */

class ErrorHandler {
    public static function register() {
        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }
    
    public static function handleError($errno, $errstr, $errfile, $errline) {
        if (!(error_reporting() & $errno)) {
            return false;
        }
        
        $errorType = [
            E_ERROR => 'ERROR',
            E_WARNING => 'WARNING',
            E_PARSE => 'PARSE',
            E_NOTICE => 'NOTICE',
            E_CORE_ERROR => 'CORE_ERROR',
            E_CORE_WARNING => 'CORE_WARNING',
            E_COMPILE_ERROR => 'COMPILE_ERROR',
            E_COMPILE_WARNING => 'COMPILE_WARNING',
            E_USER_ERROR => 'USER_ERROR',
            E_USER_WARNING => 'USER_WARNING',
            E_USER_NOTICE => 'USER_NOTICE',
            E_STRICT => 'STRICT',
            E_RECOVERABLE_ERROR => 'RECOVERABLE_ERROR',
            E_DEPRECATED => 'DEPRECATED',
            E_USER_DEPRECATED => 'USER_DEPRECATED'
        ];
        
        $type = $errorType[$errno] ?? 'UNKNOWN';
        
        $message = sprintf(
            '[%s] %s in %s on line %d',
            $type,
            $errstr,
            $errfile,
            $errline
        );
        
        error_log($message);
        
        if (APP_DEBUG) {
            echo '<pre>' . $message . '</pre>';
        }
        
        return true;
    }
    
    public static function handleException($exception) {
        $message = sprintf(
            'Uncaught Exception: %s in %s on line %d',
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        );
        
        error_log($message);
        
        if (APP_DEBUG) {
            echo '<pre>' . $message . '</pre>';
            echo '<pre>' . $exception->getTraceAsString() . '</pre>';
        } else {
            header('HTTP/1.1 500 Internal Server Error');
            echo 'An unexpected error occurred. Please try again later.';
        }
        
        exit(1);
    }
    
    public static function handleShutdown() {
        $error = error_get_last();
        
        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
            self::handleError(
                $error['type'],
                $error['message'],
                $error['file'],
                $error['line']
            );
        }
    }
}
?>