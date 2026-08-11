<?php
require '/var/www/html/vendor/autoload.php';
$app = require_once '/var/www/html/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$markdownContent = \App\Models\Message::find(249)->content;

$cleanMarkdown = preg_replace('/<details class="ds-thinking">.*?<\/details>\s*/is', '', $markdownContent);
$cleanMarkdown = preg_replace('/\[THESIS_EVAL\][\s\S]*?\[\/THESIS_EVAL\]/', '', $cleanMarkdown);
$cleanMarkdown = trim($cleanMarkdown ?: $markdownContent);
echo "Clean length: " . strlen($cleanMarkdown) . "\n";

$html = \Illuminate\Support\Str::markdown($cleanMarkdown);
echo "HTML length: " . strlen($html) . "\n";

$html = str_replace('<table>', '<table border="1" style="width: 100%; border-collapse: collapse; margin-bottom: 1rem;">', $html);
$html = str_replace(['<th>', '<td>'], ['<th style="padding: 5px; font-weight: bold; background-color: #f3f4f6;">', '<td style="padding: 5px;">'], $html);

$phpWord = new \PhpOffice\PhpWord\PhpWord();
$section = $phpWord->addSection();
try {
    \PhpOffice\PhpWord\Shared\Html::addHtml($section, $html, false, false);
    $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
    $writer->save('/var/www/html/test_full.docx');
    echo "Saved docx!\n";
} catch (\Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
