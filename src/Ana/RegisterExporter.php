<?php

namespace GlpiPlugin\G4freportsana\Ana;

/** Standalone export of ANA telephone exclusions; does not save submitted rows. */
final class RegisterExporter
{
    public const MAX_ROWS = 500;
    public const MAX_BYTES = 1048576;

    public static function filename(string $kind): string
    {
        self::checkKind($kind);
        return 'ANA_Numeros_Desconsiderados.xlsx';
    }

    public static function export(string $kind, $raw, string $scope = 'all', string $month = ''): string
    {
        self::checkKind($kind);
        if (!in_array($scope, ['all', 'filtered'], true)) {
            throw new \InvalidArgumentException('Seleção de exportação inválida.');
        }
        if ($month !== '' && !preg_match('/\A(20\d{2}|2100)-(0[1-9]|1[0-2])\z/D', $month)) {
            throw new \InvalidArgumentException('Mês inválido para exportação do cadastro.');
        }
        $rows = self::rows($raw, $kind);
        $headers = ['Número de origem', 'Identificação / motivo', 'Excluir'];
        $title = 'Números de chamadas desconsideradas';
        $lastColumn = chr(64 + count($headers));
        $lastDataRow = count($rows) + 3;
        $endRow = $lastDataRow + 1;
        $metadata = ($scope === 'filtered' ? 'Registros da seleção filtrada' : 'Todos os registros da tabela')
            . ' · ' . count($rows) . ' registro(s). Valores da tela, incluindo alterações ainda não salvas. A exportação não salva o cadastro.';
        $sheet = self::xmlHeader() . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><dimension ref="A1:' . $lastColumn . $endRow . '"/><sheetViews><sheetView workbookViewId="0" showGridLines="0">'
            . '<pane ySplit="3" topLeftCell="A4" activePane="bottomLeft" state="frozen"/>'
            . '<selection pane="bottomLeft" activeCell="A4" sqref="A4"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="20"/><cols>';
        $widths = [30, 64, 16];
        foreach ($widths as $i => $width) {
            $sheet .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $width . '" customWidth="1"/>';
        }
        $sheet .= '</cols><sheetData><row r="1" ht="32" customHeight="1">' . self::cell('A1', $title, 1)
            . '</row><row r="2" ht="' . 42 . '" customHeight="1">' . self::cell('A2', $metadata, 2) . '</row><row r="3" ht="32" customHeight="1">';
        foreach ($headers as $i => $header) $sheet .= self::cell(chr(65 + $i) . '3', $header, 3);
        $sheet .= '</row>';
        foreach ($rows as $i => $row) {
            $r = $i + 4;
            $baseStyle = $i % 2 === 0 ? 4 : 5;
            $values = [$row['number'], $row['label'], $row['active'] ? 'Sim' : 'Não'];
            $sheet .= '<row r="' . $r . '" ht="' . self::rowHeight($values, $widths) . '" customHeight="1">';
            foreach ($values as $col => $value) {
                $style = $baseStyle;
                $sheet .= self::cell(chr(65 + $col) . $r, $value, $style);
            }
            $sheet .= '</row>';
        }
        $sheet .= '<row r="' . $endRow . '" ht="24" customHeight="1">'
            . self::cell('A' . $endRow, 'Total de registros', 10) . self::cell('B' . $endRow, count($rows), 10, true)
            . '</row>'
            . '</sheetData><autoFilter ref="A3:' . $lastColumn . $lastDataRow . '"/>'
            . '<mergeCells count="2"><mergeCell ref="A1:' . $lastColumn . '1"/><mergeCell ref="A2:' . $lastColumn . '2"/></mergeCells>'
            . '<pageMargins left="0.3" right="0.3" top="0.4" bottom="0.4" header="0.2" footer="0.2"/>'
            . '<pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/></worksheet>';
        return OfficePackage::write([
            '[Content_Types].xml' => self::xmlHeader() . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels' => self::xmlHeader() . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => self::xmlHeader() . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<bookViews><workbookView/></bookViews><sheets><sheet name="' . 'Números desconsiderados' . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => self::xmlHeader() . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml' => $sheet,
            'xl/styles.xml' => self::styles(),
        ]);
    }

