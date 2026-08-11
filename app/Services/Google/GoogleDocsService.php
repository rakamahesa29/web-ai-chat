<?php

namespace App\Services\Google;

use App\Models\User;
use Google\Service\Docs;
use Google\Service\Docs\BatchUpdateDocumentRequest;
use Google\Service\Docs\Document;
use Google\Service\Docs\Request as DocsRequest;
use Google\Service\Docs\InsertTextRequest;
use Google\Service\Docs\UpdateTextStyleRequest;
use Google\Service\Docs\UpdateParagraphStyleRequest;
use Google\Service\Docs\TextStyle;
use Google\Service\Docs\ParagraphStyle;
use Google\Service\Docs\Location;
use Google\Service\Docs\Range;
use Google\Service\Drive;

class GoogleDocsService
{
    protected GoogleAuthService $authService;

    public function __construct(GoogleAuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Create a formatted Google Doc from Markdown content.
     *
     * @param User $user
     * @param string $title
     * @param string $markdownContent
     * @return string Google Doc webViewLink URL
     */
    public function createDocumentFromMarkdown(User $user, string $title, string $markdownContent): string
    {
        $client = $this->authService->getClient($user);
        $docsService = new Docs($client);
        $driveService = new Drive($client);

        // 1. Create a blank Google Document
        $doc = new Document([
            'title' => $title ?: 'Draf Skripsi - AI Chat Assistant'
        ]);
        $doc = $docsService->documents->create($doc);
        $documentId = $doc->documentId;

        // Remove DeepSeek thinking block if present
        $cleanMarkdown = preg_replace('/<details class="ds-thinking">.*?<\/details>\s*/is', '', $markdownContent);
        // Clean thesis evaluation markers if present
        $cleanMarkdown = preg_replace('/\[THESIS_EVAL\][\s\S]*?\[\/THESIS_EVAL\]/g', '', $cleanMarkdown);
        $cleanMarkdown = trim($cleanMarkdown ?: $markdownContent);

        // Parse content into plain text and batch requests for Google Docs API
        $requests = [];
        $lines = explode("\n", $cleanMarkdown);
        $currentIndex = 1; // 1-based index in Google Docs API

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (empty($trimmed)) {
                $textToInsert = "\n";
                $requests[] = new DocsRequest([
                    'insertText' => new InsertTextRequest([
                        'location' => new Location(['index' => $currentIndex]),
                        'text' => $textToInsert
                    ])
                ]);
                $currentIndex += mb_strlen($textToInsert);
                continue;
            }

            // Headings vs Regular Text
            $isH1 = str_starts_with($trimmed, '# ');
            $isH2 = str_starts_with($trimmed, '## ');
            $isH3 = str_starts_with($trimmed, '### ');
            $isBullet = str_starts_with($trimmed, '- ') || str_starts_with($trimmed, '* ');

            $plainText = preg_replace('/^#+\s*|^[\-\*]\s*|^\d+\.\s*/', '', $trimmed);
            $plainText = preg_replace('/[\*\*_`]/', '', $plainText);
            
            if ($isBullet) {
                $plainText = "• " . $plainText;
            }
            
            $textToInsert = $plainText . "\n";
            $textLength = mb_strlen($textToInsert);
            $startIndex = $currentIndex;
            $endIndex = $currentIndex + $textLength - 1;

            // Insert Text
            $requests[] = new DocsRequest([
                'insertText' => new InsertTextRequest([
                    'location' => new Location(['index' => $currentIndex]),
                    'text' => $textToInsert
                ])
            ]);

            // Apply Heading & Font Styling
            $textStyle = new TextStyle(['weightedFontFamily' => ['fontFamily' => 'Times New Roman']]);
            $paragraphStyle = new ParagraphStyle(['lineSpacing' => 150]); // 1.5 line spacing

            if ($isH1) {
                $textStyle->setFontSize(['magnitude' => 14, 'unit' => 'PT']);
                $textStyle->setBold(true);
                $paragraphStyle->setNamedStyleType('HEADING_1');
                $paragraphStyle->setAlignment('CENTER');
            } elseif ($isH2) {
                $textStyle->setFontSize(['magnitude' => 12, 'unit' => 'PT']);
                $textStyle->setBold(true);
                $paragraphStyle->setNamedStyleType('HEADING_2');
            } elseif ($isH3) {
                $textStyle->setFontSize(['magnitude' => 12, 'unit' => 'PT']);
                $textStyle->setBold(true);
                $textStyle->setItalic(true);
                $paragraphStyle->setNamedStyleType('HEADING_3');
            } else {
                $textStyle->setFontSize(['magnitude' => 12, 'unit' => 'PT']);
                $paragraphStyle->setNamedStyleType('NORMAL_TEXT');
                $paragraphStyle->setAlignment('JUSTIFIED');
            }

            $requests[] = new DocsRequest([
                'updateTextStyle' => new UpdateTextStyleRequest([
                    'range' => new Range(['startIndex' => $startIndex, 'endIndex' => $endIndex]),
                    'textStyle' => $textStyle,
                    'fields' => 'bold,italic,fontSize,weightedFontFamily'
                ])
            ]);

            $requests[] = new DocsRequest([
                'updateParagraphStyle' => new UpdateParagraphStyleRequest([
                    'range' => new Range(['startIndex' => $startIndex, 'endIndex' => $endIndex]),
                    'paragraphStyle' => $paragraphStyle,
                    'fields' => 'namedStyleType,alignment,lineSpacing'
                ])
            ]);

            $currentIndex += $textLength;
        }

        // Execute batch updates
        if (!empty($requests)) {
            $batchRequest = new BatchUpdateDocumentRequest(['requests' => $requests]);
            $docsService->documents->batchUpdate($documentId, $batchRequest);
        }

        // Fetch file URL from Drive Service
        $driveFile = $driveService->files->get($documentId, ['fields' => 'webViewLink']);
        
        return $driveFile->webViewLink ?: "https://docs.google.com/document/d/{$documentId}/edit";
    }
}
