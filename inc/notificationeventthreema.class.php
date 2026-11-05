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
use GlpiPlugin\Threema\Message;
use GlpiPlugin\Threema\UserId;

/**
 * Event side of the Threema mode: who is notified, and how queued messages are delivered.
 */
class PluginThreemaNotificationEventThreema extends NotificationEventAbstract
{
    public static function getTargetFieldName()
    {
        return 'threema_id';
    }

    /**
     * Resolve the Threema ID of a recipient: explicit value, or the ID stored on the GLPI user.
     * Recipients without a valid ID get a null target and are silently skipped.
     */
    public static function getTargetField(&$data)
    {
        $field = self::getTargetFieldName();

        if (empty($data[$field]) && !empty($data['users_id'])) {
            $data[$field] = UserId::getIdForUser((int)$data['users_id']);
        }

        $id = GatewayClient::normalizeId((string)($data[$field] ?? ''));
        $data[$field] = GatewayClient::isValidId($id) ? $id : null;

        return $field;
    }

    public static function canCron()
    {
        return true;
    }

    /** "Administrator" target: the Threema ID set in the plugin configuration. */
    public static function getAdminData()
    {
        global $CFG_GLPI;

        $id = GatewayClient::normalizeId((string)ThreemaConfig::getAll()['admin_threema_id']);
        if (!GatewayClient::isValidId($id)) {
            return [];
        }
        return [
            'threema_id' => $id,
            'language'   => $CFG_GLPI['language'],
        ];
    }

    /** Entities have no Threema ID of their own. */
    public static function getEntityAdminsData($entity)
    {
        return [];
    }

    /**
     * Deliver queued messages (called by the "queuednotification" cron, or "send now").
     *
     * @param array $data rows of glpi_queuednotifications
     *
     * @return int|false number of messages sent
     */
    public static function send(array $data)
    {
        $processed = 0;

        try {
            $client = ThreemaConfig::getClient();
        } catch (GatewayException $e) {
            Toolbox::logInFile('threema-error', 'Cannot send queued Threema messages: ' . $e->getMessage() . "\n");
            Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), true, ERROR);
            return false;
        }
        $cfg = ThreemaConfig::getAll();

        foreach ($data as $row) {
            $current = new QueuedNotification();
            $current->getFromResultSet($row);

            $text = Message::build(
                (string)$current->fields['name'],
                (string)$current->fields['body_text'],
                (int)$cfg['max_length']
            );

            try {
                $message_id = $client->sendText((string)$current->fields['recipient'], $text);
            } catch (GatewayException $e) {
                self::handleFailedSend($current, $e, $cfg);
                continue;
            }

            Toolbox::logInFile(
                'threema',
                sprintf(
                    __('%1$s: %2$s', 'threema'),
                    sprintf(__('A Threema message was sent to %1$s (message ID %2$s)', 'threema'), $current->fields['recipient'], $message_id),
                    $current->fields['name'] . "\n"
                )
            );
            $processed++;
            $current->update([
                'id'        => $current->fields['id'],
                'sent_time' => $_SESSION['glpi_currenttime'],
            ]);
            $current->delete(['id' => $current->fields['id']]);
        }

        return $processed;
    }

    private static function handleFailedSend(QueuedNotification $notification, GatewayException $e, array $cfg): void
    {
        $tries   = (int)$notification->fields['sent_try'] + 1;
        $retries = (int)$cfg['max_retries'] - $tries + 1; // retries left after this failure
        $give_up = $e->isPermanent() || $retries <= 0;

        Toolbox::logInFile(
            'threema-error',
            sprintf(
                'Threema message to %1$s failed (%2$s): %3$s | %4$s' . "\n",
                $notification->fields['recipient'],
                $give_up ? 'giving up' : $retries . ' retries remaining',
                $e->getMessage(),
                $notification->fields['name']
            )
        );

        if ($give_up) {
            $notification->delete(['id' => $notification->fields['id']]);
        }

        $input = [
            'id'       => $notification->fields['id'],
            'sent_try' => $tries,
        ];
        if (!$give_up && (int)$cfg['retry_delay'] > 0) {
            $input['send_time'] = date('Y-m-d H:i:s', strtotime('+' . (int)$cfg['retry_delay'] . ' minutes'));
        }
        $notification->update($input);
    }
}
