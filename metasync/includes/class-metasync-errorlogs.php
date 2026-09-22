<?php
class ErrorLog {
    private $logFilePath;

    /**
     * ErrorLog constructor.
     *
     * @param string $logFilePath
     */
    public function __construct() {
        $this->logFilePath = ini_get('error_log');
    }

    public function getParsedLogFile() {
        $parsedLogs = [];
        $logFilePath = $this->logFilePath;

        // error_log can be disabled (empty ini value), set to "syslog", or
        // point at a file that no longer exists — there is nothing to parse,
        // and file_get_contents would warn on those values.
        if (empty($logFilePath) || !is_string($logFilePath) || 'syslog' === $logFilePath || !is_file($logFilePath)) {
            return $parsedLogs;
        }

        // A long-lived error log can grow to gigabytes; parsing it whole
        // exhausts memory on the request that reads it. Read only the
        // trailing window — recent entries are what the admin UI shows.
        $tail_bytes = 1024 * 1024;
        $size = filesize($logFilePath);
        $content = false;
        if (false !== $size && $size > $tail_bytes) {
            $handle = @fopen($logFilePath, 'rb');
            if (false !== $handle) {
                fseek($handle, $size - $tail_bytes);
                $content = stream_get_contents($handle);
                fclose($handle);
                // Drop the leading partial line the window cut into.
                if (false !== $content && false !== strpos($content, "\n")) {
                    $content = substr($content, strpos($content, "\n") + 1);
                }
            }
        } else {
            $content = @file_get_contents($logFilePath);
        }
        if (false === $content || '' === $content) {
            return $parsedLogs;
        }

        $logFileHandle = explode("\n", $content);

        foreach ($logFileHandle as $id => $currentLine) {
            // Normal error log line starts with the date & time in []
            if ('[' === @$currentLine[0]) {
                // if (10000 === \count($parsedLogs)) {
                //     return $parsedLogs;
                // }

                // Get the datetime when the error occurred and convert it to
                // the site's timezone.
                try {
                    $dateArr = [];
                    preg_match('~^\[(.*?)\]~', $currentLine, $dateArr);
                    $errorDateTime = '';
                    if (!empty($dateArr[1])) {
                        $currentLine = str_replace($dateArr[0], '', $currentLine);
                        $currentLine = trim($currentLine);
                        $errorDateTime = new DateTime($dateArr[1]);
                        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
                        $errorDateTime->setTimezone($timezone);
                        $errorDateTime = $errorDateTime->format('Y-m-d H:i:s');
                    }
                } catch (\Exception $e) {
                    $errorDateTime = '';
                }

                // Get the type of the error
                if (false !== strpos($currentLine, 'PHP Warning')) {
                    $currentLine = str_replace('PHP Warning:', '', $currentLine);
                    $currentLine = trim($currentLine);
                    $errorType = 'WARNING';
                } else if (false !== strpos($currentLine, 'PHP Notice')) {
                    $currentLine = str_replace('PHP Notice:', '', $currentLine);
                    $currentLine = trim($currentLine);
                    $errorType = 'NOTICE';
                } else if (false !== strpos($currentLine, 'PHP Fatal error')) {
                    $currentLine = str_replace('PHP Fatal error:', '', $currentLine);
                    $currentLine = trim($currentLine);
                    $errorType = 'FATAL';
                } else if (false !== strpos($currentLine, 'PHP Parse error')) {
                    $currentLine = str_replace('PHP Parse error:', '', $currentLine);
                    $currentLine = trim($currentLine);
                    $errorType = 'SYNTAX';
                } else if (false !== strpos($currentLine, 'PHP Exception')) {
                    $currentLine = str_replace('PHP Exception:', '', $currentLine);
                    $currentLine = trim($currentLine);
                    $errorType = 'EXCEPTION';
                } else {
                    $errorType = 'UNKNOWN';
                }

                if (false !== strpos($currentLine, ' on line ')) {
                    $errorLine = explode(' on line ', $currentLine);
                    $errorLine = trim($errorLine[1]);
                    $currentLine = str_replace(' on line ' . $errorLine, '', $currentLine);
                } else {
                    $errorLine = substr($currentLine, strrpos($currentLine, ':') + 1);
                    $currentLine = str_replace(':' . $errorLine, '', $currentLine);
                }

                $errorFile = explode(' in /', $currentLine, 2);
                if (isset($errorFile[1])) {
                    $errorFile = '/' . trim($errorFile[1]);
                    $currentLine = str_replace(' in ' . $errorFile, '', $currentLine);
                } else {
                    $errorFile = '';
                }

                // The message of the error
                $errorMessage = trim($currentLine);

                $parsedLogs[] = [
                    'id'         => $id+1,
                    'dateTime'   => $errorDateTime,
                    'type'       => $errorType,
                    'file'       => $errorFile,
                    'line'       => (int)$errorLine,
                    'message'    => $errorMessage,
                    'stackTrace' => []
                ];
            } // Stack trace beginning line
            else if ('Stack trace:' === $currentLine) {
                $stackTraceLineNumber = 0;

                foreach ($logFileHandle as $line) {
                    $currentLine = str_replace(PHP_EOL, '', $line);

                    // If the current line is a stack trace line
                    if ('#' === $currentLine[0]) {
                        $parsedLogsLastKey = key($parsedLogs);
                        $currentLine = str_replace('#' . $stackTraceLineNumber, '', $currentLine);
                        $parsedLogs[$parsedLogsLastKey]['stackTrace'][] = trim($currentLine);

                        $stackTraceLineNumber++;
                    } // If the current line is the last stack trace ('thrown in...')
                    else {
                        break;
                    }
                }
            }
        }
        
        return $parsedLogs;
    }
}