<?php
/**
 * Ajax: Return supplier's email address by societe ID
 */

$res = 0;
if (!$res && file_exists('../../main.inc.php'))    { $res = @include '../../main.inc.php'; }
if (!$res && file_exists('../../../main.inc.php')) { $res = @include '../../../main.inc.php'; }
if (!$res) { echo json_encode(['error' => 'main not found']); exit; }

if (!$user->rights->mrpoutsourcing->read) { echo json_encode(['error' => 'forbidden']); exit; }

$id = GETPOSTINT('id');
if (!$id) { echo json_encode(['email' => '']); exit; }

$sql = "SELECT email FROM ".MAIN_DB_PREFIX."societe WHERE rowid=".(int)$id." AND entity=".$conf->entity;
$res = $db->query($sql);
$email = '';
if ($res && ($obj = $db->fetch_object($res))) {
    $email = $obj->email;
}

header('Content-Type: application/json');
echo json_encode(['email' => $email]);
$db->close();
