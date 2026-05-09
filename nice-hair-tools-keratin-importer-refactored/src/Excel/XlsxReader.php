<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

final class NH_TKI_XLSX_Reader
{
    public static function read(string $path): array
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Не удалось открыть XLSX-файл.');
        }

        $sharedStrings = self::read_shared_strings($zip);
        $sheetTargets = self::read_sheet_targets($zip);
        $sheets = [];

        foreach ($sheetTargets as $sheet) {
            $sheetPath = $sheet['path'];
            $sheetXml = $zip->getFromName($sheetPath);

            if (! is_string($sheetXml) || $sheetXml === '') {
                continue;
            }

            $rows = self::read_sheet_rows($sheetXml, $sharedStrings);
            $sheets[] = [
                'name' => $sheet['name'],
                'rows' => $rows,
            ];
        }

        $zip->close();

        return ['sheets' => $sheets];
    }

    private static function read_shared_strings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        $mainNs = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

        if (! is_string($xml) || $xml === '') {
            return [];
        }

        $document = simplexml_load_string($xml);

        if (! $document instanceof SimpleXMLElement) {
            return [];
        }

        $document->registerXPathNamespace('main', $mainNs);
        $strings = [];

        foreach ($document->xpath('//main:si') ?: [] as $item) {
            $item->registerXPathNamespace('main', $mainNs);
            $parts = [];

            foreach ($item->xpath('./main:t') ?: [] as $textNode) {
                $parts[] = (string) $textNode;
            }

            foreach ($item->xpath('./main:r/main:t') ?: [] as $runText) {
                $parts[] = (string) $runText;
            }

            $strings[] = implode('', $parts);
        }

        return $strings;
    }

    private static function read_sheet_targets(ZipArchive $zip): array
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if (! is_string($workbookXml) || ! is_string($relsXml) || $workbookXml === '' || $relsXml === '') {
            throw new RuntimeException('XLSX workbook metadata is incomplete.');
        }

        $workbook = simplexml_load_string($workbookXml);
        $rels = simplexml_load_string($relsXml);

        if (! $workbook instanceof SimpleXMLElement || ! $rels instanceof SimpleXMLElement) {
            throw new RuntimeException('Failed to parse XLSX workbook metadata.');
        }

        $relationshipNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $packageRelationshipNs = 'http://schemas.openxmlformats.org/package/2006/relationships';
        $mainNs = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $workbook->registerXPathNamespace('r', $relationshipNs);
        $workbook->registerXPathNamespace('main', $mainNs);
        $rels->registerXPathNamespace('pkg', $packageRelationshipNs);

        $relMap = [];

        foreach ($rels->xpath('//pkg:Relationship') ?: [] as $relationship) {
            $attributes = $relationship->attributes();
            $id = (string) ($attributes['Id'] ?? '');
            $target = (string) ($attributes['Target'] ?? '');

            if ($id !== '' && $target !== '') {
                $target = ltrim($target, '/');
                $relMap[$id] = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
            }
        }

        $targets = [];

        foreach ($workbook->xpath('//main:sheets/main:sheet') ?: [] as $sheet) {
            $attrs = $sheet->attributes($relationshipNs);
            $sheetName = (string) ($sheet['name'] ?? '');
            $relationshipId = (string) ($attrs['id'] ?? '');

            if ($sheetName === '' || $relationshipId === '' || ! isset($relMap[$relationshipId])) {
                continue;
            }

            $targets[] = [
                'name' => $sheetName,
                'path' => $relMap[$relationshipId],
            ];
        }

        return $targets;
    }

    private static function read_sheet_rows(string $xml, array $sharedStrings): array
    {
        $mainNs = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $sheet = simplexml_load_string($xml);

        if (! $sheet instanceof SimpleXMLElement) {
            return [];
        }

        $sheet->registerXPathNamespace('main', $mainNs);
        $rows = [];

        foreach ($sheet->xpath('//main:sheetData/main:row') ?: [] as $rowNode) {
            $rowNode->registerXPathNamespace('main', $mainNs);
            $row = [];

            foreach ($rowNode->xpath('./main:c') ?: [] as $cell) {
                $cell->registerXPathNamespace('main', $mainNs);
                $reference = (string) ($cell['r'] ?? '');
                $columnIndex = self::column_index_from_reference($reference);
                $type = (string) ($cell['t'] ?? '');
                $value = '';

                if ($type === 's') {
                    $valueNode = $cell->xpath('./main:v');
                    $sharedIndex = isset($valueNode[0]) ? (int) $valueNode[0] : 0;
                    $value = $sharedStrings[$sharedIndex] ?? '';
                } elseif ($type === 'inlineStr') {
                    $inlineText = $cell->xpath('./main:is/main:t');
                    $value = isset($inlineText[0]) ? (string) $inlineText[0] : '';
                } elseif ($type === 'b') {
                    $valueNode = $cell->xpath('./main:v');
                    $value = (isset($valueNode[0]) ? (string) $valueNode[0] : '0') === '1' ? '1' : '0';
                } else {
                    $valueNode = $cell->xpath('./main:v');
                    $value = isset($valueNode[0]) ? (string) $valueNode[0] : '';
                }

                $row[$columnIndex] = $value;
            }

            if ($row === []) {
                continue;
            }

            ksort($row);
            $maxIndex = max(array_keys($row));
            $normalizedRow = array_fill(0, $maxIndex + 1, '');

            foreach ($row as $columnIndex => $value) {
                $normalizedRow[(int) $columnIndex] = $value;
            }

            $rows[] = $normalizedRow;
        }

        return $rows;
    }

    private static function column_index_from_reference(string $reference): int
    {
        if ($reference === '') {
            return 0;
        }

        preg_match('/^[A-Z]+/i', $reference, $matches);
        $letters = strtoupper($matches[0] ?? 'A');
        $index = 0;

        for ($i = 0, $length = strlen($letters); $i < $length; $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }

        return max(0, $index - 1);
    }
}
