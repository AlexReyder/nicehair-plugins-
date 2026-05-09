<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

final class NH_TKI_XLSX_Writer
{
    public static function write(array $rows): string
    {
        return self::write_workbook([
            [
                'name' => 'data',
                'rows' => $rows,
            ],
        ]);
    }

    public static function write_workbook(array $sheets): string
    {
        if (! class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive is not available on this server.');
        }

        $sheets = self::normalize_sheets($sheets);
        $tempDirectory = function_exists('get_temp_dir') ? get_temp_dir() : sys_get_temp_dir();
        $path = tempnam($tempDirectory, 'nh-tki-export-');

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('Не удалось создать временный файл экспорта.');
        }

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Не удалось открыть временный XLSX-файл.');
        }

        $zip->addFromString('[Content_Types].xml', self::content_types_xml(count($sheets)));
        $zip->addFromString('_rels/.rels', self::root_relationships_xml());
        $zip->addFromString('xl/workbook.xml', self::workbook_xml($sheets));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbook_relationships_xml(count($sheets)));

        foreach ($sheets as $index => $sheet) {
            $zip->addFromString(
                'xl/worksheets/sheet' . ($index + 1) . '.xml',
                self::sheet_xml((array) ($sheet['rows'] ?? []), (array) ($sheet['data_validations'] ?? []))
            );
        }

        $zip->close();

        $content = file_get_contents($path);
        @unlink($path);

        if (! is_string($content) || $content === '') {
            throw new RuntimeException('Не удалось прочитать временный XLSX-файл.');
        }

        return $content;
    }

    private static function normalize_sheets(array $sheets): array
    {
        $normalized = [];
        $usedNames = [];

        foreach (array_values($sheets) as $index => $sheet) {
            if (is_array($sheet) && array_key_exists('rows', $sheet)) {
                $name = (string) ($sheet['name'] ?? 'Sheet ' . ($index + 1));
                $rows = is_array($sheet['rows']) ? $sheet['rows'] : [];
            } else {
                $name = 'Sheet ' . ($index + 1);
                $rows = is_array($sheet) ? $sheet : [];
            }

            $normalized[] = [
                'name' => self::normalize_sheet_name($name, $usedNames),
                'rows' => $rows,
                    'hidden' => ! empty($sheet['hidden']),
                    'data_validations' => isset($sheet['data_validations']) && is_array($sheet['data_validations']) ? $sheet['data_validations'] : [],
                    'defined_names' => isset($sheet['defined_names']) && is_array($sheet['defined_names']) ? $sheet['defined_names'] : [],
            ];
        }

        if ($normalized === []) {
            $normalized[] = [
                'name' => self::normalize_sheet_name('data', $usedNames),
                'rows' => [],
                'hidden' => false,
                'data_validations' => [],
                'defined_names' => [],
            ];
        }

        return $normalized;
    }

    private static function normalize_sheet_name(string $name, array &$usedNames): string
    {
        $name = (string) preg_replace('/[\[\]\:\*\?\/\\\\]+/u', ' ', $name);
        $name = (string) preg_replace('/\s+/u', ' ', trim($name));

        if ($name === '') {
            $name = 'Sheet';
        }

        $name = self::truncate_text($name, 31);
        $baseName = $name;
        $counter = 2;

        while (isset($usedNames[self::sheet_name_key($name)])) {
            $suffix = ' ' . $counter;
            $name = self::truncate_text($baseName, max(1, 31 - self::text_length($suffix))) . $suffix;
            $counter++;
        }

        $usedNames[self::sheet_name_key($name)] = true;

        return $name;
    }

    private static function sheet_name_key(string $name): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    }

    private static function truncate_text(string $value, int $maxLength): string
    {
        if (self::text_length($value) <= $maxLength) {
            return $value;
        }

        return function_exists('mb_substr')
            ? mb_substr($value, 0, $maxLength, 'UTF-8')
            : substr($value, 0, $maxLength);
    }

    private static function text_length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private static function content_types_xml(int $sheetCount): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';

        for ($index = 1; $index <= $sheetCount; $index++) {
            $xml .= '<Override PartName="/xl/worksheets/sheet' . $index . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return $xml . '</Types>';
    }

    private static function root_relationships_xml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbook_xml(array $sheets): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>';

        foreach (array_values($sheets) as $index => $sheet) {
            $sheetId = $index + 1;
            $state = ! empty($sheet['hidden']) ? ' state="hidden"' : '';
            $xml .= '<sheet name="' . esc_attr((string) $sheet['name']) . '" sheetId="' . $sheetId . '"' . $state . ' r:id="rId' . $sheetId . '"/>';
        }

        $xml .= '</sheets>';

        $definedNamesXml = self::defined_names_xml($sheets);

        if ($definedNamesXml !== '') {
            $xml .= $definedNamesXml;
        }

        return $xml . '</workbook>';
    }

    private static function defined_names_xml(array $sheets): string
    {
        $xml = '';

        foreach ($sheets as $sheet) {
            foreach ((array) ($sheet['defined_names'] ?? []) as $definedName) {
                if (! is_array($definedName)) {
                    continue;
                }

                $name = trim((string) ($definedName['name'] ?? ''));
                $reference = trim((string) ($definedName['ref'] ?? ''));

                if ($name === '' || $reference === '') {
                    continue;
                }

                $xml .= '<definedName name="' . esc_attr($name) . '">' . self::xml_text($reference) . '</definedName>';
            }
        }

        return $xml !== '' ? '<definedNames>' . $xml . '</definedNames>' : '';
    }

    private static function workbook_relationships_xml(int $sheetCount): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

        for ($index = 1; $index <= $sheetCount; $index++) {
            $xml .= '<Relationship Id="rId' . $index . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $index . '.xml"/>';
        }

        return $xml . '</Relationships>';
    }

    private static function sheet_xml(array $rows, array $dataValidations = []): string
    {
        $rowCount = max(1, count($rows));
        $columnCount = 1;

        foreach ($rows as $row) {
            $columnCount = max($columnCount, is_array($row) ? count($row) : 1);
        }

        $dimension = 'A1:' . self::cell_reference($columnCount - 1, $rowCount);
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="' . esc_attr($dimension) . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="18"/>'
            . '<sheetData>';

        foreach (array_values($rows) as $rowIndex => $row) {
            $excelRow = $rowIndex + 1;
            $xml .= '<row r="' . $excelRow . '">';

            foreach (array_values((array) $row) as $columnIndex => $value) {
                $reference = self::cell_reference($columnIndex, $excelRow);
                $xml .= '<c r="' . esc_attr($reference) . '" t="inlineStr"><is><t xml:space="preserve">' . self::xml_text((string) $value) . '</t></is></c>';
            }

            $xml .= '</row>';
        }

        $xml .= '</sheetData>';

        $dataValidationsXml = self::data_validations_xml($dataValidations);

        if ($dataValidationsXml !== '') {
            $xml .= $dataValidationsXml;
        }

        return $xml . '</worksheet>';
    }

    private static function data_validations_xml(array $dataValidations): string
    {
        $items = [];

        foreach ($dataValidations as $dataValidation) {
            if (! is_array($dataValidation)) {
                continue;
            }

            $sqref = trim((string) ($dataValidation['sqref'] ?? ''));
            $formula1 = trim((string) ($dataValidation['formula1'] ?? ''));

            if ($sqref === '' || $formula1 === '') {
                continue;
            }

            $type = (string) ($dataValidation['type'] ?? 'list');
            $allowBlank = ! empty($dataValidation['allow_blank']) || ! empty($dataValidation['allowBlank']);
            $showErrorMessage = array_key_exists('show_error_message', $dataValidation)
                ? ! empty($dataValidation['show_error_message'])
                : true;
            $errorStyle = (string) ($dataValidation['error_style'] ?? 'stop');
            $errorTitle = (string) ($dataValidation['error_title'] ?? '');
            $error = (string) ($dataValidation['error'] ?? '');

            $attributes = [
                'type="' . esc_attr($type) . '"',
                'allowBlank="' . ($allowBlank ? '1' : '0') . '"',
                'showErrorMessage="' . ($showErrorMessage ? '1' : '0') . '"',
                'errorStyle="' . esc_attr($errorStyle) . '"',
                'sqref="' . esc_attr($sqref) . '"',
            ];

            if ($errorTitle !== '') {
                $attributes[] = 'errorTitle="' . esc_attr($errorTitle) . '"';
            }

            if ($error !== '') {
                $attributes[] = 'error="' . esc_attr($error) . '"';
            }

            $items[] = '<dataValidation ' . implode(' ', $attributes) . '><formula1>' . self::xml_text($formula1) . '</formula1></dataValidation>';
        }

        if ($items === []) {
            return '';
        }

        return '<dataValidations count="' . count($items) . '">' . implode('', $items) . '</dataValidations>';
    }

    private static function cell_reference(int $columnIndex, int $rowIndex): string
    {
        $column = '';
        $index = $columnIndex + 1;

        while ($index > 0) {
            $index--;
            $column = chr(65 + ($index % 26)) . $column;
            $index = intdiv($index, 26);
        }

        return $column . $rowIndex;
    }

    private static function xml_text(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
