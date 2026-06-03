<?php
/**
 * MRP Outsourcing - Module Configuration Page
 */

$res = 0;
if (!$res && file_exists('../../main.inc.php'))      { $res = @include '../../main.inc.php'; }
if (!$res && file_exists('../../../main.inc.php'))   { $res = @include '../../../main.inc.php'; }
if (!$res && file_exists('../../../../main.inc.php')){ $res = @include '../../../../main.inc.php'; }
if (!$res) die('Include of main failed');

$langs->loadLangs(array('admin', 'mrpoutsourcing@mrpoutsourcing'));
if (!$user->admin) accessforbidden();

$action = GETPOST('action', 'aZ09');

if ($action === 'setvalue' && $_POST) {
    $settings = [
        'MRPOUTSOURCING_MAIL_FROM',
        'MRPOUTSOURCING_DEFAULT_REDUCE_STOCK',
        'MRPOUTSOURCING_IMAP_HOST',
        'MRPOUTSOURCING_IMAP_PORT',
        'MRPOUTSOURCING_IMAP_USER',
        'MRPOUTSOURCING_IMAP_PASS',
        'MRPOUTSOURCING_IMAP_FOLDER',
        'MRPOUTSOURCING_IMAP_SSL',
        'MRPOUTSOURCING_DEFAULT_WAREHOUSE',
    ];
    foreach ($settings as $key) {
        dolibarr_set_const($db, $key, GETPOST($key, 'alpha'), 'chaine', 0, '', $conf->entity);
    }
    setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
}

llxHeader('', $langs->trans('OutsourcingSetup'));
print load_fiche_titre($langs->trans('OutsourcingSetup'), '', 'setup');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="setvalue">';
print '<table class="noborder centpercent">';

print '<tr class="liste_titre"><th colspan="3">'.$langs->trans('EmailSettings').'</th></tr>';
_settingRow('MRPOUTSOURCING_MAIL_FROM',            $langs->trans('MailFromAddress'),     'text', 'productie@mijnbedrijf.nl');
_settingRow('MRPOUTSOURCING_DEFAULT_REDUCE_STOCK', $langs->trans('DefaultReduceStock'),  'select', '', ['0' => 'Nee', '1' => 'Ja']);

print '<tr class="liste_titre"><th colspan="3">'.$langs->trans('ImapSettings').'</th></tr>';
_settingRow('MRPOUTSOURCING_IMAP_HOST',   $langs->trans('ImapHost'),   'text',   'mail.mijnbedrijf.nl');
_settingRow('MRPOUTSOURCING_IMAP_PORT',   $langs->trans('ImapPort'),   'text',   '993');
_settingRow('MRPOUTSOURCING_IMAP_SSL',    $langs->trans('ImapSsl'),    'select', '', ['1' => 'Ja', '0' => 'Nee']);
_settingRow('MRPOUTSOURCING_IMAP_USER',   $langs->trans('ImapUser'),   'text',   'productie@mijnbedrijf.nl');
_settingRow('MRPOUTSOURCING_IMAP_PASS',   $langs->trans('ImapPass'),   'password', '');
_settingRow('MRPOUTSOURCING_IMAP_FOLDER', $langs->trans('ImapFolder'), 'text',   'INBOX');

print '<tr class="liste_titre"><th colspan="3">'.$langs->trans('StockSettings').'</th></tr>';
_settingRow('MRPOUTSOURCING_DEFAULT_WAREHOUSE', $langs->trans('DefaultWarehouse'), 'warehouse', '');

print '</table>';

$token = getDolGlobalString('MRPOUTSOURCING_TOKEN_SECRET');
print '<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:14px 18px;margin:16px 0;">';
print '<strong>'.$langs->trans('SecureTokenInfo').':</strong> <code style="background:#dcfce7;padding:2px 8px;border-radius:4px;">'.dol_htmlentities($token).'</code>';
print '<br><small class="opacitymedium">'.$langs->trans('TokenUsedForCallbacks').'</small>';
print '</div>';

print '<div style="background:#eff6ff;border:1px solid #93c5fd;border-radius:8px;padding:14px 18px;margin:16px 0;">';
print '<strong>'.$langs->trans('CronSetup').':</strong><br>';
print '<code style="font-size:12px;">*/5 * * * * php '.dol_buildpath('/mrpoutsourcing/scripts/process_inbound_mail.php', 0).'</code>';
print '</div>';

print '<div class="center" style="margin-top:16px;"><input type="submit" class="button" value="'.$langs->trans('Save').'"></div>';
print '</form>';

llxFooter();
$db->close();

function _settingRow($key, $label, $type, $placeholder = '', $options = [])
{
    global $conf, $langs, $db;
    $current = getDolGlobalString($key);
    print '<tr class="oddeven">';
    print '<td class="titlefield"><label for="'.$key.'">'.dol_htmlentities($label).'</label></td><td>';
    if ($type === 'text') {
        print '<input type="text" id="'.$key.'" name="'.$key.'" class="flat minwidth300" value="'.dol_htmlentities($current).'" placeholder="'.dol_htmlentities($placeholder).'">';
    } elseif ($type === 'password') {
        print '<input type="password" id="'.$key.'" name="'.$key.'" class="flat minwidth300" value="'.dol_htmlentities($current).'" autocomplete="new-password">';
    } elseif ($type === 'select') {
        print '<select id="'.$key.'" name="'.$key.'" class="flat">';
        foreach ($options as $v => $l) {
            print '<option value="'.dol_htmlentities($v).'"'.($current == $v ? ' selected' : '').'>'.dol_htmlentities($l).'</option>';
        }
        print '</select>';
    } elseif ($type === 'warehouse') {
        $form = new Form($db);
        print $form->select_entrepots($current ?: 0, $key, '', 1);
    }
    print '</td><td></td></tr>';
}
