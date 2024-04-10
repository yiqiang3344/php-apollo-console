<?php

class Log
{
    public $logPath;

    private function __construct($logPath)
    {
        $this->logPath = $logPath;
    }

    public static function instance($logPath)
    {
        return new self($logPath);
    }

    public static function createDirectory($path, $mode = 0775, $recursive = true)
    {
        if (is_dir($path)) {
            return true;
        }
        $parentDir = dirname($path);
        // recurse if parent dir does not exist and we are not at the root of the file system.
        if ($recursive && !is_dir($parentDir) && $parentDir !== $path) {
            static::createDirectory($parentDir, $mode, true);
        }
        try {
            if (!mkdir($path, $mode)) {
                return false;
            }
        } catch (\Exception $e) {
            if (!is_dir($path)) {
                throw new Exception("Failed to create directory \"$path\": " . $e->getMessage(), $e->getCode(), $e);
            }
        }
        try {
            return chmod($path, $mode);
        } catch (\Exception $e) {
            throw new Exception("Failed to change permissions for directory \"$path\": " . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function format($message)
    {
        $_message = [
            'time' => date('Y-m-d H:i:s'),
        ];
        $_message = array_merge($_message, $message);
        return json_encode($_message, JSON_UNESCAPED_UNICODE);
    }

    public function log(array $message)
    {
        $text = $this->format($message) . PHP_EOL;
        self::createDirectory($this->logPath);
        $logFile = $this->logPath . '/' . date('Ymd') . '.log';
        if (($fp = @fopen($logFile, 'a')) === false) {
            throw new Exception("Unable to append to log file: {$logFile}");
        }
        @flock($fp, LOCK_EX);
        $writeResult = @fwrite($fp, $text);
        if ($writeResult === false) {
            $error = error_get_last();
            throw new Exception("Unable to export log through file ({$logFile})!: {$error['message']}");
        }
        $textSize = strlen($text);
        if ($writeResult < $textSize) {
            throw new Exception("Unable to export whole log through file ({$logFile})! Wrote $writeResult out of $textSize bytes.");
        }
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }
}
