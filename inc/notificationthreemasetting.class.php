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

use GlpiPlugin\Threema\Config as ThreemaConfig;
use GlpiPlugin\Threema\GatewayClient;
use GlpiPlugin\Threema\GatewayException;

/**
 * Configuration page of the Threema mode (Setup > Notifications > Threema).
 */
class PluginThreemaNotificationThreemaSetting extends NotificationSetting
{
    public static function getTypeName($nb = 0)
    {
        return __('Threema notifications configuration', 'threema');
    }

    public function getEnableLabel()
    {
        return __('Enable notifications via Threema', 'threema');
    }

    public static function getMode()
    {
        return PluginThreemaNotificationThreema::MODE;
    }

    public static function getIcon()
    {
        return 'fas fa-comment-dots';
    }

    public function defineTabs($options = [])
    {
        $ong = parent::defineTabs($options);
        $this->addStandardTab('Log', $ong, $options);
        return $ong;
    }

    protected function showFormConfig()
    {
        global $CFG_GLPI;

        $cfg  = ThreemaConfig::getAll();
        $h    = static function ($v): string {
            return htmlspecialchars((string)$v, ENT_QUOTES);
        };
        $row  = static function (string $label, string $field, string $help = ''): void {
            echo "<tr class='tab_bg_2'><td style='width:28%'>" . $label . "</td><td>" . $field;
            if ($help !== '') {
                echo "<div class='text-muted small mt-1'>" . $help . "</div>";
            }
            echo "</td></tr>";
        };

        echo "<form action='" . Toolbox::getItemTypeFormURL(__CLASS__) . "' method='post'>";
        echo "<div>";
        echo "<input type='hidden' name='id' value='1'>";
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_1'><th colspan='2'>" . __('Threema notifications', 'threema') . "</th></tr>";

        if (empty($CFG_GLPI['notifications_threema'])) {
            echo "<tr class='tab_bg_2'><td colspan='2'>" . __('Threema notifications are disabled.', 'threema')
                . " <a href='{$CFG_GLPI['root_doc']}/front/setup.notification.php'>" . __('See configuration') . "</a></td></tr>";
        }

        // --- Gateway account -------------------------------------------------
        echo "<tr class='tab_bg_1'><th colspan='2'>" . __('Threema Gateway account', 'threema') . "</th></tr>";

        $row(
            __('Gateway ID', 'threema'),
            "<input type='text' name='gateway_id' value='" . $h($cfg['gateway_id']) . "' maxlength='8' size='12' "
                . "style='text-transform:uppercase' placeholder='*MYAPIID'>",
            __('Your 8-character API identity, usually starting with *.', 'threema')
        );
        $row(
            __('API secret', 'threema'),
            "<input type='password' name='api_secret' value='' size='30' autocomplete='new-password' placeholder='"
                . ($cfg['api_secret'] !== '' ? '********' : '') . "'>",
            __('Leave empty to keep the current secret.', 'threema')
        );
        ob_start();
        Dropdown::showFromArray('mode', [
            GatewayClient::MODE_E2E   => __('End-to-end encrypted (recommended)', 'threema'),
            GatewayClient::MODE_BASIC => __('Basic (server-side encryption)', 'threema'),
        ], ['value' => $cfg['mode'], 'display' => true]);
        $row(
            __('Mode', 'threema'),
            ob_get_clean(),
            __('Must match how your Gateway ID was created. In end-to-end mode Threema never sees your private key.', 'threema')
        );

        // --- Key (E2E) -------------------------------------------------------
        $pub_html = '';
        if ($cfg['private_key'] !== '') {
            try {
                $pub_html = "<div class='mt-1'>" . __('Matching public key:', 'threema')
                    . " <code>" . $h(GatewayClient::derivePublicKey($cfg['private_key'])) . "</code></div>";
            } catch (GatewayException $e) {
                $pub_html = "<div class='mt-1 text-danger'>" . __('Stored private key is invalid.', 'threema') . "</div>";
            }
        }
        $row(
            __('Private key (end-to-end mode)', 'threema'),
            "<input type='password' name='private_key' value='' size='70' autocomplete='new-password' placeholder='"
                . ($cfg['private_key'] !== '' ? '************************' : 'private:0123…') . "'>" . $pub_html,
            __('Content of privateKey.txt generated with threema-msgapi-tool -g. Stored encrypted. Keep your own backups: if it is lost, the Gateway ID can never be used again. Leave empty to keep the current key.', 'threema')
        );

        // --- Delivery --------------------------------------------------------
        echo "<tr class='tab_bg_1'><th colspan='2'>" . __('Delivery', 'threema') . "</th></tr>";
        $row(
            __('Maximum message length (bytes)', 'threema'),
            "<input type='number' name='max_length' value='" . (int)$cfg['max_length'] . "' min='200' max='7000' step='100'>",
            __('Longer notifications are truncated. Hard limits: 3500 bytes (basic) / about 7000 bytes (end-to-end).', 'threema')
        );
        $row(
            __('Retries on failure', 'threema'),
            "<input type='number' name='max_retries' value='" . (int)$cfg['max_retries'] . "' min='0' max='20'> "
                . __('delay (minutes)', 'threema') . " <input type='number' name='retry_delay' value='" . (int)$cfg['retry_delay'] . "' min='1' max='1440'>"
        );
        $row(
            __('Administrator Threema ID', 'threema'),
            "<input type='text' name='admin_threema_id' value='" . $h($cfg['admin_threema_id']) . "' maxlength='8' size='12' style='text-transform:uppercase'>",
            __('Recipient of the "Administrator" notification target and of the test message.', 'threema')
        );
        ob_start();
        Dropdown::showYesNo('auto_lookup', $cfg['auto_lookup']);
        $row(
            __('Find users\' Threema IDs automatically', 'threema'),
            ob_get_clean(),
            __('Enables the "SyncIds" automatic action, which looks up users without an ID by hashed e-mail / mobile number.', 'threema')
        );

        // --- Advanced --------------------------------------------------------
        echo "<tr class='tab_bg_1'><th colspan='2'>" . __('Advanced', 'threema') . "</th></tr>";
        $row(
            __('API URL', 'threema'),
            "<input type='text' name='base_url' value='" . $h($cfg['base_url']) . "' size='40'> "
                . __('timeout (s)', 'threema') . " <input type='number' name='timeout' value='" . (int)$cfg['timeout'] . "' min='1' max='60'>",
            __('The GLPI proxy settings are used if configured.', 'threema')
        );

        $options = [
            'candel'     => false,
            'addbuttons' => [
                'test_threema_connection' => __('Save and test connection', 'threema'),
                'test_threema_send'       => __('Save and send a test message to the administrator', 'threema'),
            ],
        ];
        $this->showFormButtons($options);
    }
}
