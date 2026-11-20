<?php
declare(strict_types=1);

namespace NZB\Modules;

use RuntimeException;

/**
 * Disk-backed fixed-width GF16 matrix rows for large PAR2 recovery systems.
 * Scratch files are private, local to the recovery set, and removed on close.
 * A single row at a time is held in PHP memory.
 */
final class Par2DiskMatrix
{
    private $handle;
    private string $path;
    private int $rowBytes;

    public function __construct(string $directory, int $rowBytes)
    {
        if ($rowBytes < 2 || $rowBytes % 2 !== 0 || !is_dir($directory) || !is_writable($directory))
        {
            throw new RuntimeException('Invalid PAR2 disk-backed matrix workspace.');
        }
        $this->rowBytes = $rowBytes;
        $this->path = $directory . DIRECTORY_SEPARATOR . '.nzbmanager-par2-matrix-' . bin2hex(random_bytes(12));
        $handle = @fopen($this->path, 'x+b');
        if (!is_resource($handle))
        {
            throw new RuntimeException('Could not allocate private PAR2 matrix scratch file.');
        }
        @chmod($this->path, 0600);
        $this->handle = $handle;
    }

    public function write(int $index, string $row): void
    {
        if (!is_resource($this->handle) || $index < 0 || strlen($row) !== $this->rowBytes)
        {
            throw new RuntimeException('Invalid PAR2 matrix row write.');
        }
        $offset = $index * $this->rowBytes;
        if ($offset < 0 || fseek($this->handle, $offset) !== 0)
        {
            throw new RuntimeException('Cannot seek PAR2 matrix scratch file for writing.');
        }
        for ($written = 0; $written < $this->rowBytes;)
        {
            $n = @fwrite($this->handle, substr($row, $written));
            if (!is_int($n) || $n < 1)
            {
                throw new RuntimeException('Unable to write PAR2 matrix scratch data: check free disk space.');
            }
            $written += $n;
        }
    }

    public function read(int $index): string
    {
        if (!is_resource($this->handle) || $index < 0)
        {
            throw new RuntimeException('Invalid PAR2 matrix row read.');
        }
        $offset = $index * $this->rowBytes;
        if ($offset < 0 || fseek($this->handle, $offset) !== 0)
        {
            throw new RuntimeException('Cannot seek PAR2 matrix scratch file for reading.');
        }
        $row = '';
        while (strlen($row) < $this->rowBytes)
        {
            $chunk = @fread($this->handle, $this->rowBytes - strlen($row));
            if (!is_string($chunk) || $chunk === '')
            {
                throw new RuntimeException('PAR2 matrix scratch row is truncated.');
            }
            $row .= $chunk;
        }
        return $row;
    }

    public function close(): void
    {
        if (is_resource($this->handle))
        {
            fclose($this->handle);
        }
        $this->handle = null;
        if (is_file($this->path) && !is_link($this->path))
        {
            @unlink($this->path);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
