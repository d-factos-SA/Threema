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

use Glpi\Event;
use GlpiPlugin\Threema\Config as ThreemaConfig;

Session::checkRight('config', UPDATE);

$saved = true;
if (isset($_POST['update']) || isset($_POST['test_threema_connection']) || isset($_POST['test_threema_send'])) {
    $errors = ThreemaConfig::save($_POST);
    foreach ($errors as $error) {
        Session::addMessageAfterRedirect($error, false, ERROR);
    }
    $saved = !$errors;
    if ($saved) {
        Event::log(0, 'system', 3, 'setup', sprintf(
            __('%s edited the Threema notifications configuration', 'threema'),
            $_SESSION['glpiname'] ?? __('Unknown')
        ));
    }

    if ($saved && isset($_POST['test_threema_connection'])) {
        ThreemaConfig::testConnection();
    } elseif ($saved && isset($_POST['test_threema_send'])) {
        ThreemaConfig::sendTest();
    } elseif ($saved) {
        Session::addMessageAfterRedirect(__('Item successfully updated'));
    }
    Html::back();
}

$menus     = ['config', 'notification', PluginThreemaNotificationThreemaSetting::class];
$config_id = Config::getConfigIDForContext('core');
PluginThreemaNotificationThreemaSetting::displayFullPageForItem($config_id, $menus);
