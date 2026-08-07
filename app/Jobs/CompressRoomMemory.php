<?php

namespace App\Jobs;

use App\Models\Room;
use App\Services\AI\MemoryManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CompressRoomMemory implements ShouldQueue, \Illuminate\Contracts\Queue\ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    protected $room;

    public function __construct(Room $room)
    {
        $this->room = $room;
    }

    public function uniqueId(): string
    {
        return (string) $this->room->id;
    }

    public function handle(MemoryManager $memoryManager): void
    {
        $memoryManager->executeCompression($this->room);
    }
}