    /** Export accepts incomplete input rows and never invokes register save validation. */
    private static function rows($raw, string $kind): array
    {
        if (is_string($raw)) {
            if (strlen($raw) > self::MAX_BYTES || substr(ltrim($raw), 0, 1) !== '[') {
                throw new \InvalidArgumentException('A exportação aceita uma lista JSON de até 1 MB.');
            }
            $raw = json_decode($raw, true, 16);
            if (json_last_error() !== JSON_ERROR_NONE) throw new \InvalidArgumentException('Dados de exportação inválidos.');
        }
        if (!is_array($raw) || ($raw && array_keys($raw) !== range(0, count($raw) - 1)) || count($raw) > self::MAX_ROWS) {
            throw new \InvalidArgumentException('A exportação aceita até 500 registros.');
        }
        $fields = ['number', 'label'];
        $rows = [];
        foreach ($raw as $source) {
            if (!is_array($source) || ($source && array_keys($source) === range(0, count($source) - 1))) {
                throw new \InvalidArgumentException('Cada registro deve ser um objeto de campos.');
            }
            $row = [];
            foreach ($fields as $field) {
                $value = $source[$field] ?? '';
                if ((!is_string($value) && !is_int($value) && !is_float($value)) || (is_float($value) && !is_finite($value)) || strlen((string)$value) > 2048) {
                    throw new \InvalidArgumentException('Campo de exportação inválido ou muito extenso: ' . $field . '.');
                }
                $row[$field] = (string)$value;
            }
                $active = $source['active'] ?? true;
                if (!in_array($active, [true, false, 1, 0, '1', '0'], true)) {
                    throw new \InvalidArgumentException('Opção de exclusão inválida.');
                }
                $row['active'] = in_array($active, [true, 1, '1'], true);

            $rows[] = $row;
        }
        return $rows;
    }

    private static function checkKind(string $kind): void
    {
        if ($kind !== 'tests') throw new \InvalidArgumentException('Cadastro desconhecido.');
    }

    private static function cell(string $ref, $value, int $style, bool $number = false): string
    {
        if ($number) return '<c r="' . $ref . '" s="' . $style . '" t="n"><v>' . OfficePackage::xml($value) . '</v></c>';
        // Always inline text: =, +, -, @ and phone prefixes never become formulas.
        return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">'
            . OfficePackage::xml($value) . '</t></is></c>';
    }

    private static function rowHeight(array $values, array $widths): int
    {
        $lineCount = 1;
        foreach ($values as $column => $value) {
            // Widths are Excel character units; leave padding and allow for
            // proportional fonts and line wrapping at word boundaries.
            $capacity = max(5, (int)floor(($widths[$column] - 2) * 0.82));
            $lines = 0;
            foreach (preg_split('/\r\n|\r|\n/', (string)$value) as $line) {
                $length = function_exists('mb_strlen') ? mb_strlen($line, 'UTF-8') : preg_match_all('/./us', $line);
                $lines += max(1, (int)ceil($length / $capacity));
            }
            $lineCount = max($lineCount, $lines);
        }
        return min(240, max(32, 16 * $lineCount + 8));
    }

    private static function xmlHeader(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    }

    private static function styles(): string
    {
        $xml = self::xmlHeader() . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/>'
            . '<numFmt numFmtId="165" formatCode="&quot;R$&quot; #,##0.00"/></numFmts>'
            . '<fonts count="4"><font><sz val="11"/><color rgb="FF' . ReportStyle::NAVY . '"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="18"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FF' . ReportStyle::NAVY . '"/><name val="Calibri"/></font></fonts>'
            . '<fills count="6"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>';
        foreach ([ReportStyle::NAVY, ReportStyle::BLUE, ReportStyle::WHITE, ReportStyle::LIGHT_GRAY] as $color) {
            $xml .= '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $color . '"/><bgColor indexed="64"/></patternFill></fill>';
        }
        $xml .= '</fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="12">';
        // normal, title, metadata, header, alternating body/date/currency, total.
        foreach ([[0, 0, 0], [1, 2, 0], [0, 5, 0], [2, 3, 0], [0, 4, 0], [0, 5, 0],
            [0, 4, 164], [0, 5, 164], [0, 4, 165], [0, 5, 165], [3, 5, 0], [3, 5, 165]] as [$font, $fill, $format]) {
            $xml .= '<xf numFmtId="' . $format . '" fontId="' . $font . '" fillId="' . $fill . '" borderId="0" xfId="0" applyAlignment="1"'
                . ($format ? ' applyNumberFormat="1"' : '') . '><alignment vertical="center" wrapText="1"/></xf>';
        }
        return $xml . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }
}
