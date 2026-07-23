<?php

namespace App\Services\AI\Adapters;

interface BaseAdapter
{
    /**
     * Fungsi utama untuk mendapatkan respon dari AI.
     * * @param array $payload (berisi sistem_prompt, history, dll)
     * @return \Generator|array Mengembalikan Generator untuk streaming, atau array untuk respons sinkronus.
     */
    public function generateResponse(array $payload): \Generator|array;
}