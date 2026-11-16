<?php

namespace GlpiPlugin\G4freportsana\Ana;

/** Native Word charts with a self-contained editable workbook for every figure. */
final class WordChart
{
    private array $parts = [];

    public function paragraph(array $definition, int $height = 3600): string
    {
        if (empty($definition['available']) || empty($definition['categories']) || empty($definition['series'])) {
            return '';
        }
        $index = count($this->parts) + 1;
        $rel = 'rIdANAChart' . $index;
        $name = 'ana_chart_' . $index;
        $this->parts[] = [
            'rel' => $rel,
            'name' => $name,
            'chart' => $this->chart($definition, $index),
            'workbook' => $this->workbook($definition),
        ];
        $title = OfficePackage::xml($definition['title']);
        return '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:before="80" w:after="180"/><w:keepNext/></w:pPr><w:r><w:drawing>'
            . '<wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="6032500" cy="' . ($height * 635) . '"/><wp:effectExtent l="0" t="0" r="0" b="0"/>'
            . '<wp:docPr id="' . (9500 + $index) . '" name="' . $title . '" descr="' . OfficePackage::xml($definition['note'] ?? $definition['title']) . '"/>'
            . '<wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart">'
            . '<c:chart r:id="' . $rel . '"/></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
    }

    public function attach(array &$files): void
    {
        foreach ($this->parts as $part) {
            $chartPath = 'word/charts/' . $part['name'] . '.xml';
            $bookPath = 'word/embeddings/' . $part['name'] . '.xlsx';
            $files[$chartPath] = $part['chart'];
            $files[$bookPath] = $part['workbook'];
            $files['word/charts/_rels/' . $part['name'] . '.xml.rels'] = $this->relationships([
                ['rIdWorkbook', 'package', '../embeddings/' . $part['name'] . '.xlsx'],
            ]);
            $files['word/_rels/document.xml.rels'] = str_replace('</Relationships>', '<Relationship Id="' . $part['rel'] . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="charts/' . $part['name'] . '.xml"/></Relationships>', $files['word/_rels/document.xml.rels']);
            $files['[Content_Types].xml'] = str_replace('</Types>', '<Override PartName="/' . $chartPath . '" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/><Override PartName="/' . $bookPath . '" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"/></Types>', $files['[Content_Types].xml']);
        }
    }

    private function chart(array $d, int $index): string
    {
        $kind = $d['kind'] ?? 'bar';
        if (in_array($d['id'] ?? '', ['tickets_daily', 'calls_daily'], true)) $d['axis_category_title'] = 'Dia';
        $line = $kind === 'line';
        $circular = in_array($kind, ['pie', 'doughnut'], true);
        $horizontal = $kind === 'bar';
        $stacked = !empty($d['stacked']);
        $percent = ($d['unit'] ?? 'count') === 'percent';
        $score = ($d['unit'] ?? '') === 'score';
        $format = $percent ? '[$-416]0.00%' : ($score ? '[$-416]0.00' : '[$-416]#,##0');
        $denseOverview = $percent && count($d['categories']) > 5;
        $dataLabels = '<c:dLbls><c:numFmt formatCode="' . $format . '" sourceLinked="0"/>' . $this->textProperties(1000, $score ? ReportStyle::WHITE : ReportStyle::NAVY)
            . '<c:dLblPos val="' . ($line ? 't' : ($score ? 'inEnd' : ($stacked ? 'ctr' : 'outEnd'))) . '"/><c:showLegendKey val="0"/><c:showVal val="1"/><c:showCatName val="0"/><c:showSerName val="0"/></c:dLbls>';
        $series = '';
        $categoryCache = '<c:ptCount val="' . count($d['categories']) . '"/>';
        foreach ($d['categories'] as $i => $label) {
            $categoryCache .= '<c:pt idx="' . $i . '"><c:v>' . OfficePackage::xml($label) . '</c:v></c:pt>';
        }
        $last = count($d['categories']) + 1;
        foreach ($d['series'] as $i => $s) {
            $column = $this->column($i + 2);
            $color = preg_match('/^[0-9A-Fa-f]{6}$/', $s['color'] ?? '') ? $s['color'] : ReportStyle::BLUE;
            $values = '';
            foreach ($d['categories'] as $j => $unused) {
                $value = $s['values'][$j] ?? null;
                if ($value !== null && is_numeric($value)) {
                    $values .= '<c:pt idx="' . $j . '"><c:v>' . $this->number((float)$value) . '</c:v></c:pt>';
                }
            }
            $series .= '<c:ser><c:idx val="' . $i . '"/><c:order val="' . $i . '"/><c:tx><c:strRef><c:f>Dados!$' . $column . '$1</c:f><c:strCache><c:ptCount val="1"/><c:pt idx="0"><c:v>' . OfficePackage::xml($s['name']) . '</c:v></c:pt></c:strCache></c:strRef></c:tx>';
            $series .= '<c:spPr><a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill><a:ln w="' . ($line ? '25400' : '0') . '">' . ($line ? '<a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill>' : '<a:noFill/>') . '</a:ln></c:spPr>';
            if ($line) {
                $series .= '<c:marker><c:symbol val="circle"/><c:size val="4"/></c:marker>';
            }
            if (count($d['series']) === 1) {
                foreach ($d['point_colors'] ?? [] as $point => $pointColor) {
                    if (isset($d['categories'][$point]) && preg_match('/^[0-9A-Fa-f]{6}$/', $pointColor)) {
                        $series .= '<c:dPt><c:idx val="' . (int)$point . '"/><c:spPr><a:solidFill><a:srgbClr val="' . $pointColor . '"/></a:solidFill><a:ln w="6350"><a:solidFill><a:srgbClr val="' . ReportStyle::WHITE . '"/></a:solidFill></a:ln></c:spPr></c:dPt>';
                    }
                }
            }
            // Office requires customized labels inside ser, after dPt and before cat/val.
            if ($circular) {
                // Keep every source value; the composition table also labels small/zero slices.
                $total = array_sum($s['values']);
                $sliceLabels = '';
                foreach ($s['values'] as $point => $value) {
                    if ($value === null || $total <= 0 || $value / $total < 0.05) {
                        $sliceLabels .= '<c:dLbl><c:idx val="' . $point . '"/><c:delete val="1"/></c:dLbl>';
                    }
                }
                $series .= '<c:dLbls>' . $sliceLabels . '<c:numFmt formatCode="[$-416]0%" sourceLinked="0"/>' . $this->textProperties(900, ReportStyle::NAVY)
                    // Doughnut series must use automatic (centered) placement: MS-OI29500 §2.1.1456.
                    . ($kind === 'doughnut' ? '' : '<c:dLblPos val="ctr"/>')
                    . '<c:showLegendKey val="0"/><c:showVal val="0"/><c:showCatName val="0"/><c:showSerName val="0"/><c:showPercent val="1"/><c:showLeaderLines val="0"/></c:dLbls>';
            } elseif (($denseOverview && $i === 0) || (count($d['categories']) <= 12 && !$stacked && !$denseOverview)) {
                $series .= $dataLabels;
            }
            $series .= '<c:cat><c:strRef><c:f>Dados!$A$2:$A$' . $last . '</c:f><c:strCache>' . $categoryCache . '</c:strCache></c:strRef></c:cat>';
            $series .= '<c:val><c:numRef><c:f>Dados!$' . $column . '$2:$' . $column . '$' . $last . '</c:f><c:numCache><c:formatCode>' . $format . '</c:formatCode><c:ptCount val="' . count($d['categories']) . '"/>' . $values . '</c:numCache></c:numRef></c:val>' . ($line ? '<c:smooth val="0"/>' : '') . '</c:ser>';
        }
        $catId = 730000 + $index * 2;
        $valId = $catId + 1;
        $axesIds = '<c:axId val="' . $catId . '"/><c:axId val="' . $valId . '"/>';
        if ($circular) {
            $tag = $kind === 'doughnut' ? 'doughnutChart' : 'pieChart';
            $plot = '<c:' . $tag . '><c:varyColors val="1"/>' . $series . '<c:firstSliceAng val="270"/>'
                . ($kind === 'doughnut' ? '<c:holeSize val="60"/>' : '') . '</c:' . $tag . '>';
        } elseif ($line) {
            $plot = '<c:lineChart><c:grouping val="standard"/><c:varyColors val="0"/>' . $series . '<c:marker val="1"/><c:smooth val="0"/>' . $axesIds . '</c:lineChart>';
        } else {
            $plot = '<c:barChart><c:barDir val="' . ($horizontal ? 'bar' : 'col') . '"/><c:grouping val="' . ($stacked ? 'stacked' : 'clustered') . '"/><c:varyColors val="0"/>' . $series . '<c:gapWidth val="' . ($stacked ? '45' : '100') . '"/><c:overlap val="' . ($stacked ? '100' : '0') . '"/>' . $axesIds . '</c:barChart>';
        }
        $grid = '<c:majorGridlines><c:spPr><a:ln w="6350"><a:solidFill><a:srgbClr val="' . ReportStyle::BORDER . '"/></a:solidFill></a:ln></c:spPr></c:majorGridlines>';
        if (!$circular) {
        $plot .= '<c:catAx><c:axId val="' . $catId . '"/><c:scaling><c:orientation val="' . ($horizontal ? 'maxMin' : 'minMax') . '"/></c:scaling><c:delete val="0"/><c:axPos val="' . ($horizontal ? 'l' : 'b') . '"/>' . $this->axisTitle($d['axis_category_title'] ?? ($percent ? 'Indicador' : 'Categoria')) . '<c:majorTickMark val="none"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/>' . $this->textProperties(count($d['categories']) > 20 ? 850 : ($denseOverview ? 950 : 1050)) . '<c:crossAx val="' . $valId . '"/><c:crosses val="autoZero"/><c:auto val="1"/><c:lblAlgn val="ctr"/><c:lblOffset val="100"/>'
            // Keep each category identifiable in small operational breakdowns.
            . (count($d['categories']) <= 12 ? '<c:tickLblSkip val="1"/><c:tickMarkSkip val="1"/>' : '')
            . '<c:noMultiLvlLbl val="1"/></c:catAx>';
        $plot .= '<c:valAx><c:axId val="' . $valId . '"/><c:scaling><c:orientation val="minMax"/><c:min val="0"/>' . ($score ? '<c:max val="5"/>' : ($percent ? '<c:max val="1"/>' : '')) . '</c:scaling><c:delete val="0"/><c:axPos val="' . ($horizontal ? 'b' : 'l') . '"/>' . $grid . $this->axisTitle($d['axis_value_title'] ?? ($percent ? 'Percentual (%)' : 'Quantidade')) . '<c:numFmt formatCode="' . ($percent ? '[$-416]0%' : ($score ? '[$-416]0.00' : '[$-416]#,##0')) . '" sourceLinked="0"/><c:majorTickMark val="none"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/>' . $this->textProperties(950) . '<c:crossAx val="' . $catId . '"/><c:crosses val="' . ($horizontal ? 'max' : 'autoZero') . '"/><c:crossBetween val="between"/>' . (!$percent ? '<c:majorUnit val="' . $this->countUnit($d) . '"/>' : '') . '</c:valAx>';
        }
        // A circular chart has one series but its legend identifies categories.
        // Single-series bars identify categories on their axis instead.
        $legend = $circular || count($d['series']) > 1 ? '<c:legend><c:legendPos val="b"/><c:layout/><c:overlay val="0"/>' . $this->textProperties(1000) . '</c:legend>' : '';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><c:date1904 val="0"/><c:lang val="pt-BR"/><c:roundedCorners val="0"/><c:chart>'
            . '<c:title><c:tx><c:rich><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="1250" b="1"><a:solidFill><a:srgbClr val="' . ReportStyle::NAVY . '"/></a:solidFill><a:latin typeface="Arial"/></a:defRPr></a:pPr><a:r><a:t>' . OfficePackage::xml($d['title']) . '</a:t></a:r></a:p></c:rich></c:tx><c:layout/><c:overlay val="0"/></c:title>'
            . '<c:autoTitleDeleted val="0"/><c:plotArea><c:layout/>' . $plot . '</c:plotArea>' . $legend . '<c:plotVisOnly val="1"/><c:dispBlanksAs val="gap"/><c:showDLblsOverMax val="0"/></c:chart><c:spPr><a:solidFill><a:srgbClr val="' . ReportStyle::WHITE . '"/></a:solidFill><a:ln><a:noFill/></a:ln></c:spPr>' . $this->textProperties(1050)
            . '<c:externalData r:id="rIdWorkbook"><c:autoUpdate val="0"/></c:externalData></c:chartSpace>';
    }

    /** CT_CatAx/CT_ValAx use the same rich-text title as the chart. */
    private function axisTitle(string $text): string
    {
        if ($text === '') return '';
        return '<c:title><c:tx><c:rich><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="1000"><a:solidFill><a:srgbClr val="'
            . ReportStyle::NAVY . '"/></a:solidFill><a:latin typeface="Arial"/></a:defRPr></a:pPr><a:r><a:t>'
            . OfficePackage::xml($text) . '</a:t></a:r></a:p></c:rich></c:tx><c:layout/><c:overlay val="0"/></c:title>';
    }

    private function textProperties(int $size, string $color = ReportStyle::NAVY): string
    {
        return '<c:txPr><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="' . $size . '"><a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill><a:latin typeface="Arial"/></a:defRPr></a:pPr><a:endParaRPr lang="pt-BR"/></a:p></c:txPr>';
    }

    private function countUnit(array $d): string
    {
        $maximum = 0;
        foreach ($d['categories'] as $i => $unused) {
            $total = 0;
            foreach ($d['series'] as $s) {
                $value = (float)($s['values'][$i] ?? 0);
                $total += $value;
                $maximum = max($maximum, $value);
            }
            if (!empty($d['stacked'])) {
                $maximum = max($maximum, $total);
            }
        }
        foreach ([1, 2, 5, 10, 20, 50, 100, 200, 500, 1000, 2000, 5000, 10000, 20000, 50000] as $step) {
            if ($maximum <= $step * 6) {
                return (string)$step;
            }
        }
        return (string)(ceil($maximum / 60000) * 10000);
    }

    private function workbook(array $d): string
    {
        $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $rows = '<row r="1">' . $this->stringCell('A1', 'Categoria');
        foreach ($d['series'] as $i => $s) {
            $rows .= $this->stringCell($this->column($i + 2) . '1', $s['name']);
        }
        $rows .= '</row>';
        foreach ($d['categories'] as $i => $category) {
            $row = $i + 2;
            $rows .= '<row r="' . $row . '">' . $this->stringCell('A' . $row, $category);
            foreach ($d['series'] as $j => $s) {
                $value = $s['values'][$i] ?? null;
                if ($value !== null && is_numeric($value)) {
                    $rows .= '<c r="' . $this->column($j + 2) . $row . '" s="' . (($d['unit'] ?? '') === 'percent' ? '1' : '0') . '"><v>' . $this->number((float)$value) . '</v></c>';
                }
            }
            $rows .= '</row>';
        }
        return OfficePackage::write([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels' => $this->relationships([['rId1', 'officeDocument', 'xl/workbook.xml']]),
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="' . $namespace . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView/></bookViews><sheets><sheet name="Dados" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => $this->relationships([['rId1', 'worksheet', 'worksheets/sheet1.xml'], ['rId2', 'styles', 'styles.xml']]),
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="' . $namespace . '"><fonts count="1"><font><sz val="11"/><name val="Arial"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="10" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="' . $namespace . '"><sheetViews><sheetView workbookViewId="0"/></sheetViews><sheetFormatPr defaultRowHeight="15"/><cols><col min="1" max="1" width="45" customWidth="1"/><col min="2" max="' . (count($d['series']) + 1) . '" width="24" customWidth="1"/></cols><sheetData>' . $rows . '</sheetData></worksheet>',
        ]);
    }

    private function relationships(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($rows as $row) {
            $xml .= '<Relationship Id="' . $row[0] . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/' . $row[1] . '" Target="' . $row[2] . '"/>';
        }
        return $xml . '</Relationships>';
    }

    private function stringCell(string $ref, string $text): string
    {
        return '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . OfficePackage::xml($text) . '</t></is></c>';
    }

    private function column(int $number): string
    {
        $label = '';
        while ($number > 0) {
            $number--;
            $label = chr(65 + $number % 26) . $label;
            $number = intdiv($number, 26);
        }
        return $label;
    }

    private function number(float $number): string
    {
        return rtrim(rtrim(number_format($number, 10, '.', ''), '0'), '.') ?: '0';
    }
}
