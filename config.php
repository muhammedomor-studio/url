<?php
/**
 * Short-Link configuration.
 * No Render Environment Variables are required.
 * Edit this file once and add your GitHub token before deploying.
 */
return [
    'app_name' => 'Short-Link',
    'github_owner' => 'muhammedomor-studio',
    'github_repo' => 'url',
    'github_branch' => 'main',
    'github_data_path' => 'data/links.json',
    // Create a fine-grained GitHub token with Contents: Read and write access
    // for ONLY the repository above. Never share this token publicly.
    'github_token' => 'PASTE_YOUR_GITHUB_TOKEN_HERE',
    // Choose your own long random secret for API requests.
    'api_key' => 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET',
    // Choose a strong admin password.
    'admin_password' => 'CHANGE_THIS_ADMIN_PASSWORD',
    'default_expiry_days' => 0,
];
