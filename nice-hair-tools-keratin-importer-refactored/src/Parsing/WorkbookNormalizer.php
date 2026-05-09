<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

trait NH_TKI_WorkbookNormalizerTrait
{
    private function normalize_workbook(array $workbook): array
    {
        $result = [
            'tools' => [],
            'ready_to_install' => [],
            'exclusive_hair' => [],
            'custom_hair' => [],
            'keratin_rows' => [],
            'keratin_groups' => [],
            'warnings' => [],
            'errors' => [],
        ];

        foreach ($workbook['sheets'] as $sheet) {
            $rows = $sheet['rows'] ?? [];

            if ($rows === [] || ! isset($rows[0]) || ! is_array($rows[0])) {
                continue;
            }

            $header = $this->find_sheet_header($rows);

            if (! is_array($header)) {
                continue;
            }

            $headers = (array) $header['headers'];
            $rowOffset = (int) $header['index'];
            $dataRows = array_slice($rows, $rowOffset);

            if ($this->is_ready_to_install_sheet($headers)) {
                $parsed = $this->parse_ready_to_install_rows($dataRows, $headers, $rowOffset);
                $result['ready_to_install'] = array_merge($result['ready_to_install'], $parsed['items']);
                $result['warnings'] = array_merge($result['warnings'], $parsed['warnings']);
                $result['errors'] = array_merge($result['errors'], $parsed['errors']);
                continue;
            }

            if ($this->is_exclusive_hair_sheet($headers)) {
                $parsed = $this->parse_exclusive_hair_rows($dataRows, $headers, $rowOffset);
                $result['exclusive_hair'] = array_merge($result['exclusive_hair'], $parsed['items']);
                $result['warnings'] = array_merge($result['warnings'], $parsed['warnings']);
                $result['errors'] = array_merge($result['errors'], $parsed['errors']);
                continue;
            }

            if ($this->is_custom_hair_sheet($headers)) {
                $parsed = $this->parse_custom_hair_rows($dataRows, $headers, $rowOffset);
                $result['custom_hair'] = array_merge($result['custom_hair'], $parsed['items']);
                $result['warnings'] = array_merge($result['warnings'], $parsed['warnings']);
                $result['errors'] = array_merge($result['errors'], $parsed['errors']);
                continue;
            }

            if ($this->is_tools_sheet($headers)) {
                $parsed = $this->parse_tools_rows($dataRows, $headers, $rowOffset);
                $result['tools'] = array_merge($result['tools'], $parsed['items']);
                $result['warnings'] = array_merge($result['warnings'], $parsed['warnings']);
                $result['errors'] = array_merge($result['errors'], $parsed['errors']);
                continue;
            }

            if ($this->is_keratin_sheet($headers)) {
                $parsed = $this->parse_keratin_rows($dataRows, $headers, $rowOffset);
                $result['keratin_rows'] = array_merge($result['keratin_rows'], $parsed['rows']);
                $result['warnings'] = array_merge($result['warnings'], $parsed['warnings']);
                $result['errors'] = array_merge($result['errors'], $parsed['errors']);
            }
        }

        if ($result['tools'] === [] && $result['keratin_rows'] === [] && $result['ready_to_install'] === [] && $result['exclusive_hair'] === [] && $result['custom_hair'] === []) {
            $result['errors'][] = 'В Excel не найдены листы с данными Tools, Keratin, Ready to Install, Exclusive Hair или Custom Hair.';

            return $result;
        }

        $result['keratin_groups'] = $this->group_keratin_rows($result['keratin_rows'], $result['warnings'], $result['errors']);

        return $result;
    }
}
