<?php

/**
 * -------------------------------------------------------------------------
 * Threema plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

use GlpiPlugin\Threema\UserId;

define('PLUGIN_THREEMA_VERSION', '1.0.0');
// Minimal and maximal (exclusive) GLPI versions: GLPI 11 and 12
define('PLUGIN_THREEMA_MIN_GLPI', '11.0.0');
define('PLUGIN_THREEMA_MAX_GLPI', '12.0.0');

/**
 * Init the hooks of the plugin - REQUIRED
 */
function plugin_init_threema()
{
    global $PLUGIN_HOOKS;

    if (!Plugin::isPluginActive('threema')) {
        return;
    }

    // New notification mode "threema" -> classes PluginThreemaNotification*Threema* in inc/
    Notification_NotificationTemplate::registerMode(
        'threema',
        __('Threema', 'threema'),
        'threema'
    );

    // Encrypt these configuration values with the GLPI key
    $PLUGIN_HOOKS['secured_configs']['threema'] = ['api_secret', 'private_key'];

    // "Threema" tab on the user form and on the personal preferences page
    Plugin::registerClass(UserId::class, ['addtabon' => ['User', 'Preference']]);
    $PLUGIN_HOOKS['item_purge']['threema'] = ['User' => [UserId::class, 'cleanForUser']];

    // Configuration entry in Setup > Plugins
    $PLUGIN_HOOKS['config_page']['threema'] = 'front/notificationthreemasetting.form.php';
}

function plugin_version_threema()
{
    return [
        'name'         => 'Threema',
        'version'      => PLUGIN_THREEMA_VERSION,
        'author'       => 'd-factos SA - G. Lemaire',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/d-factos-SA/Threema',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_THREEMA_MIN_GLPI,
                'max' => PLUGIN_THREEMA_MAX_GLPI,
            ],
            'php'  => [
                'min' => '8.2',
                'exts' => [
                    'curl'     => ['required' => true],
                    'mbstring' => ['required' => true],
                    'sodium'   => ['required' => false, 'description' => 'Required for end-to-end encrypted mode'],
                ],
            ],
        ],
    ];
}

/** Prerequisites check (also covered by the 'requirements' above) */
function plugin_threema_check_prerequisites()
{
    if (version_compare(GLPI_VERSION, PLUGIN_THREEMA_MIN_GLPI, '<') || version_compare(GLPI_VERSION, PLUGIN_THREEMA_MAX_GLPI, '>=')) {
        echo sprintf('This plugin requires GLPI >= %s and < %s', PLUGIN_THREEMA_MIN_GLPI, PLUGIN_THREEMA_MAX_GLPI);
        return false;
    }
    return true;
}

function plugin_threema_check_config($verbose = false)
{
    return true;
}
