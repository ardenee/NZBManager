<?php
declare(strict_types=1);
namespace NZB;
use RuntimeException;

final class NzbStorage
{
    public function __construct(private string $dir)
    {
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir))
        {
            throw new RuntimeException('Cannot create NZB storage folder.');
        }
        if (!is_writable($dir))
        {
            throw new RuntimeException('NZB storage folder is not writable.');
        }
    }

    public function save(string $name, string $xml): array
    {
        $summary = NzbDocument::fromXml($xml)->summary();
        $id = bin2hex(random_bytes(16));
        $name = trim(str_replace(['/', '\\', "\0"], '', $name));
        $name = substr(preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '', 0, 180);
        $name = trim($name, " .");
        $name = $name ?: 'untitled.nzb';
        if (!str_ends_with(strtolower($name), '.nzb'))
        {
            $name .= '.nzb';
        }
        $record = [
            'id' => $id, 'name' => $name, 'created' => gmdate('c'),
            'files' => count($summary['files']), 'bytes' => $summary['totalBytes'],
            'segments' => $summary['segmentCount']
        ];
        $path = $this->dir . DIRECTORY_SEPARATOR . $id;
        $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($path . '.nzb', $xml, LOCK_EX) !== strlen($xml))
        {
            @unlink($path . '.nzb');
            throw new RuntimeException('Could not save NZB.');
        }
        if (file_put_contents($path . '.json', $json, LOCK_EX) !== strlen($json))
        {
            @unlink($path . '.nzb');
            @unlink($path . '.json');
            throw new RuntimeException('Could not save NZB metadata.');
        }
        return $record;
    }

    private function path(string $id, string $suffix): string
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $id))
        {
            throw new RuntimeException('Invalid NZB identifier.');
        }
        return $this->dir . DIRECTORY_SEPARATOR . $id . $suffix;
    }

    public function info(string $id): array
    {
        $path = $this->path($id, '.json');
        if (!is_file($path) || is_link($path))
        {
            throw new RuntimeException('NZB not found.');
        }
        $info = json_decode(file_get_contents($path) ?: '', true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($info) || ($info['id'] ?? null) !== $id)
        {
            throw new RuntimeException('Invalid NZB metadata.');
        }
        return $info;
    }

    public function load(string $id): NzbDocument
    {
        $this->info($id);
        $path = $this->path($id, '.nzb');
        if (!is_file($path) || is_link($path))
        {
            throw new RuntimeException('NZB not found.');
        }
        $xml = file_get_contents($path, false, null, 0, NzbDocument::MAX_BYTES + 1);
        if ($xml === false)
        {
            throw new RuntimeException('Could not load NZB.');
        }
        return NzbDocument::fromXml($xml);
    }

    public function all(): array
    {
        $entries = [];
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path)
        {
            try
            {
                $entries[] = $this->info(basename($path, '.json'));
            }
            catch (\Throwable)
            {
                // One invalid metadata file should not prevent other files loading.
            }
        }
        usort($entries, static fn (array $a, array $b): int => strcmp($b['created'], $a['created']));
        return $entries;
    }

    public function delete(string $id): void
    {
        $this->info($id);
        foreach (['.json', '.nzb'] as $suffix)
        {
            $path = $this->path($id, $suffix);
            if (is_file($path) && !is_link($path) && !unlink($path))
            {
                throw new RuntimeException('Cannot delete NZB.');
            }
        }
    }
}
