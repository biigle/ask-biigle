<?php

return [
    'llm_api_url' => env('BIIGLEBOT_LLM_API_URL', 'https://chat-ai.academiccloud.de/v1/chat/completions'),
    'llm_api_key' => env('BIIGLEBOT_LLM_API_KEY'),
    'llm_algorithm' => env('BIIGLEBOT_LLM_ALGORITHM'),
    'llm_inference_service' => env('BIIGLEBOT_LLM_INFERENCE_SERVICE', 'saia-openai-gateway'),
    'llm_arcana_id' => env('BIIGLEBOT_LLM_ARCANA_ID'),
    'llm_system_prompt' => env('BIIGLEBOT_LLM_SYSTEM_PROMPT', 'You are BIIGLEBot, an assistant for BIIGLE 2.0 users.'),
    'llm_enable_tools' => env('BIIGLEBOT_LLM_ENABLE_TOOLS', true),
    'llm_temperature' => env('BIIGLEBOT_LLM_TEMPERATURE', 0.2),
    'llm_top_p' => env('BIIGLEBOT_LLM_TOP_P', 1.0),
];
