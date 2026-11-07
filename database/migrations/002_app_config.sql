-- Runtime configuration for the dynamic app gate.
-- Additive only: new setting rows, no renamed or dropped columns, so APKs that
-- predate this migration keep working.
INSERT INTO app_settings (setting_key, setting_value, description) VALUES
    ('min_supported_version_code', '0', 'Android versionCode below this is refused by the API until the app is updated'),
    ('min_recommended_version_code', '1', 'Android versionCode below this shows the update prompt in the app'),
    ('latest_app_version_name', '1.0.0', 'Version label shown on the update prompt'),
    ('update_message', 'A newer version of MajiLink is available. Update to keep ordering.', 'Body text of the update prompt'),
    ('update_url', '', 'Where the app sends users to get the new APK or Play listing'),
    ('force_update', '0', 'Turn the update prompt into a wall for versions below the recommended code'),
    ('maintenance_mode', '0', 'Refuse all app API traffic and show the maintenance message'),
    ('maintenance_message', 'MajiLink is taking a short break. Try again shortly.', 'Text shown on the maintenance screen'),
    ('allow_endpoint_override', '0', 'Let any build edit its own server address from the Server screen'),
    ('api_base_url', '', 'Absolute base URL advertised to apps, for example https://api.majilink.co.ke/api/v1/'),
    ('feature_flags', '', 'Comma separated names of enabled features, served to apps as a flag map'),
    ('currency', 'KES', 'Currency label the app prints next to amounts')
ON DUPLICATE KEY UPDATE description = VALUES(description);
