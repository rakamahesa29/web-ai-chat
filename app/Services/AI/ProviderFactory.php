<?php

namespace App\Services\AI;

use App\Services\AI\Adapters\OllamaAdapter;
use App\Services\AI\Adapters\OllamaCloudAdapter;
use App\Services\AI\Adapters\DeepseekAdapter;

class ProviderFactory
{
    /**
     * Menghasilkan instance adapter yang tepat berdasarkan nama model.
     */
    public static function make(string $modelName, array $options = []): object
    {
        $config = ConfigurationManager::getConfiguration($modelName);

        return match ($config->provider) {
            'ollama'       => new OllamaAdapter($config->model_name_api),
            'ollama_cloud' => new OllamaCloudAdapter($config->model_name_api),
            'deepseek'     => new DeepseekAdapter(
                $config->api_key,
                $config->model_name_api,
                $options['deepseek_pro'] ?? false,
                $options['query_type'] ?? null,
                ($options['chat_mode'] ?? '') === 'agent',
                $options['response_format'] ?? null
            ),
            default => new OllamaAdapter($config->model_name_api),
        };
    }
}