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

        // Remove DeepSeek thinking block if present
        $cleanMarkdown = preg_replace('/<details class="ds-thinking">.*?<\/details>\s*/is', '', $markdownContent);
        // Clean thesis evaluation markers if present
        $cleanMarkdown = preg_replace('/\[THESIS_EVAL\][\s\S]*?\[\/THESIS_EVAL\]/g', '', $cleanMarkdown);
        $cleanMarkdown = trim($cleanMarkdown ?: $markdownContent);

        // Convert Markdown to HTML natively using Laravel's markdown parser
        $html = \Illuminate\Support\Str::markdown($cleanMarkdown);

        // Wrap in styled HTML for optimal Google Docs import
        $styledHtml = "
        <html>
            <head>
                <style>
                    body { font-family: 'Times New Roman', Times, serif; font-size: 12pt; line-height: 1.5; color: #000000; text-align: justify; }
                    table { border-collapse: collapse; width: 100%; margin-bottom: 1rem; font-family: sans-serif; }
                    th, td { border: 1px solid #d1d5db; padding: 8px 12px; color: #000000; }
                    th { background-color: #f3f4f6; font-weight: bold; text-align: left; }
                    h1, h2, h3 { color: #000000; font-family: 'Times New Roman', Times, serif; }
                    h1 { text-align: center; font-size: 14pt; }
                    h2 { font-size: 12pt; }
                    h3 { font-size: 12pt; font-style: italic; }
                    code { font-family: 'Courier New', Courier, monospace; background-color: #f3f4f6; padding: 2px 4px; border-radius: 4px; }
                    pre { background-color: #f3f4f6; padding: 10px; border-radius: 4px; overflow-x: auto; }
                    blockquote { border-left: 4px solid #d1d5db; margin-left: 0; padding-left: 1rem; color: #4b5563; font-style: italic; }
                </style>
            </head>
            <body>
                {$html}
            </body>
        </html>
        ";

        // Upload to Google Drive and convert to Google Docs natively
        $fileMetadata = new \Google\Service\Drive\DriveFile([
            'name' => $title ?: 'Draf Skripsi - AI Chat Assistant',
            'mimeType' => 'application/vnd.google-apps.document' // Tells Drive to convert to GDocs
        ]);

        $file = $driveService->files->create($fileMetadata, [
            'data' => $styledHtml,
            'mimeType' => 'text/html',
            'uploadType' => 'multipart',
            'fields' => 'id, webViewLink'
        ]);

        return $file->webViewLink ?: "https://docs.google.com/document/d/{$file->id}/edit";
    }
}
