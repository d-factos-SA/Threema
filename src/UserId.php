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

namespace GlpiPlugin\Threema;

use CommonDBTM;
use CommonGLPI;
use CronTask;
use Html;
use Session;
use User;
use UserEmail;

/**
 * Threema ID of a GLPI user (one row per user).
 *
 * Shown as a "Threema" tab on the user form and on the personal preferences page.
 * `threemaid` is NULL when an automatic lookup found nothing (retried after 30 days).
 */
class UserId extends CommonDBTM
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_EMAIL  = 'lookup_email';
    public const SOURCE_PHONE  = 'lookup_phone';
    public const SOURCE_NONE   = 'none';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_threema_userids';
    }

    public static function getTypeName($nb = 0)
    {
        return __('Threema ID', 'threema');
    }

    // ------------------------------------------------------------------
    // Data access
    // ------------------------------------------------------------------

    /** @return string|null normalised Threema ID of the user, or null */
    public static function getIdForUser(int $users_id): ?string
    {
        $row = new self();
        if ($users_id > 0 && $row->getFromDBByCrit(['users_id' => $users_id]) && !empty($row->fields['threemaid'])) {
            return $row->fields['threemaid'];
        }
        return null;
    }

    public static function setIdForUser(int $users_id, ?string $threema_id, string $source = self::SOURCE_MANUAL): bool
    {
        $threema_id = $threema_id === null ? null : GatewayClient::normalizeId($threema_id);
        if ($threema_id !== null && !GatewayClient::isValidId($threema_id)) {
            return false;
        }
        $input = [
            'users_id'  => $users_id,
            'threemaid' => $threema_id,
            'source'    => $source,
            'date_mod'  => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ];
        $row = new self();
        if ($row->getFromDBByCrit(['users_id' => $users_id])) {
            $input['id'] = $row->getID();
            return (bool)$row->update($input);
        }
        $input['date_creation'] = $input['date_mod'];
        return (bool)$row->add($input);
    }

    public static function deleteForUser(int $users_id): bool
    {
        $row = new self();
        return $row->deleteByCriteria(['users_id' => $users_id], true);
    }

    /** Hook "item_purge" on User */
    public static function cleanForUser(CommonDBTM $item): void
    {
        self::deleteForUser((int)$item->getID());
    }

    // ------------------------------------------------------------------
    // Rights
    // ------------------------------------------------------------------

    /**
     * A user can edit their own ID. Editing someone else's requires BOTH the global
     * "Users" UPDATE right (User::canUpdate()) and the right on that very user in the
     * current entities (User::can($id, UPDATE)). canUpdateItem() alone only checks the
     * entity of the target and would let any logged-in user through.
     */
    public static function canEditFor(int $users_id): bool
    {
        if ($users_id <= 0) {
            return false;
        }
        if ($users_id === (int)Session::getLoginUserID()) {
            return true;
        }
        if (!User::canUpdate()) {
            return false;
        }
        $user = new User();
        return (bool)$user->can($users_id, UPDATE);
    }

    // ------------------------------------------------------------------
    // Automatic lookup (hashed e-mail / mobile number, nothing in clear leaves GLPI)
    // ------------------------------------------------------------------

    /**
     * Look up the Threema ID linked to the user's e-mail addresses, then mobile number,
     * and store the result.
     *
     * @return string|null the ID found
     * @throws GatewayException on gateway error (nothing is stored)
     */
    public static function lookupForUser(int $users_id, ?GatewayClient $client = null): ?string
    {
        $client = $client ?? Config::getClient();
        $found  = null;
        $source = self::SOURCE_NONE;

        foreach (array_unique(array_filter(UserEmail::getAllForUser($users_id))) as $email) {
            $found = $client->lookupEmail($email);
            if ($found !== null) {
                $source = self::SOURCE_EMAIL;
                break;
            }
        }

        if ($found === null) {
            $user = new User();
            $mobile = $user->getFromDB($users_id) ? trim((string)$user->fields['mobile']) : '';
            // only international numbers can be looked up reliably
            if ($mobile !== '' && preg_match('/^(\+|00)\d/', $mobile)) {
                $found = $client->lookupPhone($mobile);
                if ($found !== null) {
                    $source = self::SOURCE_PHONE;
                }
            }
        }

        self::setIdForUser($users_id, $found, $source);
        return $found;
    }

    public static function cronInfo($name)
    {
        return [
            'description' => __('Look up the Threema ID of users who do not have one yet (by e-mail / mobile number)', 'threema'),
            'parameter'   => __('Users per run', 'threema'),
        ];
    }

    /** CronTask "SyncIds" - disabled by default, requires the "auto lookup" setting. */
    public static function cronSyncIds(CronTask $task)
    {
        global $DB;

        $cfg = Config::getAll();
        if (empty($cfg['auto_lookup']) || !Config::isConfigured()) {
            return 0;
        }
        $limit  = max(1, (int)($task->fields['param'] ?: 50));
        $recent = date('Y-m-d H:i:s', strtotime('-30 days'));
        $users  = User::getTable();
        $ids    = self::getTable();

        $iterator = $DB->request([
            'SELECT'    => ["$users.id"],
            'FROM'      => $users,
            'LEFT JOIN' => [$ids => ['ON' => [$ids => 'users_id', $users => 'id']]],
            'WHERE'     => [
                "$users.is_active"  => 1,
                "$users.is_deleted" => 0,
                'OR' => [
                    "$ids.id" => null,
                    ['AND' => ["$ids.threemaid" => null, "$ids.date_mod" => ['<', $recent]]],
                ],
            ],
            'LIMIT'     => $limit,
        ]);

        $client = Config::getClient();
        $done = 0;
        foreach ($iterator as $row) {
            try {
                if (self::lookupForUser((int)$row['id'], $client) !== null) {
                    $task->addVolume(1);
                }
                $done++;
            } catch (GatewayException $e) {
                $task->log('Threema lookup failed: ' . $e->getMessage());
                if (!$e->isPermanent()) {
                    break; // gateway unavailable / rate limited: try again on next run
                }
            }
        }
        return $done > 0 ? 1 : 0;
    }

    // ------------------------------------------------------------------
    // UI: "Threema" tab on User and Preference
    // ------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (($item instanceof User && $item->getID() > 0) || $item instanceof \Preference) {
            return self::createTabEntry(__('Threema', 'threema'));
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        $users_id = $item instanceof User ? (int)$item->getID() : (int)Session::getLoginUserID();
        self::showForUser($users_id);
        return true;
    }

    public static function showForUser(int $users_id): void
    {
        $can_edit = self::canEditFor($users_id);
        $row      = new self();
        $has_row  = $users_id > 0 && $row->getFromDBByCrit(['users_id' => $users_id]);
        $current  = $has_row ? (string)$row->fields['threemaid'] : '';
        $source   = $has_row ? (string)$row->fields['source'] : '';

        $sources = [
            self::SOURCE_MANUAL => __('entered manually', 'threema'),
            self::SOURCE_EMAIL  => __('found from e-mail address', 'threema'),
            self::SOURCE_PHONE  => __('found from mobile number', 'threema'),
            self::SOURCE_NONE   => __('not found by automatic lookup', 'threema'),
        ];

        echo "<div class='center'>";
        echo "<form method='post' action='" . self::getFormURL() . "'>";
        echo Html::hidden('users_id', ['value' => $users_id]);
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_1'><th colspan='2'>" . __('Threema notifications', 'threema') . "</th></tr>";
        echo "<tr class='tab_bg_1'><td style='width:30%'>" . __('Threema ID', 'threema') . "</td><td>";
        if ($can_edit) {
            echo "<input type='text' name='threemaid' value='" . htmlspecialchars($current) . "' maxlength='8' size='10' "
                . "pattern='[0-9A-Za-z*]{8}' style='text-transform:uppercase' placeholder='ABCD1234'>";
        } else {
            echo htmlspecialchars($current) ?: '-';
        }
        if ($current !== '' && isset($sources[$source])) {
            echo " <span class='text-muted'>(" . $sources[$source] . ")</span>";
        }
        echo "</td></tr>";
        echo "<tr class='tab_bg_1'><td colspan='2' class='text-muted'>"
            . __('The 8-character ID is shown in the Threema app under Profile. Users without an ID do not receive Threema notifications.', 'threema')
            . "</td></tr>";

        if ($can_edit) {
            echo "<tr class='tab_bg_1'><td colspan='2' class='center'>";
            echo Html::submit(_x('button', 'Save'), ['name' => 'save', 'class' => 'btn btn-primary']);
            if (Config::isConfigured()) {
                echo ' ' . Html::submit(__('Look up by e-mail / mobile', 'threema'), ['name' => 'lookup', 'class' => 'btn btn-outline-secondary']);
            }
            if ($current !== '') {
                echo ' ' . Html::submit(_x('button', 'Delete permanently'), ['name' => 'delete', 'class' => 'btn btn-outline-danger']);
            }
            echo "</td></tr>";
        }
        echo "</table>";
        Html::closeForm();
        echo "</div>";
    }
}
