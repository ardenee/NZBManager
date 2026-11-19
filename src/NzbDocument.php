<?php
declare(strict_types=1);
namespace NZB;
use DOMDocument;
use DOMElement;
use RuntimeException;

final class NzbDocument
{
    public const MAX_BYTES = 33554432;
    private function __construct(private DOMDocument $doc) {}

    public static function fromXml(string $xml): self
    {
        if ($xml === '' || strlen($xml) > self::MAX_BYTES)
        {
            throw new RuntimeException('NZB data is empty or exceeds the 32 MiB limit.');
        }
        if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml))
        {
            throw new RuntimeException('DTD and entity declarations are not accepted.');
        }
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;
        $old = libxml_use_internal_errors(true);
        try
        {
            $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        }
        finally
        {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
        if (!$ok || $dom->doctype !== null || $dom->documentElement?->localName !== 'nzb')
        {
            throw new RuntimeException('The file is not valid NZB XML.');
        }
        $result = new self($dom);
        if (count($result->files()) > 10000)
        {
            throw new RuntimeException('The NZB has too many entries.');
        }
        return $result;
    }

    /** @return list<DOMElement> */
    private function files(): array
    {
        $files = [];
        foreach ($this->doc->documentElement->childNodes as $child)
        {
            if ($child instanceof DOMElement && $child->localName === 'file')
            {
                $files[] = $child;
            }
        }
        return $files;
    }

    public function summary(): array
    {
        $files = [];
        $totalBytes = 0;
        $totalSegments = 0;
        foreach ($this->files() as $index => $file)
        {
            $subject = $file->getAttribute('subject');
            $name = preg_match('/"([^"]+)"/u', $subject, $matches) ? $matches[1] : $subject;
            $bytes = 0;
            $segments = 0;
            $groups = [];
            foreach ($file->childNodes as $child)
            {
                if (!($child instanceof DOMElement))
                {
                    continue;
                }
                if ($child->localName === 'groups')
                {
                    foreach ($child->childNodes as $group)
                    {
                        if ($group instanceof DOMElement && $group->localName === 'group')
                        {
                            $groups[] = trim($group->textContent);
                        }
                    }
                }
                if ($child->localName === 'segments')
                {
                    foreach ($child->childNodes as $segment)
                    {
                        if ($segment instanceof DOMElement && $segment->localName === 'segment')
                        {
                            $segments++;
                            $size = $segment->getAttribute('bytes');
                            if (ctype_digit($size) && strlen($size) < 19)
                            {
                                $segmentBytes = (int) $size;
                                $bytes = $segmentBytes > PHP_INT_MAX - $bytes ? PHP_INT_MAX : $bytes + $segmentBytes;
                            }
                        }
                    }
                }
            }
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $files[] = [
                'index' => $index, 'name' => $name, 'subject' => $subject,
                'poster' => $file->getAttribute('poster'), 'groups' => implode(', ', $groups),
                'segments' => $segments, 'bytes' => $bytes, 'type' => $extension ?: 'other'
            ];
            $totalBytes = $bytes > PHP_INT_MAX - $totalBytes ? PHP_INT_MAX : $totalBytes + $bytes;
            $totalSegments += $segments;
        }
        $meta = [];
        foreach ($this->doc->documentElement->childNodes as $child)
        {
            if (!($child instanceof DOMElement) || $child->localName !== 'head')
            {
                continue;
            }
            foreach ($child->childNodes as $item)
            {
                if ($item instanceof DOMElement && $item->localName === 'meta')
                {
                    $meta[] = ['type' => $item->getAttribute('type'), 'value' => trim($item->textContent)];
                }
            }
        }
        return ['files' => $files, 'totalBytes' => $totalBytes, 'segmentCount' => $totalSegments, 'metadata' => $meta];
    }

    public function xml(): string
    {
        return $this->doc->saveXML() ?: throw new RuntimeException('Could not serialize NZB.');
    }

    /** @param list<int> $indices */
    public function select(array $indices): string
    {
        $files = $this->files();
        if (!$indices || count($indices) > count($files))
        {
            throw new RuntimeException('Select at least one valid entry.');
        }
        $keep = [];
        foreach ($indices as $i)
        {
            if ($i < 0 || $i >= count($files))
            {
                throw new RuntimeException('Invalid selected file index.');
            }
            $keep[$i] = true;
        }
        $copy = $this->doc->cloneNode(true);
        if (!($copy instanceof DOMDocument))
        {
            throw new RuntimeException('Could not copy NZB document.');
        }
        $position = 0;
        foreach (iterator_to_array($copy->documentElement->childNodes) as $child)
        {
            if ($child instanceof DOMElement && $child->localName === 'file')
            {
                if (!isset($keep[$position]))
                {
                    $child->parentNode->removeChild($child);
                }
                $position++;
            }
        }
        return $copy->saveXML() ?: throw new RuntimeException('Could not export NZB.');
    }

    /** @param list<self> $documents */
    public static function merge(array $documents): string
    {
        if (count($documents) < 2)
        {
            throw new RuntimeException('Choose two or more NZB documents.');
        }
        $first = array_shift($documents);
        $merged = $first->doc->cloneNode(true);
        if (!($merged instanceof DOMDocument))
        {
            throw new RuntimeException('Could not merge NZB documents.');
        }
        foreach ($documents as $other)
        {
            foreach ($other->files() as $file)
            {
                $merged->documentElement->appendChild($merged->importNode($file, true));
            }
        }
        $xml = $merged->saveXML();
        if (!$xml || strlen($xml) > self::MAX_BYTES)
        {
            throw new RuntimeException('Merged NZB exceeds the 32 MiB limit.');
        }
        return $xml;
    }
}
