<?php

namespace App\Services\Google;

use App\Models\User;
use Google\Service\Sheets;
use Google\Service\Sheets\Spreadsheet;
use Google\Service\Sheets\ValueRange;
use Google\Service\Drive;

class GoogleSheetsService
{
    protected GoogleAuthService $authService;

    public function __construct(GoogleAuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Create a Google Sheet from Markdown table data or text content.
     *
     * @param User $user
     * @param string $title
     * @param string $markdownContent
     * @return string Google Sheet webViewLink URL
     */
    public function createSheetFromMarkdown(User $user, string $title, string $markdownContent): string
    {
        $client = $this->authService->getClient($user);
        $sheetsService = new Sheets($client);
        $driveService = new Drive($client);

        // 1. Extract tabular data from Markdown
        $rows = $this->parseMarkdownTable($markdownContent);

        if (empty($rows)) {
            // Fallback: Line-by-line single column if no markdown table found
            $lines = explode("\n", trim($markdownContent));
            foreach ($lines as $line) {
                if (!empty(trim($line))) {
                    $rows[] = [trim($line)];
                }
            }
        }

        // 2. Create blank Spreadsheet
        $spreadsheet = new Spreadsheet([
            'properties' => [
                'title' => $title ?: 'Matriks Data Skripsi - AI Assistant'
            ]
        ]);
        $spreadsheet = $sheetsService->spreadsheets->create($spreadsheet);
        $spreadsheetId = $spreadsheet->spreadsheetId;

        // 3. Write data values into Sheet1!A1
        $body = new ValueRange([
            'values' => $rows
        ]);
        $params = [
            'valueInputOption' => 'USER_ENTERED'
        ];

        $sheetsService->spreadsheets_values->update(
            $spreadsheetId,
            'Sheet1!A1',
            $body,
            $params
        );

        // 4. Retrieve Drive webViewLink URL
        $driveFile = $driveService->files->get($spreadsheetId, ['fields' => 'webViewLink']);

        return $driveFile->webViewLink ?: "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/edit";
    }

    /**
     * Parse markdown tables into 2D Array
     */
    private function parseMarkdownTable(string $content): array
    {
        $rows = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $trimmed = trim($line);
            
            if (str_contains($trimmed, '|')) {
                // Ignore divider lines like |---|---|
                if (preg_match('/^\|?\s*:?-+:?\s*(\|?\s*:?-+:?\s*)+\|?$/', $trimmed)) {
                    continue;
                }

                $cells = array_map(function ($cell) {
                    return trim(preg_replace('/[\*\*_`]/', '', $cell));
                }, explode('|', trim($trimmed, '|')));

                if (!empty($cells)) {
                    $rows[] = $cells;
                }
            }
        }

        return $rows;
    }
}
