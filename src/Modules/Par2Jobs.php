<?php
declare(strict_types=1);

namespace NZB\Modules;

use RuntimeException;

/**
 * Lightweight PAR2 header inspector and compatibility facade for PHP jobs.
 * PAR2 verification and Reed–Solomon repair are implemented in PHP.
 * No external par2cmdline or other recovery executable is used anywhere.
 */
final class Par2Jobs
{
    /** @return array{set_id:string,type:string,bytes:int} */
    public static function inspect(WorkspaceFiles $work, string $relative): array
    {
        $path = $work->resolve($relative, 'file');
        if (!preg_match('/\.par2\z/i', $path) || !is_readable($path))
        {
            throw new RuntimeException('Select a readable PAR2 file.');
        }
        $size = (int) @filesize($path);
        if ($size < 64 || $size > 268435456)
        {
            throw new RuntimeException('PAR2 file is too small or exceeds the 256 MiB limit.');
        }
        $fp = @fopen($path, 'rb');
        if (!is_resource($fp))
        {
            throw new RuntimeException('Cannot read the PAR2 header.');
        }
        try
        {
            $header = fread($fp, 64);
        }
        finally
        {
            fclose($fp);
        }
        if (!is_string($header) || strlen($header) !== 64 ||
            substr($header, 0, 8) !== "PAR2\0PKT")
        {
            throw new RuntimeException('File does not have a PAR2 packet header.');
        }
        return ['set_id' => bin2hex(substr($header, 32, 16)),
            'type' => trim(str_replace("\0", '', substr($header, 48, 16))),
            'bytes' => $size];
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        return BackgroundJobs::all('par2');
    }

    public static function submit(WorkspaceFiles $work, string $relative, string $mode): string
    {
        if (!in_array($mode, ['verify', 'repair'], true))
        {
            throw new RuntimeException('Unsupported PAR2 operation.');
        }
        return BackgroundJobs::queue('par2_' . $mode, $work, $relative);
    }

    /** CLI-only compatibility helper; no external executable. */
    public static function runOne(): bool
    {
        if (PHP_SAPI !== 'cli')
        {
            throw new RuntimeException('PAR2 jobs can run only in PHP CLI.');
        }
        foreach (array_reverse(self::all()) as $job)
        {
            if (($job['status'] ?? null) === 'queued')
            {
                BackgroundJobs::run($job['id']);
                return true;
            }
        }
        return false;
    }
}
