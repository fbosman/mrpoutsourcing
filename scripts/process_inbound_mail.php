<?php
/**
 * MRP Outsourcing - Inbound Email Processor
 * Processes incoming supplier completion/confirmation emails via IMAP.
 *
 * Subject pattern: GEREED-{MO_REF}-{TOKEN}  or  BEVESTIGD-{MO_REF}-{TOKEN}
 *
 * Cron example:
 *   *\/5 * * * * php /path/to/dolibarr/custom/mrpoutsourcing/scripts/process_inbound_mail.php
 */

define('EVEN_IF_ONLY_LOGIN_ALLOWED', 1);

$res = 0;
if (!$res && file_exists('../../../main.inc.php'))    { $res = @include '../../../main.inc.php'; }
if (!$res && file_exists('../../../../main.inc.php')) { $res = @include '../../../../main.inc.php'; }
if (!$res) { echo "Cannot find main.inc.php\n"; exit(1); }

require_once dol_buildpath('/mrpoutsourcing/class/mrpoutsourcingorder.class.php', 0);

$imapHost   = getDolGlobalString('MRPOUTSOURCING_IMAP_HOST');
$imapPort   = getDolGlobalInt('MRPOUTSOURCING_IMAP_PORT') ?: 993;
$imapUser   = getDolGlobalString('MRPOUTSOURCING_IMAP_USER');
$imapPass   = getDolGlobalString('MRPOUTSOURCING_IMAP_PASS');
$imapFolder = getDolGlobalString('MRPOUTSOURCING_IMAP_FOLDER') ?: 'INBOX';
$imapSsl    = getDolGlobalInt('MRPOUTSOURCING_IMAP_SSL') ? '/ssl' : '';

if (!$imapHost || !$imapUser || !$imapPass) {
    echo "[MRP Outsourcing] IMAP not configured. Set MRPOUTSOURCING_IMAP_HOST/USER/PASS in module settings.\n";
    exit(0);
}

if (!function_exists('imap_open')) {
    echo "[MRP Outsourcing] PHP IMAP extension not installed. Run: apt install php-imap\n";
    exit(1);
}

$mailbox = '{'.$imapHost.':'.$imapPort.'/imap'.$imapSsl.'}'.$imapFolder;
$imap    = @imap_open($mailbox, $imapUser, $imapPass, 0, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);

if (!$imap) {
    echo "[MRP Outsourcing] Cannot connect to IMAP: ".imap_last_error()."\n";
    exit(1);
}

$uids = imap_search($imap, 'UNSEEN');
if (!$uids) {
    echo "[MRP Outsourcing] No unread messages.\n";
    imap_close($imap);
    exit(0);
}

$processed = 0;
$errors    = 0;

foreach ($uids as $uid) {
    $header    = imap_headerinfo($imap, $uid);
    $subject   = isset($header->subject) ? imap_utf8($header->subject) : '';
    $fromEmail = $header->from[0]->mailbox.'@'.$header->from[0]->host;

    echo "[MRP Outsourcing] Checking: ".$subject."\n";

    if (preg_match('/\b(GEREED|BEVESTIGD)-([A-Z0-9\-]+)-([a-f0-9]{48})\b/i', $subject, $matches)) {
        $action = strtolower($matches[1]);
        $moRef  = strtoupper($matches[2]);
        $token  = $matches[3];

        $outsource = new MrpOutsourcingOrder($db);
        $found     = $outsource->fetch(0, $token);

        if ($found <= 0) {
            echo "  → Token not found: $token\n";
            $errors++;
            continue;
        }

        $body       = _getEmailBody($imap, $uid);
        $userSystem = new User($db);
        $userSystem->fetch(0, '', 1);

        if ($action === 'gereed') {
            $supplierRef = '';
            if (preg_match('/referentie[:\s]+([A-Z0-9\-\/]+)/i', $body, $refMatch)) {
                $supplierRef = trim($refMatch[1]);
            }
            $result = $outsource->markAsDone($userSystem, $supplierRef, "Gereedmelding via e-mail van $fromEmail\n\n$body");
            if ($result > 0) {
                echo "  → ✅ MO $moRef marked as DONE. Ref: $supplierRef\n";
                $processed++;
                _sendReply($imap, $uid, $header, $outsource, 'done');
            } else {
                echo "  → Error: ".$outsource->error."\n";
                $errors++;
            }
        } elseif ($action === 'bevestigd') {
            if ($outsource->status === MrpOutsourcingOrder::STATUS_SENT) {
                $sql = "UPDATE ".MAIN_DB_PREFIX."mrpoutsourcing_order SET
                        status = '".MrpOutsourcingOrder::STATUS_CONFIRMED."',
                        date_confirmed = '".$db->idate(dol_now())."',
                        callback_log = CONCAT(IFNULL(callback_log,''), '\n---\n', '".
                            $db->escape(date('Y-m-d H:i:s')." - Bevestiging per e-mail van $fromEmail")."')
                        WHERE rowid = ".(int)$outsource->id;
                $db->query($sql);
                echo "  → 📬 MO $moRef confirmed by supplier.\n";
                $processed++;
            }
        }

        imap_setflag_full($imap, $uid, '\\Seen');
    } else {
        echo "  → Subject does not match pattern, skipping.\n";
    }
}

imap_close($imap);
echo "[MRP Outsourcing] Done. Processed: $processed, Errors: $errors\n";
$db->close();

function _getEmailBody($imap, $uid)
{
    $structure = imap_fetchstructure($imap, $uid);
    $body      = '';
    if (isset($structure->parts)) {
        foreach ($structure->parts as $partNum => $part) {
            if ($part->subtype === 'PLAIN') {
                $raw = imap_fetchbody($imap, $uid, ($partNum + 1));
                if ($part->encoding == 3) $raw = base64_decode($raw);
                elseif ($part->encoding == 4) $raw = quoted_printable_decode($raw);
                $body .= $raw;
                break;
            }
        }
    } else {
        $raw = imap_body($imap, $uid);
        if ($structure->encoding == 3) $raw = base64_decode($raw);
        elseif ($structure->encoding == 4) $raw = quoted_printable_decode($raw);
        $body = $raw;
    }
    return trim($body);
}

function _sendReply($imap, $uid, $header, $outsource, $type)
{
    require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
    require_once DOL_DOCUMENT_ROOT.'/mrp/class/mo.class.php';
    global $db;
    $mo = new Mo($db);
    $mo->fetch($outsource->fk_mo);
    $to      = $header->from[0]->mailbox.'@'.$header->from[0]->host;
    $from    = getDolGlobalString('MRPOUTSOURCING_MAIL_FROM');
    $subject = 'Re: '.imap_utf8($header->subject);
    $body    = $type === 'done'
        ? '<p>Uw gereedmelding voor opdracht <strong>'.dol_htmlentities($mo->ref).'</strong> is ontvangen en verwerkt. De opdracht is als afgerond geregistreerd.</p><p>Met vriendelijke groet,<br>'.dol_htmlentities(getDolGlobalString('MAIN_INFO_SOCIETE_NOM')).'</p>'
        : '<p>Ontvangstbevestiging geregistreerd. Dank u.</p>';
    $mail    = new CMailFile($subject, $to, $from, $body, [], [], [], '', '', 0, 1);
    $mail->sendfile();
}
