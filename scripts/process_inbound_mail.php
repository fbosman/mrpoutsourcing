<?php
/**
 * MRP Outsourcing - Inbound Email Processor
 * Verwerkt inkomende gereedmeldingen/bevestigingen van leveranciers.
 *
 * Provider instelbaar via MRPOUTSOURCING_MAIL_PROVIDER:
 *   'imap'      - klassiek IMAP met gebruikersnaam/wachtwoord (php-imap extensie)
 *   'office365' - Microsoft Graph API (OAuth2 client-credentials, zie office365mailclient.class.php)
 *
 * Onderwerp-patroon: GEREED-{MO_REF}-{TOKEN}  of  BEVESTIGD-{MO_REF}-{TOKEN}
 *
 * Cron voorbeeld:
 *   *\/5 * * * * php /path/to/dolibarr/custom/mrpoutsourcing/scripts/process_inbound_mail.php
 */

define('EVEN_IF_ONLY_LOGIN_ALLOWED', 1);

$res = 0;
if (!$res && file_exists('../../../main.inc.php'))    { $res = @include '../../../main.inc.php'; }
if (!$res && file_exists('../../../../main.inc.php')) { $res = @include '../../../../main.inc.php'; }
if (!$res) { echo "Cannot find main.inc.php\n"; exit(1); }

require_once dol_buildpath('/mrpoutsourcing/class/mrpoutsourcingorder.class.php', 0);

$provider = getDolGlobalString('MRPOUTSOURCING_MAIL_PROVIDER') ?: 'imap';
echo "[MRP Outsourcing] Provider: ".$provider."\n";

$processed = 0;
$errors    = 0;

if ($provider === 'office365') {
    list($processed, $errors) = mrpoutsourcing_run_office365($db);
} else {
    list($processed, $errors) = mrpoutsourcing_run_imap($db);
}

echo "[MRP Outsourcing] Done. Processed: $processed, Errors: $errors\n";
$db->close();


/**
 * Verwerk berichten via Microsoft Graph (Office365).
 */
function mrpoutsourcing_run_office365($db)
{
    require_once dol_buildpath('/mrpoutsourcing/class/office365mailclient.class.php', 0);

    $processed = 0;
    $errors    = 0;

    $client = new MrpOutsourcingO365Client();
    if (!$client->isConfigured()) {
        echo "[MRP Outsourcing] Office365 niet geconfigureerd. Stel MRPOUTSOURCING_O365_TENANT/CLIENT_ID/CLIENT_SECRET/MAILBOX in.\n";
        return array(0, 0);
    }
    if (!$client->authenticate()) {
        echo "[MRP Outsourcing] Office365 authenticatie mislukt: ".$client->error."\n";
        return array(0, 1);
    }

    $messages = $client->fetchUnread();
    if ($client->error) {
        echo "[MRP Outsourcing] ".$client->error."\n";
        return array(0, 1);
    }
    if (!$messages) {
        echo "[MRP Outsourcing] No unread messages.\n";
        return array(0, 0);
    }

    foreach ($messages as $m) {
        echo "[MRP Outsourcing] Checking: ".$m['subject']."\n";
        $r = mrpoutsourcing_handle_message($db, $m['subject'], $m['from'], $m['body']);
        echo "  → ".$r['log']."\n";

        if ($r['status'] === 'done' || $r['status'] === 'confirmed') $processed++;
        elseif ($r['status'] === 'error') $errors++;

        if ($r['markRead']) $client->markRead($m['id']);
        if ($r['reply'])    $client->sendReply($m['from'], $r['reply']['subject'], $r['reply']['body']);
    }

    return array($processed, $errors);
}

/**
 * Verwerk berichten via klassiek IMAP.
 */
