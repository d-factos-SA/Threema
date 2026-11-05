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

use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Threema\GatewayException;
use GlpiPlugin\Threema\UserId;

Session::checkLoginUser();

$users_id = (int)($_POST['users_id'] ?? 0);
if (!UserId::canEditFor($users_id)) {
    throw new AccessDeniedHttpException();
}

if (isset($_POST['save'])) {
    $id = trim((string)($_POST['threemaid'] ?? ''));
    if ($id === '') {
        UserId::deleteForUser($users_id);
        Session::addMessageAfterRedirect(__('Threema ID removed', 'threema'));
    } elseif (UserId::setIdForUser($users_id, $id)) {
        Session::addMessageAfterRedirect(__('Threema ID saved', 'threema'));
    } else {
        Session::addMessageAfterRedirect(__('Invalid Threema ID (8 characters: A-Z, 0-9, *)', 'threema'), false, ERROR);
    }
} elseif (isset($_POST['delete'])) {
    UserId::deleteForUser($users_id);
    Session::addMessageAfterRedirect(__('Threema ID removed', 'threema'));
} elseif (isset($_POST['lookup'])) {
    try {
        $found = UserId::lookupForUser($users_id);
        if ($found !== null) {
            Session::addMessageAfterRedirect(sprintf(__('Threema ID found: %s', 'threema'), $found));
        } else {
            Session::addMessageAfterRedirect(__('No Threema ID is linked to this user\'s e-mail address or mobile number.', 'threema'), false, WARNING);
        }
    } catch (GatewayException $e) {
        Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
    }
}

Html::back();
