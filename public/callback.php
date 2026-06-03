<?php
/**
 * MRP Outsourcing - Public Callback Endpoint
 * Called when supplier clicks "Received" or "Done" links in the order email.
 * Secured by unique per-order token. No Dolibarr login required.
 */

define('NOLOGIN', 1);
define('NOCSRFCHECK', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);

$res = 0;
if (!$res && file_exists('../../main.inc.php'))       { $res = @include '../../main.inc.php'; }
if (!$res && file_exists('../../../main.inc.php'))    { $res = @include '../../../main.inc.php'; }
if (!$res && file_exists('../../../../main.inc.php')) { $res = @include '../../../../main.inc.php'; }
if (!$res) die('Cannot find main.inc.php');

require_once dol_buildpath('/mrpoutsourcing/class/mrpoutsourcingorder.class.php', 0);

$token       = GETPOST('token', 'alpha');
$action      = GETPOST('action', 'alpha');
$supplierRef = GETPOST('ref', 'alpha');

$outsource   = new MrpOutsourcingOrder($db);
$found       = $outsource->fetch(0, $token);

$userSystem  = new User($db);
$userSystem->fetch(0, '', 1);

$success = false;
$message = '';
$error   = '';
$moRef   = '';

if ($found <= 0 || !$outsource->id) {
    $error = 'Ongeldige of verlopen link. Neem contact op met uw opdrachtgever.';
} else {
    require_once DOL_DOCUMENT_ROOT.'/mrp/class/mo.class.php';
    $mo = new Mo($db);
    $mo->fetch($outsource->fk_mo);
    $moRef = $mo->ref;

    if ($action === 'confirm') {
        if ($outsource->status === MrpOutsourcingOrder::STATUS_SENT) {
            $sql = "UPDATE ".MAIN_DB_PREFIX."mrpoutsourcing_order SET
                    status = '".MrpOutsourcingOrder::STATUS_CONFIRMED."',
                    date_confirmed = '".$db->idate(dol_now())."',
                    callback_log = CONCAT(IFNULL(callback_log,''), '\n---\n', '".
                        $db->escape(date('Y-m-d H:i:s').' - Bevestiging via weblink. IP: '.getUserRemoteIP())."')
                    WHERE rowid = ".(int)$outsource->id;
            $db->query($sql);
            $success = true;
            $message = 'Bedankt! Wij hebben uw ontvangstbevestiging geregistreerd voor opdracht <strong>'.dol_htmlentities($moRef).'</strong>.';
            _notifyInternal($outsource, $mo, 'confirmed', $userSystem, $db);
        } elseif ($outsource->status === MrpOutsourcingOrder::STATUS_CONFIRMED) {
            $success = true;
            $message = 'U hebt de ontvangst van opdracht <strong>'.dol_htmlentities($moRef).'</strong> al eerder bevestigd.';
        } else {
            $error = 'Deze opdracht heeft al status: '.$outsource->status;
        }
    } elseif ($action === 'done') {
        if (in_array($outsource->status, [MrpOutsourcingOrder::STATUS_SENT, MrpOutsourcingOrder::STATUS_CONFIRMED])) {
            $result = $outsource->markAsDone($userSystem, $supplierRef, 'Gereedmelding via weblink. IP: '.getUserRemoteIP());
            if ($result > 0) {
                $success = true;
                $message = 'Uw gereedmelding voor opdracht <strong>'.dol_htmlentities($moRef).'</strong> is ontvangen. De productie-opdracht is gesloten.';
                _notifyInternal($outsource, $mo, 'done', $userSystem, $db);
            } else {
                $error = 'Fout: '.dol_htmlentities($outsource->error);
            }
        } elseif ($outsource->status === MrpOutsourcingOrder::STATUS_DONE) {
            $success = true;
            $message = 'Opdracht <strong>'.dol_htmlentities($moRef).'</strong> was al eerder gereed gemeld.';
        } else {
            $error = 'Gereedmelding niet mogelijk voor status: '.$outsource->status;
        }
    }
}

$db->close();

$companyName = getDolGlobalString('MAIN_INFO_SOCIETE_NOM');

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>MRP Opdracht &bull; <?= dol_htmlentities($companyName) ?></title>
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Segoe UI',system-ui,sans-serif;background:linear-gradient(135deg,#f0f4ff 0%,#e8f5e9 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
    .card{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,0.10);max-width:520px;width:100%;overflow:hidden}
    .card-header{background:linear-gradient(135deg,#1e3a5f 0%,#2563eb 100%);padding:28px 32px;color:#fff;text-align:center}
    .card-header h1{font-size:18px;font-weight:700}
    .card-header p{font-size:13px;opacity:0.8;margin-top:4px}
    .card-body{padding:32px;text-align:center}
    .icon-big{font-size:56px;margin-bottom:16px}
    .status-title{font-size:22px;font-weight:700;margin-bottom:10px;color:#111}
    .status-msg{font-size:15px;color:#4b5563;line-height:1.6}
    .status-msg.error{color:#b91c1c}
    .mo-badge{display:inline-block;margin-top:16px;padding:6px 16px;background:#eff6ff;color:#1d4ed8;border-radius:999px;font-size:13px;font-weight:600}
    .ref-form{margin-top:20px;text-align:left}
    .ref-form label{font-size:13px;color:#374151;font-weight:600;display:block;margin-bottom:6px}
    .ref-form input{width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px}
    .btn-green{margin-top:12px;display:block;width:100%;padding:12px;background:#16a34a;color:#fff;border:none;border-radius:6px;font-size:15px;font-weight:600;cursor:pointer;text-decoration:none;text-align:center}
    .btn-green:hover{background:#15803d}
    .card-footer{background:#f8fafc;padding:14px 32px;text-align:center;font-size:11px;color:#9ca3af;border-top:1px solid #f1f5f9}
  </style>
</head>
<body>
<div class="card">
  <div class="card-header">
    <h1><?= dol_htmlentities($companyName) ?></h1>
    <p>Productie Opdracht Portaal</p>
  </div>
  <div class="card-body">
<?php if ($error): ?>
    <div class="icon-big">⚠️</div>
    <div class="status-title">Fout</div>
    <div class="status-msg error"><?= $error ?></div>
<?php elseif ($success && $action === 'done'): ?>
    <div class="icon-big">✅</div>
    <div class="status-title">Gereedmelding Ontvangen!</div>
    <div class="status-msg"><?= $message ?></div>
    <?php if ($moRef): ?><div class="mo-badge">📋 <?= dol_htmlentities($moRef) ?></div><?php endif; ?>
<?php elseif ($success): ?>
    <div class="icon-big">📬</div>
    <div class="status-title">Ontvangst Bevestigd</div>
    <div class="status-msg"><?= $message ?></div>
    <?php if ($moRef): ?><div class="mo-badge">📋 <?= dol_htmlentities($moRef) ?></div><?php endif; ?>
    <div class="ref-form">
      <label>Uw interne referentie (optioneel):</label>
      <input type="text" id="sup_ref" placeholder="Bijv. WO-2026-0042">
      <a id="done-link" href="callback.php?token=<?= urlencode($token) ?>&action=done" class="btn-green">✅ Opdracht gereed melden</a>
    </div>
    <script>document.getElementById('sup_ref').addEventListener('input',function(){document.getElementById('done-link').href='callback.php?token=<?= urlencode($token) ?>&action=done&ref='+encodeURIComponent(this.value);});</script>
<?php else: ?>
    <div class="icon-big">🏭</div>
    <div class="status-title">Gereedmelding</div>
    <div class="ref-form">
      <label>Uw interne referentie (optioneel):</label>
      <input type="text" id="sup_ref2" placeholder="Bijv. WO-2026-0042">
      <a id="done-link2" href="callback.php?token=<?= urlencode($token) ?>&action=done" class="btn-green">✅ Gereed melden</a>
    </div>
    <script>document.getElementById('sup_ref2').addEventListener('input',function(){document.getElementById('done-link2').href='callback.php?token=<?= urlencode($token) ?>&action=done&ref='+encodeURIComponent(this.value);});</script>
<?php endif; ?>
  </div>
  <div class="card-footer"><?= dol_htmlentities($companyName) ?> &bull; MRP Outsourcing &bull; <?= date('Y') ?></div>
</div>
</body>
</html>
<?php
function _notifyInternal($outsource, $mo, $type, $user, $db)
{
    require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
    $notifyEmail = getDolGlobalString('MRPOUTSOURCING_MAIL_FROM');
    if (!$notifyEmail) return;
    if ($type === 'confirmed') {
        $subject = '[MRP] Opdracht '.$mo->ref.' bevestigd door leverancier';
        $body    = '<p>Leverancier heeft ontvangst bevestigd van opdracht <strong>'.$mo->ref.'</strong>.<br>Tijdstip: '.date('d-m-Y H:i:s').'</p>';
    } else {
        $subject = '[MRP] ✅ Opdracht '.$mo->ref.' GEREED gemeld door leverancier';
        $body    = '<p>Gereedmelding voor opdracht <strong>'.$mo->ref.'</strong>.<br>Ref leverancier: '.dol_htmlentities($outsource->supplier_ref ?: '-').'<br>Tijdstip: '.date('d-m-Y H:i:s').'<br><br>De MRP-opdracht is automatisch gesloten.</p>';
    }
    $mail = new CMailFile($subject, $notifyEmail, $notifyEmail, $body, [], [], [], '', '', 0, 1);
    $mail->sendfile();
}
