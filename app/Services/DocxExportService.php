<?php

namespace App\Services;

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Language;

class DocxExportService
{
    /**
     * Generate a .docx file from Markdown content.
     *
     * @param string $markdownContent
     * @param string $title
     * @return string Path to the generated temp .docx file
     */
    public function generateDocx(string $markdownContent, string $title = 'Dokumen Skripsi'): string
    {
        // Remove DeepSeek thinking block if present
        $markdownContent = preg_replace('/<details class="ds-thinking">.*?<\/details>\s*/is', '', $markdownContent);

        // Filter out invalid XML control characters
        $markdownContent = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $markdownContent);
        // Escape special characters to prevent invalid XML in the docx
        $markdownContent = htmlspecialchars($markdownContent, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $phpWord = new PhpWord();
        
        // Set document language to Indonesian
        $phpWord->getSettings()->setThemeFontLang(new Language('id-ID'));

        // Define Academic Styles
        $phpWord->addTitleStyle(1, [
            'name' => 'Times New Roman',
            'size' => 14,
            'bold' => true,
            'color' => '000000',
        ], [
            'alignment' => Jc::CENTER,
            'spaceBefore' => 240,
            'spaceAfter' => 120,
        ]);

        $phpWord->addTitleStyle(2, [
            'name' => 'Times New Roman',
            'size' => 12,
            'bold' => true,
            'color' => '000000',
        ], [
            'spaceBefore' => 180,
            'spaceAfter' => 60,
        ]);

        $phpWord->addTitleStyle(3, [
            'name' => 'Times New Roman',
            'size' => 12,
            'bold' => true,
            'italic' => true,
            'color' => '000000',
        ], [
            'spaceBefore' => 120,
            'spaceAfter' => 60,
        ]);

        // Add Section with standard margins (Top: 4cm, Left: 4cm, Right: 3cm, Bottom: 3cm in twips)
        // 1 cm = 567 twips -> Top: 2268, Left: 2268, Right: 1701, Bottom: 1701
        $section = $phpWord->addSection([
            'marginTop' => 2268,
            'marginLeft' => 2268,
            'marginRight' => 1701,
            'marginBottom' => 1701,
        ]);

        // Base font and paragraph style
        $fontStyle = [
            'name' => 'Times New Roman',
            'size' => 12,
            'color' => '000000',
        ];

        $paragraphStyle = [
            'lineSpacing' => 360, // 1.5 lines
            'spaceAfter' => 120,  // 6pt space after
            'alignment' => Jc::BOTH, // Justified
        ];

        // Parse line by line
        $lines = explode("\n", $markdownContent);
        $inCodeBlock = false;
        $inTable = false;
        $tableData = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Handle code block toggles
            if (str_starts_with($trimmed, '```')) {
                $inCodeBlock = !$inCodeBlock;
                continue;
            }

            if ($inCodeBlock) {
                $section->addText($line, ['name' => 'Courier New', 'size' => 10, 'color' => '333333'], ['spaceAfter' => 0]);
                continue;
            }

            // Skip horizontal rules
            if ($trimmed === '---' || $trimmed === '***' || $trimmed === '___') {
                $section->addTextBreak(1, ['size' => 4]); // Small gap instead of printing dashes
                continue;
            }

            // Handle Tables
            if (str_contains($trimmed, '|')) {
                // Ignore markdown alignment lines like |---|---|
                if (preg_match('/^\|?\s*:?-+:?\s*(\|?\s*:?-+:?\s*)+\|?$/', $trimmed)) {
                    continue;
                }

                $cells = array_map('trim', explode('|', trim($trimmed, '|')));
                $tableData[] = $cells;
                $inTable = true;
                continue;
            } else if ($inTable && !empty($tableData)) {
                // Render accumulated table
                $this->renderTable($section, $tableData);
                $tableData = [];
                $inTable = false;
            }

            if (empty($trimmed)) {
                continue;
            }

            // Headings
            if (str_starts_with($trimmed, '# ')) {
                $text = trim(substr($trimmed, 2));
                $section->addTitle($this->cleanMarkdownInline($text), 1);
            } elseif (str_starts_with($trimmed, '## ')) {
                $text = trim(substr($trimmed, 3));
                $section->addTitle($this->cleanMarkdownInline($text), 2);
            } elseif (str_starts_with($trimmed, '### ')) {
                $text = trim(substr($trimmed, 4));
                $section->addTitle($this->cleanMarkdownInline($text), 3);
            } elseif (str_starts_with($trimmed, '- ') || str_starts_with($trimmed, '* ')) {
                $text = trim(substr($trimmed, 2));
                $textRun = $section->addListItemRun(0, 'bullet', $paragraphStyle);
                $this->addFormattedTextRun($textRun, $text, $fontStyle);
            } elseif (preg_match('/^\d+\.\s+(.*)/', $trimmed, $matches)) {
                $textRun = $section->addListItemRun(0, 'number', $paragraphStyle);
                $this->addFormattedTextRun($textRun, $matches[1], $fontStyle);
            } elseif (str_starts_with($trimmed, '> ')) {
                $text = trim(substr($trimmed, 2));
                $quoteStyle = array_merge($fontStyle, ['italic' => true, 'color' => '555555']);
                $quotePara = array_merge($paragraphStyle, ['leftIndent' => 720]); // Indented quote
                $textRun = $section->addTextRun($quotePara);
                $this->addFormattedTextRun($textRun, $text, $quoteStyle);
            } else {
                // Regular Paragraph
                $textRun = $section->addTextRun($paragraphStyle);
                $this->addFormattedTextRun($textRun, $trimmed, $fontStyle);
            }
        }

