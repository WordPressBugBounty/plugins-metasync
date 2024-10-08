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
        $logFileHandle = explode("\n", file_get_contents($this->logFilePath));

        foreach ($logFileHandle as $id => $currentLine) {
            // Normal error log line starts with the date & time in []
            if ('[' === @$currentLine[0]) {
                // if (10000 === \count($parsedLogs)) {
                //     return $parsedLogs;
                // }

                // Get the datetime when the error occurred and convert it to berlin timezone
                try {
                    $dateArr = [];
                    preg_match('~^\[(.*?)\]~', $currentLine, $dateArr);
                    $currentLine = str_replace($dateArr[0], '', $currentLine);
                    $currentLine = trim($currentLine);
                    $errorDateTime = new DateTime($dateArr[1]);
                    $errorDateTime->setTimezone(new DateTimeZone('Europe/Berlin'));
                    $errorDateTime = $errorDateTime->format('Y-m-d H:i:s');
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

                $errorFile = explode(' in /', $currentLine);
                $errorFile = '/' . trim($errorFile[1]);
                $currentLine = str_replace(' in ' . $errorFile, '', $currentLine);

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