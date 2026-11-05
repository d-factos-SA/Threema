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
 * Notification mode "threema" (outgoing channel).
 *
 * GLPI resolves the classes of a plugin mode from its name:
 *   PluginThreemaNotificationThreema          (this class)
 *   PluginThreemaNotificationEventThreema     (targets + sending)
 *   PluginThreemaNotificationThreemaSetting   (configuration page)
 *
 * Like the e-mail mode, notifications are first queued in glpi_queuednotifications
 * and delivered by the "queuednotification" automatic action (cron).
 */
class PluginThreemaNotificationThreema implements NotificationInterface
{
    public const MODE = 'threema';

    public static function check($value, $options = [])
    {
        return GatewayClient::isValidId(GatewayClient::normalizeId((string)$value));
    }

    public static function testNotification()
    {
        return ThreemaConfig::sendTest();
    }

    /**
     * Called by GLPI for each recipient: put the message in the queue.
     */
    public function sendNotification($options = [])
    {
        $data = [
            'itemtype'                 => $options['_itemtype'],
            'items_id'                 => $options['_items_id'],
            'notificationtemplates_id' => $options['_notificationtemplates_id'],
            'entities_id'              => $options['_entities_id'],
            'event'                    => $options['event'] ?? null,
            'itemtype_trigger'         => $options['itemtype_trigger'] ?? null,
            'items_id_trigger'         => $options['items_id_trigger'] ?? 0,
            'sender'                   => $options['from'] ?? '',
            'sendername'               => $options['fromname'] ?? '',
            'name'                     => $options['subject'],
            'body_text'                => $options['content_text'],
            'recipient'                => $options['to'],
            'recipientname'            => $options['toname'] ?? '',
            'mode'                     => self::MODE,
        ];

        $queue = new QueuedNotification();
        if (!$queue->add($data)) {
            Session::addMessageAfterRedirect(__s('Error inserting Threema message to queue', 'threema'), true, ERROR);
            return false;
        }

        Toolbox::logInFile(
            'threema',
            sprintf(__('A Threema message to %1$s was added to queue: %2$s', 'threema'), $options['to'], $options['subject']) . "\n"
        );
        return true;
    }
}
