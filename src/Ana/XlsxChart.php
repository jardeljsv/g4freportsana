<?php

namespace GlpiPlugin\G4freportsana\Ana;

/** Native editable spreadsheet chart parts with references to visible source cells. */
final class XlsxChart
{
    public static function anchor(array $chart, string $relationship, int $id): string
    {
        [$left, $top, $right, $bottom] = $chart['anchor'];
        $xml = '<xdr:twoCellAnchor editAs="oneCell">';
        foreach (['from' => [$left, $top], 'to' => [$right, $bottom]] as $tag => $position) {
            $xml .= '<xdr:' . $tag . '><xdr:col>' . $position[0] . '</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>' . $position[1] . '</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:' . $tag . '>';
        }
        return $xml . '<xdr:graphicFrame macro=""><xdr:nvGraphicFramePr><xdr:cNvPr id="' . $id . '" name="' . OfficePackage::xml($chart['title']) . '"/><xdr:cNvGraphicFramePr/></xdr:nvGraphicFramePr><xdr:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></xdr:xfrm><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart"><c:chart xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:id="' . $relationship . '"/></a:graphicData></a:graphic></xdr:graphicFrame><xdr:clientData/></xdr:twoCellAnchor>';
    }

    public static function xml(array $chart): string
    {
        $kind = $chart['kind'];
        if (in_array($chart['id'] ?? '', ['tickets_daily', 'calls_daily'], true)) $chart['axis_category_title'] = 'Dia';
        $circular = in_array($kind, ['pie', 'doughnut'], true);
        $line = $kind === 'line';
        $horizontal = $kind === 'bar';
        $percent = $chart['unit'] === 'percent';
        $score = ($chart['unit'] ?? '') === 'score';
        $format = $percent ? '0.00%' : ($score ? '0.00' : '#,##0');
        $count = count($chart['categories']);
        $dense = $count > 12;
        $maximum = 0;
        foreach ($chart['series'] as $series) { foreach ($series['values'] as $value) { if ($value !== null) { $maximum = max($maximum, $value); } } }
        if (!empty($chart['stacked'])) {
            for ($i = 0; $i < $count; $i++) {
                $sum = 0; foreach ($chart['series'] as $series) { $sum += $series['values'][$i] ?? 0; }
                $maximum = max($maximum, $sum);
            }
        }
        $maximum = $percent ? max(1, $maximum) : ($score ? 5 : max(1, ceil($maximum * 1.15)));
        $majorUnit = !$percent && $maximum <= 20 ? max(1, ceil($maximum / 6)) : null;
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><c:lang val="pt-BR"/><c:roundedCorners val="0"/><c:chart><c:title><c:tx><c:rich><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="1250" b="1"/></a:pPr><a:r><a:rPr lang="pt-BR"/><a:t>' . OfficePackage::xml($chart['title']) . '</a:t></a:r></a:p></c:rich></c:tx><c:overlay val="0"/></c:title><c:autoTitleDeleted val="0"/><c:plotArea><c:layout/>';
        $chartTag = $circular ? ($kind . 'Chart') : ($line ? 'lineChart' : 'barChart');
        $xml .= '<c:' . $chartTag . '>';
        if (!$circular && !$line) { $xml .= '<c:barDir val="' . ($horizontal ? 'bar' : 'col') . '"/>'; }
        if (!$circular) { $xml .= '<c:grouping val="' . ($line ? 'standard' : (!empty($chart['stacked']) ? 'stacked' : 'clustered')) . '"/>'; }
        $xml .= '<c:varyColors val="' . ($circular ? '1' : '0') . '"/>';
        foreach ($chart['series'] as $index => $series) {
            $color = $series['color'];
            $xml .= '<c:ser><c:idx val="' . $index . '"/><c:order val="' . $index . '"/><c:tx><c:v>' . OfficePackage::xml($series['name']) . '</c:v></c:tx><c:spPr>';
            $xml .= $line ? '<a:ln w="25400"><a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill></a:ln>' : '<a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill><a:ln><a:noFill/></a:ln>';
            $xml .= '</c:spPr>';
            if ($line) { $xml .= '<c:marker><c:symbol val="circle"/><c:size val="4"/><c:spPr><a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill><a:ln><a:noFill/></a:ln></c:spPr></c:marker>'; }
            if (count($chart['series']) === 1 && !empty($chart['point_colors'])) {
                foreach ($chart['point_colors'] as $point => $pointColor) {
                    $xml .= '<c:dPt><c:idx val="' . $point . '"/><c:spPr><a:solidFill><a:srgbClr val="' . $pointColor . '"/></a:solidFill><a:ln><a:noFill/></a:ln></c:spPr></c:dPt>';
                }
            }
            // Office requires custom formatting and per-point labels inside ser, before cat/val.
            if ($circular) {
                // Small/zero slices retain their legend and exact adjacent source counts.
                $sum = array_sum($series['values']);
                $xml .= '<c:dLbls>';
                foreach ($series['values'] as $point => $value) {
                    $show = $value !== null && $sum > 0 && $value / $sum >= 0.05;
                    // CT_DLbl requires either delete or label settings, never both.
                    $xml .= '<c:dLbl><c:idx val="' . $point . '"/>';
                    $xml .= $show
                        ? '<c:showVal val="0"/><c:showCatName val="0"/><c:showSerName val="0"/><c:showPercent val="1"/>'
                        : '<c:delete val="1"/>';
                    $xml .= '</c:dLbl>';
                }
                $xml .= '<c:numFmt formatCode="0%" sourceLinked="0"/><c:txPr><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="1400" b="1"><a:solidFill><a:srgbClr val="' . ReportStyle::NAVY . '"/></a:solidFill><a:latin typeface="Arial"/></a:defRPr></a:pPr><a:endParaRPr lang="pt-BR"/></a:p></c:txPr>'
                    // Doughnut series use automatic centering; an explicit position is unsupported.
                    . ($kind === 'doughnut' ? '' : '<c:dLblPos val="ctr"/>')
                    . '<c:showLegendKey val="0"/><c:showVal val="0"/><c:showCatName val="0"/><c:showSerName val="0"/><c:showPercent val="1"/><c:showLeaderLines val="0"/></c:dLbls>';
            } elseif (!$dense) {
                $xml .= '<c:dLbls><c:numFmt formatCode="' . $format . '" sourceLinked="0"/><c:dLblPos val="' . ($line ? 't' : (!empty($chart['stacked']) ? 'ctr' : 'outEnd')) . '"/><c:showLegendKey val="0"/><c:showVal val="1"/><c:showCatName val="0"/><c:showSerName val="0"/></c:dLbls>';
            }
            // A metric comparison can use its immutable INS identifier as a
            // literal category while both values remain linked to editable cells.
            $hasCategoryReference = isset($chart['category_ref']) && $chart['category_ref'] !== '';
            $xml .= '<c:cat>' . ($hasCategoryReference
                ? '<c:strRef><c:f>' . OfficePackage::xml($chart['category_ref']) . '</c:f><c:strCache>'
                : '<c:strLit>') . '<c:ptCount val="' . $count . '"/>';
            foreach ($chart['categories'] as $point => $label) { $xml .= '<c:pt idx="' . $point . '"><c:v>' . OfficePackage::xml($label) . '</c:v></c:pt>'; }
            $xml .= ($hasCategoryReference ? '</c:strCache></c:strRef>' : '</c:strLit>')
                . '</c:cat><c:val><c:numRef><c:f>' . OfficePackage::xml($series['value_ref']) . '</c:f><c:numCache><c:formatCode>' . $format . '</c:formatCode><c:ptCount val="' . $count . '"/>';
            foreach ($series['values'] as $point => $value) {
                if ($value !== null) { $xml .= '<c:pt idx="' . $point . '"><c:v>' . sprintf('%.15g', $value) . '</c:v></c:pt>'; }
            }
            $xml .= '</c:numCache></c:numRef></c:val>' . ($line ? '<c:smooth val="0"/>' : '') . '</c:ser>';
        }
        if ($circular) {
            $xml .= '<c:firstSliceAng val="270"/>';
            if ($kind === 'doughnut') { $xml .= '<c:holeSize val="60"/>'; }
        }
        if (!$circular && !$line) { $xml .= '<c:gapWidth val="' . ($dense ? '45' : '65') . '"/><c:overlap val="' . (!empty($chart['stacked']) ? '100' : '0') . '"/>'; }
        if ($line) { $xml .= '<c:marker val="1"/><c:smooth val="0"/>'; }
        if (!$circular) { $xml .= '<c:axId val="100"/><c:axId val="200"/>'; }
        $xml .= '</c:' . $chartTag . '>';
        if (!$circular) {
            $xml .= '<c:catAx><c:axId val="100"/><c:scaling><c:orientation val="' . ($horizontal ? 'maxMin' : 'minMax') . '"/></c:scaling><c:delete val="0"/><c:axPos val="' . ($horizontal ? 'l' : 'b') . '"/>' . self::axisTitle($chart['axis_category_title'] ?? ($percent ? 'Indicador' : 'Categoria')) . '<c:majorTickMark val="none"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/><c:spPr><a:ln><a:noFill/></a:ln></c:spPr>' . self::textProperties($count > 20 ? 850 : 1050) . '<c:crossAx val="200"/><c:crosses val="autoZero"/><c:auto val="1"/><c:lblAlgn val="ctr"/><c:lblOffset val="100"/>'
                . ($count <= 12 ? '<c:tickLblSkip val="1"/><c:tickMarkSkip val="1"/>' : '')
                . '<c:noMultiLvlLbl val="1"/></c:catAx>';
            $xml .= '<c:valAx><c:axId val="200"/><c:scaling><c:orientation val="minMax"/><c:max val="' . sprintf('%.15g', $maximum) . '"/><c:min val="0"/></c:scaling><c:axPos val="' . ($horizontal ? 'b' : 'l') . '"/><c:majorGridlines><c:spPr><a:ln w="6350"><a:solidFill><a:srgbClr val="' . ReportStyle::LIGHT_GRAY . '"/></a:solidFill></a:ln></c:spPr></c:majorGridlines>' . self::axisTitle($chart['axis_value_title'] ?? ($percent ? 'Percentual (%)' : 'Quantidade')) . '<c:numFmt formatCode="' . ($percent ? '0%' : ($score ? '0.00' : '#,##0')) . '" sourceLinked="0"/><c:majorTickMark val="none"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/><c:spPr><a:ln><a:noFill/></a:ln></c:spPr><c:crossAx val="100"/><c:crosses val="' . ($horizontal ? 'max' : 'autoZero') . '"/><c:crossBetween val="' . ($line ? 'midCat' : 'between') . '"/>' . ($majorUnit !== null ? '<c:majorUnit val="' . $majorUnit . '"/>' : '') . '</c:valAx>';
        }
        $xml .= '</c:plotArea>';
        if ($circular || count($chart['series']) > 1) { $xml .= '<c:legend><c:legendPos val="b"/><c:overlay val="0"/>' . self::textProperties(1000) . '</c:legend>'; }
        return $xml . '<c:plotVisOnly val="1"/><c:dispBlanksAs val="gap"/><c:showDLblsOverMax val="0"/></c:chart><c:spPr><a:solidFill><a:srgbClr val="' . ReportStyle::WHITE . '"/></a:solidFill><a:ln><a:noFill/></a:ln></c:spPr><c:txPr><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="1000"><a:solidFill><a:srgbClr val="' . ReportStyle::NAVY . '"/></a:solidFill><a:latin typeface="Arial"/></a:defRPr></a:pPr><a:endParaRPr lang="pt-BR"/></a:p></c:txPr></c:chartSpace>';
    }

    /** CT_CatAx/CT_ValAx use the same rich-text title as the chart. */
    private static function axisTitle(string $text): string
    {
        if ($text === '') return '';
        return '<c:title><c:tx><c:rich><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="1000"><a:solidFill><a:srgbClr val="'
            . ReportStyle::NAVY . '"/></a:solidFill><a:latin typeface="Arial"/></a:defRPr></a:pPr><a:r><a:t>'
            . OfficePackage::xml($text) . '</a:t></a:r></a:p></c:rich></c:tx><c:layout/><c:overlay val="0"/></c:title>';
    }

    private static function textProperties(int $size): string
    {
        return '<c:txPr><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="' . $size . '"><a:solidFill><a:srgbClr val="' . ReportStyle::NAVY . '"/></a:solidFill><a:latin typeface="Arial"/></a:defRPr></a:pPr><a:endParaRPr lang="pt-BR"/></a:p></c:txPr>';
    }
}
