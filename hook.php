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
use GlpiPlugin\Threema\UserId;

/**
 * Plugin install process
 */
function plugin_threema_install()
{
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();

    if (!$DB->tableExists('glpi_plugin_threema_userids')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_threema_userids` (
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `users_id` int {$sign} NOT NULL DEFAULT '0',
                `threemaid` varchar(8) DEFAULT NULL COMMENT 'NULL = automatic lookup found nothing',
                `source` varchar(20) NOT NULL DEFAULT 'manual',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `users_id` (`users_id`),
                KEY `threemaid` (`threemaid`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC"
        );
    }

    if (!$DB->tableExists('glpi_plugin_threema_pubkeys')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_threema_pubkeys` (
                `threemaid` varchar(8) NOT NULL,
                `publickey` char(64) NOT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`threemaid`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC"
        );
    }

    // Default settings (never overwrite existing ones) + the "mode enabled" flag read by GLPI core
    $existing = Config::getConfigurationValues(ThreemaConfig::CONTEXT);
    $missing  = array_diff_key(ThreemaConfig::defaults(), $existing);
    if ($missing) {
        Config::setConfigurationValues(ThreemaConfig::CONTEXT, $missing);
    }
    if (Config::getConfigurationValue('core', 'notifications_threema') === null) {
        Config::setConfigurationValues('core', ['notifications_threema' => 0]);
    }

    // Automatic action (disabled until "Find users' Threema IDs automatically" is enabled and the task activated)
    CronTask::register(
        UserId::class,
        'SyncIds',
        DAY_TIMESTAMP,
        [
            'comment' => __('Look up the Threema ID of users who do not have one yet', 'threema'),
            'mode'    => CronTask::MODE_EXTERNAL,
            'state'   => CronTask::STATE_DISABLE,
            'param'   => 50,
        ]
    );

    return true;
}

/**
 * Plugin uninstall process
 */
function plugin_threema_uninstall()
{
    global $DB;

    foreach (['glpi_plugin_threema_userids', 'glpi_plugin_threema_pubkeys'] as $table) {
        $DB->doQuery("DROP TABLE IF EXISTS `{$table}`");
    }

    // Notifications that used the Threema mode, and anything still queued
    $DB->delete(Notification_NotificationTemplate::getTable(), ['mode' => 'threema']);
    $DB->delete(QueuedNotification::getTable(), ['mode' => 'threema']);

    $config = new Config();
    $config->deleteByCriteria(['context' => ThreemaConfig::CONTEXT]);
    $config->deleteByCriteria(['context' => 'core', 'name' => 'notifications_threema']);

    CronTask::unregister('threema');

    return true;
}
