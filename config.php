<?php
/**
 * Short-Link configuration bridge.
 * Reads the existing Render Environment Variables.
 * No secrets are stored in this file and no new variables are required.
 */
return [
    'app_name' => 'Short-Link',
    'github_owner' => getenv('GH_OWNER') ?: '',
    'github_repo' => getenv('GH_REPO') ?: '',
    'github_branch' => getenv('GH_BRANCH') ?: 'main',
    'github_data_path' => getenv('GH_DATA_PATH') ?: 'data/links.json',
    'github_token' => getenv('GH_TOKEN') ?: '',
    'api_key' => getenv('SHORTENER_API_KEY') ?: '',
    'admin_password' => getenv('ADMIN_PASSWORD') ?: (getenv('SHORTENER_API_KEY') ?: ''),
    'app_base_url' => rtrim((string)(getenv('APP_BASE_URL') ?: ''), '/'),
    'default_expiry_days' => 0,
];
