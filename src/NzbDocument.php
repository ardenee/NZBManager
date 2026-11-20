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
        // Historical NZBs include an external PUBLIC/SYSTEM DTD declaration.
        // Strip only a simple declaration before parsing: never load its URL.
        $externalDtd = '~<!DOCTYPE[ \t\r\n]+nzb(?:[ \t\r\n]+(?:SYSTEM[ \t\r\n]+["\x27][^"\x27<>]*["\x27]|PUBLIC[ \t\r\n]+["\x27][^"\x27<>]*["\x27][ \t\r\n]+["\x27][^"\x27<>]*["\x27]))?[ \t\r\n]*>~i';
        $sanitized = preg_replace($externalDtd, '', $xml, 1, $removed);
        if ($sanitized === null || preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $sanitized))
        {
            throw new RuntimeException('Unsafe DTD or entity declaration in NZB.');
        }
        $xml = $sanitized;
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
            $integrity = self::segmentIntegrity($file);
            $files[] = [
                'index' => $index, 'name' => $name, 'subject' => $subject,
                'poster' => $file->getAttribute('poster'), 'groups' => implode(', ', $groups),
                'segments' => $segments, 'bytes' => $bytes, 'type' => $extension ?: 'other',
                'missingSegments' => $integrity['missing'], 'missingSample' => $integrity['sample'],
                'duplicateSegments' => $integrity['duplicates'], 'invalidSegments' => $integrity['invalid'],
                'suspect' => $integrity['missing'] > 0 || $integrity['duplicates'] > 0 || $integrity['invalid'] > 0 || $segments === 0
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

    /** @return list<DOMElement> */
    private static function segmentNodes(DOMElement $file): array
    {
        $rows = [];
        foreach ($file->childNodes as $child)
        {
            if ($child instanceof DOMElement && $child->localName === 'segments')
            {
                foreach ($child->childNodes as $segment)
                {
                    if ($segment instanceof DOMElement && $segment->localName === 'segment')
                    {
                        $rows[] = $segment;
                    }
                }
            }
        }
        return $rows;
    }

    /** @return array{missing:int,sample:list<int>,duplicates:int,invalid:int} */
    private static function segmentIntegrity(DOMElement $file): array
    {
        $numbers = [];
        $duplicates = 0;
        $invalid = 0;
        foreach (self::segmentNodes($file) as $segment)
        {
            $raw = $segment->getAttribute('number');
            if (!ctype_digit($raw) || strlen($raw) > 8 || (int) $raw < 1)
            {
                $invalid++;
                continue;
            }
            $number = (int) $raw;
            if (isset($numbers[$number]))
            {
                $duplicates++;
            }
            $numbers[$number] = true;
        }
        $sorted = array_keys($numbers);
        sort($sorted, SORT_NUMERIC);
        $previous = 0;
        $missing = 0;
        $sample = [];
        foreach ($sorted as $number)
        {
            if ($number > $previous + 1)
            {
                $gap = $number - $previous - 1;
                $missing += $gap;
                for ($i = $previous + 1; $i < $number && count($sample) < 16; $i++)
                {
                    $sample[] = $i;
                }
            }
            $previous = $number;
        }
        return ['missing' => $missing, 'sample' => $sample, 'duplicates' => $duplicates, 'invalid' => $invalid];
    }

    /** @return list<array{number:int,bytes:int,id:string,valid:bool}> */
    public function segmentsFor(int $fileIndex): array
    {
        $file = $this->files()[$fileIndex] ?? null;
        if ($file === null || $fileIndex < 0)
        {
            throw new RuntimeException('Invalid file index for segment viewer.');
        }
        $rows = [];
        foreach (self::segmentNodes($file) as $segment)
        {
            $rawNumber = $segment->getAttribute('number');
            $rawBytes = $segment->getAttribute('bytes');
            $valid = ctype_digit($rawNumber) && strlen($rawNumber) <= 8 && (int) $rawNumber >= 1;
            $rows[] = [
                'number' => $valid ? (int) $rawNumber : 0,
                'bytes' => ctype_digit($rawBytes) && strlen($rawBytes) <= 18 ? (int) $rawBytes : 0,
                'id' => trim($segment->textContent),
                'valid' => $valid,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $a['number'] <=> $b['number']);
        return $rows;
    }

    /**
     * Only existing internal gaps or the next sequential segment can be added.
     * @return list<int>
     */
    public function allowedAddNumbers(int $fileIndex): array
    {
        $rows = $this->segmentsFor($fileIndex);
        $seen = [];
        $max = 0;
        foreach ($rows as $row)
        {
            if ($row['valid'])
            {
                $seen[$row['number']] = true;
                $max = max($max, $row['number']);
            }
        }
        if ($max >= 50000)
        {
            throw new RuntimeException('This very large file is beyond the manual add-dropdown limit.');
        }
        $options = [];
        for ($n = 1; $n <= $max; $n++)
        {
            if (!isset($seen[$n]))
            {
                $options[] = $n;
            }
        }
        $options[] = $max + 1;
        return $options;
    }

    /**
     * Return a new NZB XML document. The original library record is retained.
     * @param 'add'|'replace'|'delete' $operation
     */
    public function withSegmentChange(int $fileIndex, string $operation, int $number, string $messageId = '', int $bytes = 0): string
    {
        if (!in_array($operation, ['add', 'replace', 'delete'], true) ||
            $number < 1 || $number > 99999999)
        {
            throw new RuntimeException('Invalid segment operation or number.');
        }
        if ($operation !== 'delete' && (
            $bytes < 1 || $bytes > PHP_INT_MAX ||
            !preg_match('/\A[\x21-\x3b\x3d\x3f-\x7e]{3,512}\z/D', $messageId)
        ))
        {
            throw new RuntimeException('Provide a valid Message-ID and positive segment byte count.');
        }
        $copy = self::fromXml($this->xml());
        $file = $copy->files()[$fileIndex] ?? null;
        if ($fileIndex < 0 || $file === null)
        {
            throw new RuntimeException('Selected NZB file was not found.');
        }
        $segmentsNode = null;
        foreach ($file->childNodes as $child)
        {
            if ($child instanceof DOMElement && $child->localName === 'segments')
            {
                $segmentsNode = $child;
                break;
            }
        }
        $matches = [];
        foreach (self::segmentNodes($file) as $segment)
        {
            if ($segment->getAttribute('number') === (string) $number)
            {
                $matches[] = $segment;
            }
        }
        if (count($matches) > 1)
        {
            throw new RuntimeException('Multiple existing segments have this number; edit is ambiguous.');
        }
        if ($operation === 'add')
        {
            if (!in_array($number, $copy->allowedAddNumbers($fileIndex), true))
            {
                throw new RuntimeException('Add only a missing segment number or the next sequential number.');
            }
            if ($matches !== [])
            {
                throw new RuntimeException('This segment number already exists.');
            }
            if ($segmentsNode === null)
            {
                $segmentsNode = $copy->doc->createElementNS($file->namespaceURI, 'segments');
                $file->appendChild($segmentsNode);
            }
            $new = $copy->doc->createElementNS($file->namespaceURI, 'segment');
            $new->setAttribute('number', (string) $number);
            $new->setAttribute('bytes', (string) $bytes);
            $new->textContent = $messageId;
            $segmentsNode->appendChild($new);
        }
        else
        {
            if ($matches === [])
            {
                throw new RuntimeException('Segment number does not exist.');
            }
            if ($operation === 'delete')
            {
                $matches[0]->parentNode->removeChild($matches[0]);
            }
            else
            {
                $matches[0]->setAttribute('bytes', (string) $bytes);
                $matches[0]->textContent = $messageId;
            }
        }
        $result = $copy->doc->saveXML();
        if (!is_string($result))
        {
            throw new RuntimeException('Unable to serialize edited NZB.');
        }
        self::fromXml($result);
        return $result;
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
