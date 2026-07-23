<?php

namespace App\Jobs;

use App\Models\Room;
use App\Services\AI\MemoryManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CompressRoomMemory implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $room;

    public function __construct(Room $room)
    {
        $this->room = $room;
    }

    public function handle(MemoryManager $memoryManager): void
    {
        // Panggil logika kompresi
        $memoryManager->handleAutoCompression($this->room);
    }
}
