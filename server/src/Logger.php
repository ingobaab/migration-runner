<?php

class Logger
{
    private string $file;

    public function __construct()
    {
        if (!is_dir(__DIR__ . '/../logs')) {
            mkdir(__DIR__ . '/../logs', 0777, true);
        }

        $this->file = __DIR__ . '/../logs/' . date('Ymd_His') . '.log';
    }

    public function info(string $msg)
    {
        $this->write("INFO", $msg);
    }

    public function error(string $msg)
    {
        $this->write("ERROR", $msg);
    }

    private function write(string $level, string $msg)
    {
        $line = "[" . date('c') . "] [$level] $msg\n";
        echo $line;
        file_put_contents($this->file, $line, FILE_APPEND);
    }
}
