<?php

namespace App\Jobs;

use App\Models\ThesisEvaluation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExtractThesisEvaluation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 30;

    protected int $roomId;
    protected ?int $messageId;
    protected string $rawJson;

    public function __construct(int $roomId, ?int $messageId, string $rawJson)
    {
        $this->roomId = $roomId;
        $this->messageId = $messageId;
        $this->rawJson = $rawJson;
    }

    public function handle(): void
    {
        try {
            $data = json_decode($this->rawJson, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning("ThesisEval: Invalid JSON from AI", [
                    'room_id' => $this->roomId,
                    'error' => json_last_error_msg(),
                    'raw' => substr($this->rawJson, 0, 500),
                ]);
                return;
            }

            $validTypes = ['benang_merah', 'defense_readiness', 'chapter_review'];
            $type = $data['type'] ?? 'chapter_review';
            if (!in_array($type, $validTypes)) {
                $type = 'chapter_review';
            }

            $overallScore = floatval($data['overall_score'] ?? 0);
            $overallScore = max(0, min(10, $overallScore));

            $chapterScores = $data['chapter_scores'] ?? [];
            foreach ($chapterScores as $key => &$chapter) {
                if (is_array($chapter) && isset($chapter['score'])) {
                    $chapter['score'] = max(0, min(10, floatval($chapter['score'])));
                }
            }
            unset($chapter);

            ThesisEvaluation::create([
                'room_id'         => $this->roomId,
                'message_id'      => $this->messageId,
                'evaluation_type' => $type,
                'overall_score'   => $overallScore,
                'chapter_scores'  => $chapterScores,
                'strengths'       => $data['strengths'] ?? [],
                'weaknesses'      => $data['weaknesses'] ?? [],
                'recommendations' => $data['recommendations'] ?? null,
                'raw_evaluation'  => $this->rawJson,
            ]);

            Log::info("ThesisEval: Saved evaluation", [
                'room_id' => $this->roomId,
                'type' => $type,
                'overall_score' => $overallScore,
            ]);

        } catch (\Exception $e) {
            Log::error("ThesisEval: Failed to save", [
                'room_id' => $this->roomId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
