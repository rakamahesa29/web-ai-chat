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
        $driveService = new Drive($client);

        // Generate DOCX file locally using the robust DocxExportService
        $docxService = new \App\Services\DocxExportService();
        $docxPath = $docxService->generateDocx($markdownContent, $title);
        $docxContent = file_get_contents($docxPath);

        // Upload to Google Drive and convert to Google Docs natively
        $fileMetadata = new \Google\Service\Drive\DriveFile([
            'name' => $title ?: 'Draf Skripsi - AI Chat Assistant',
            'mimeType' => 'application/vnd.google-apps.document' // Tells Drive to convert to GDocs
        ]);

        $file = $driveService->files->create($fileMetadata, [
            'data' => $docxContent,
            'mimeType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'uploadType' => 'multipart',
            'fields' => 'id, webViewLink'
        ]);

        // Clean up temporary docx file
        if (file_exists($docxPath)) {
            unlink($docxPath);
        }

        return $file->webViewLink ?: "https://docs.google.com/document/d/{$file->id}/edit";
    }
}