function mrpoutsourcing_run_imap($db)
{
    $imapHost   = getDolGlobalString('MRPOUTSOURCING_IMAP_HOST');
    $imapPort   = getDolGlobalInt('MRPOUTSOURCING_IMAP_PORT') ?: 993;
    $imapUser   = getDolGlobalString('MRPOUTSOURCING_IMAP_USER');
    $imapPass   = getDolGlobalString('MRPOUTSOURCING_IMAP_PASS');
    $imapFolder = getDolGlobalString('MRPOUTSOURCING_IMAP_FOLDER') ?: 'INBOX';
    $imapSsl    = getDolGlobalInt('MRPOUTSOURCING_IMAP_SSL') ? '/ssl' : '';

    if (!$imapHost || !$imapUser || !$imapPass) {
        echo "[MRP Outsourcing] IMAP not configured. Set MRPOUTSOURCING_IMAP_HOST/USER/PASS in module settings.\n";
        return array(0, 0);
    }
    if (!function_exists('imap_open')) {
        echo "[MRP Outsourcing] PHP IMAP extension not installed. Run: apt install php-imap\n";
        return array(0, 1);
    }

    $mailbox = '{'.$imapHost.':'.$imapPort.'/imap'.$imapSsl.'}'.$imapFolder;
    $imap    = @imap_open($mailbox, $imapUser, $imapPass, 0, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
    if (!$imap) {
        echo "[MRP Outsourcing] Cannot connect to IMAP: ".imap_last_error()."\n";
        return array(0, 1);
    }

    $uids = imap_search($imap, 'UNSEEN');
    if (!$uids) {
        echo "[MRP Outsourcing] No unread messages.\n";
        imap_close($imap);
        return array(0, 0);
    }

    $processed = 0;
    $errors    = 0;

    foreach ($uids as $uid) {
        $header    = imap_headerinfo($imap, $uid);
        $subject   = isset($header->subject) ? imap_utf8($header->subject) : '';
        $fromEmail = $header->from[0]->mailbox.'@'.$header->from[0]->host;

        echo "[MRP Outsourcing] Checking: ".$subject."\n";

        $body = _getEmailBody($imap, $uid);
        $r    = mrpoutsourcing_handle_message($db, $subject, $fromEmail, $body);
        echo "  → ".$r['log']."\n";

        if ($r['status'] === 'done' || $r['status'] === 'confirmed') $processed++;
        elseif ($r['status'] === 'error') $errors++;

        if ($r['markRead']) imap_setflag_full($imap, $uid, '\\Seen');
        if ($r['reply']) {
            require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
            $from = getDolGlobalString('MRPOUTSOURCING_MAIL_FROM');
            $mail = new CMailFile($r['reply']['subject'], $fromEmail, $from, $r['reply']['body'], [], [], [], '', '', 0, 1);
            $mail->sendfile();
        }
    }

    imap_close($imap);
    return array($processed, $errors);
}

/**
 * Provider-onafhankelijke verwerking van één bericht.
 *
 * @return array{
 *   status:string,    // 'done'|'confirmed'|'noop'|'error'|'skip'
 *   log:string,       // log-regel voor de console
 *   markRead:bool,    // bericht als gelezen/seen markeren
 *   reply:?array      // ['subject'=>, 'body'=>] of null
 * }
 */
function mrpoutsourcing_handle_message($db, $subject, $fromEmail, $body)
{
    $result = array('status' => 'skip', 'log' => '', 'markRead' => false, 'reply' => null);

    if (!preg_match('/\b(GEREED|BEVESTIGD)-([A-Z0-9\-]+)-([a-f0-9]{48})\b/i', $subject, $matches)) {
        $result['log'] = 'Onderwerp matcht patroon niet, overgeslagen.';
        return $result;
    }

    $action = strtolower($matches[1]);
    $moRef  = strtoupper($matches[2]);
    $token  = $matches[3];

    $outsource = new MrpOutsourcingOrder($db);
    if ($outsource->fetch(0, $token) <= 0) {
        // Herkend patroon maar onbekend token: niet markeren (mogelijk niet voor ons).
        $result['status'] = 'error';
        $result['log']    = "Token niet gevonden: $token";
        return $result;
    }

    // Herkend en van ons: niet opnieuw verwerken bij volgende run.
    $result['markRead'] = true;

    $userSystem = new User($db);
    $userSystem->fetch(0, '', 1);

    if ($action === 'gereed') {
        $supplierRef = '';
        if (preg_match('/referentie[:\s]+([A-Z0-9\-\/]+)/i', $body, $refMatch)) {
            $supplierRef = trim($refMatch[1]);
        }
        $res = $outsource->markAsDone($userSystem, $supplierRef, "Gereedmelding via e-mail van $fromEmail\n\n$body");
        if ($res > 0) {
            $result['status'] = 'done';
            $result['log']    = "✅ MO $moRef gereed gemeld. Ref: $supplierRef";
            $result['reply']  = mrpoutsourcing_build_reply($db, $outsource, 'done', $subject);
        } else {
            $result['status'] = 'error';
            $result['log']    = "Fout: ".$outsource->error;
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
            $result['status'] = 'confirmed';
            $result['log']    = "📬 MO $moRef bevestigd door leverancier.";
        } else {
            $result['status'] = 'noop';
            $result['log']    = "MO $moRef: status is al '".$outsource->status."', geen actie.";
        }
    }

    return $result;
}

/**
 * Bouw de inhoud van het automatische antwoord aan de leverancier.
 */
function mrpoutsourcing_build_reply($db, $outsource, $type, $origSubject)
{
    require_once DOL_DOCUMENT_ROOT.'/mrp/class/mo.class.php';
    $mo = new Mo($db);
    $mo->fetch($outsource->fk_mo);

    $subject = 'Re: '.$origSubject;
    $body = $type === 'done'
        ? '<p>Uw gereedmelding voor opdracht <strong>'.dol_htmlentities($mo->ref).'</strong> is ontvangen en verwerkt. De opdracht is als afgerond geregistreerd.</p><p>Met vriendelijke groet,<br>'.dol_htmlentities(getDolGlobalString('MAIN_INFO_SOCIETE_NOM')).'</p>'
        : '<p>Ontvangstbevestiging geregistreerd. Dank u.</p>';

    return array('subject' => $subject, 'body' => $body);
}

/**
 * Haal de platte-tekst body uit een IMAP-bericht.
 */
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
