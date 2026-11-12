<?php

return [
    'push_enabled' => (bool) env('PROCURA_CHAT_PUSH_ENABLED', false),
    'expo_access_token' => env('EXPO_ACCESS_TOKEN'),
    'token_days' => 30,
];
