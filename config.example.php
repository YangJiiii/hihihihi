<?php

return [
    // Token lay tu BotFather, dang: 123456789:AA...
    'bot_token' => 'YOUR_BOT_TOKEN_HERE',

    // Tao bang: openssl rand -hex 32
    'webhook_secret' => 'YOUR_WEBHOOK_SECRET_HERE',

    // Cache HTML firmware trong bao nhieu giay. 900 = 15 phut.
    'cache_ttl_seconds' => 900,

    // ---- GitHub Actions decrypt IPA ----
    // Tao Personal Access Token o: https://github.com/settings/tokens (can quyen "workflow")
    'github_token' => 'ghp_xxxxxxxxxxxxxxxxxxxx',
    // Vi du: 'yangjiii/ipsw-bot'
    'github_repo' => 'your-username/your-repo',
];
