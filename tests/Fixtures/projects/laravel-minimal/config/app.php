<?php

return [
    'name' => 'Acme',
    'env' => 'local',
    'debug' => true,
    'key' => 'base64:'.base64_encode(str_repeat('a', 32)),
    'cipher' => 'AES-256-CBC',
];