        // Render remaining table if document ends on a table
        if ($inTable && !empty($tableData)) {
            $this->renderTable($section, $tableData);
        }

        // Save to temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'skripsi_docx_') . '.docx';
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tempFile);

        return $tempFile;
    }

    /**
     * Helper to render table in PhpWord
     */
    private function renderTable($section, array $rows): void
    {
        $tableStyle = [
            'borderSize' => 6,
            'borderColor' => '000000',
            'cellMargin' => 100,
            'alignment' => Jc::CENTER,
        ];
        $table = $section->addTable($tableStyle);

        foreach ($rows as $rowIndex => $rowCells) {
            $table->addRow();
            $isHeader = ($rowIndex === 0);

            foreach ($rowCells as $cellText) {
                $cellStyle = [
                    'valign' => 'center',
                    'bgColor' => $isHeader ? 'F2F4F7' : 'FFFFFF',
                ];
                $cell = $table->addCell(2500, $cellStyle);
                
                $textStyle = [
                    'name' => 'Times New Roman',
                    'size' => 11,
                    'bold' => $isHeader,
                    'color' => '000000',
                ];
                $paraStyle = [
                    'alignment' => $isHeader ? Jc::CENTER : Jc::LEFT,
                    'spaceAfter' => 0,
                    'lineSpacing' => 240, // 1.0 line for table cells
                ];

                $textRun = $cell->addTextRun($paraStyle);
                $this->addFormattedTextRun($textRun, $cellText, $textStyle);
            }
        }

        $section->addTextBreak(1);
    }

    /**
     * Parses inline markdown (**bold**, *italic*, `code`) into PHPWord TextRun
     */
    private function addFormattedTextRun($textRun, string $text, array $baseStyle): void
    {
        // Split by bold (**text**)
        $parts = preg_split('/(\*\*.*?\*\*|\*.*?\*|`.*?`)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach ($parts as $part) {
            if (empty($part)) continue;

            $style = $baseStyle;

            if (str_starts_with($part, '**') && str_ends_with($part, '**')) {
                $content = substr($part, 2, -2);
                $style['bold'] = true;
                $textRun->addText($content, $style);
            } elseif (str_starts_with($part, '*') && str_ends_with($part, '*')) {
                $content = substr($part, 1, -1);
                $style['italic'] = true;
                $textRun->addText($content, $style);
            } elseif (str_starts_with($part, '`') && str_ends_with($part, '`')) {
                $content = substr($part, 1, -1);
                $style['name'] = 'Courier New';
                $style['size'] = 10.5;
                $textRun->addText($content, $style);
            } else {
                $textRun->addText($part, $style);
            }
        }
    }

    /**
     * Clean inline markdown for plain titles
     */
    private function cleanMarkdownInline(string $text): string
    {
        return preg_replace('/[\*\*_`]/', '', $text);
    }
}
