<?php
/**
 * MRP Outsourcing - Microsoft Graph (Office365) mail client
 *
 * Leest en beantwoordt mailboxberichten via de Microsoft Graph API met de
 * OAuth2 client-credentials flow (app-only toegang, geen gebruikerslogin).
 *
 * Vereist een Azure AD app-registratie met Application-rechten (admin consent):
 *   - Mail.ReadWrite   (berichten lezen + als gelezen markeren)
 *   - Mail.Send        (automatisch antwoord sturen)
 *
 * Benodigde moduleconstanten:
 *   MRPOUTSOURCING_O365_TENANT          Tenant-id (of domein, bv. bedrijf.onmicrosoft.com)
 *   MRPOUTSOURCING_O365_CLIENT_ID       Application (client) ID
 *   MRPOUTSOURCING_O365_CLIENT_SECRET   Client secret (versleuteld opgeslagen)
 *   MRPOUTSOURCING_O365_MAILBOX         Te lezen mailbox (userPrincipalName / e-mailadres)
 *   MRPOUTSOURCING_O365_FOLDER          Map (well-known naam, standaard 'inbox')
 */

class MrpOutsourcingO365Client
{
    private $tenant;
    private $clientId;
    private $clientSecret;
    private $mailbox;
    private $folder;
    private $token;

    public $error = '';

    public function __construct()
    {
        $this->tenant       = getDolGlobalString('MRPOUTSOURCING_O365_TENANT');
        $this->clientId     = getDolGlobalString('MRPOUTSOURCING_O365_CLIENT_ID');
        $this->clientSecret = getDolGlobalString('MRPOUTSOURCING_O365_CLIENT_SECRET');
        $this->mailbox      = getDolGlobalString('MRPOUTSOURCING_O365_MAILBOX');
        $this->folder       = getDolGlobalString('MRPOUTSOURCING_O365_FOLDER') ?: 'inbox';
    }

    public function isConfigured()
    {
        return $this->tenant && $this->clientId && $this->clientSecret && $this->mailbox;
    }

    /**
     * Haal een app-only access token op (client-credentials flow). Token wordt gecached voor deze run.
     */
    public function authenticate()
    {
        if ($this->token) return true;

        $url  = 'https://login.microsoftonline.com/'.rawurlencode($this->tenant).'/oauth2/v2.0/token';
        $post = http_build_query(array(
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope'         => 'https://graph.microsoft.com/.default',
            'grant_type'    => 'client_credentials',
        ));

        $res = $this->httpRaw('POST', $url, array('Content-Type: application/x-www-form-urlencoded'), $post);
        if ($res['code'] != 200 || empty($res['json']['access_token'])) {
            $this->error = 'Token ophalen mislukt (HTTP '.$res['code'].'): '.
                (isset($res['json']['error_description']) ? $res['json']['error_description'] : $res['body']);
            return false;
        }
        $this->token = $res['json']['access_token'];
        return true;
    }

    /**
     * Haal ongelezen berichten op uit de geconfigureerde map.
     * @return array Lijst met ['id','subject','from','body'] (lege array bij geen berichten of fout).
     */
    public function fetchUnread()
    {
        $path = '/users/'.rawurlencode($this->mailbox).'/mailFolders/'.rawurlencode($this->folder).'/messages'.
                '?$filter='.rawurlencode('isRead eq false').'&$top=50&$select=id,subject,from,body';

        // Vraag platte tekst i.p.v. HTML body, zodat de "referentie:"-parsing werkt.
        $res = $this->graph('GET', $path, null, array('Prefer: outlook.body-content-type="text"'));
        if (!$res || $res['code'] != 200) {
            $this->error = 'Berichten ophalen mislukt (HTTP '.($res['code'] ?? 0).'): '.($res['body'] ?? 'geen antwoord');
            return array();
        }

        $out = array();
        $values = isset($res['json']['value']) ? $res['json']['value'] : array();
        foreach ($values as $msg) {
            $out[] = array(
                'id'      => isset($msg['id']) ? $msg['id'] : '',
                'subject' => isset($msg['subject']) ? $msg['subject'] : '',
                'from'    => isset($msg['from']['emailAddress']['address']) ? $msg['from']['emailAddress']['address'] : '',
                'body'    => isset($msg['body']['content']) ? $msg['body']['content'] : '',
            );
        }
        return $out;
    }

    /**
     * Markeer een bericht als gelezen.
     */
    public function markRead($messageId)
    {
        $res = $this->graph('PATCH', '/users/'.rawurlencode($this->mailbox).'/messages/'.rawurlencode($messageId), array('isRead' => true));
        return $res && in_array($res['code'], array(200, 204));
    }

    /**
     * Stuur een (antwoord)mail vanuit de mailbox.
     */
    public function sendReply($to, $subject, $htmlBody)
    {
        $payload = array(
            'message' => array(
                'subject'      => $subject,
                'body'         => array('contentType' => 'HTML', 'content' => $htmlBody),
                'toRecipients' => array(array('emailAddress' => array('address' => $to))),
            ),
            'saveToSentItems' => true,
        );
        $res = $this->graph('POST', '/users/'.rawurlencode($this->mailbox).'/sendMail', $payload);
        if (!$res || !in_array($res['code'], array(200, 202))) {
            $this->error = 'Antwoord sturen mislukt (HTTP '.($res['code'] ?? 0).'): '.($res['body'] ?? '');
            return false;
        }
        return true;
    }

    /**
     * Voer een Graph-aanroep uit met het bearer-token.
     */
    private function graph($method, $path, $payload = null, $extraHeaders = array())
    {
        if (!$this->token && !$this->authenticate()) return null;

        $headers = array_merge(array('Authorization: Bearer '.$this->token), $extraHeaders);
        $body    = null;
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($payload);
        }
        return $this->httpRaw($method, 'https://graph.microsoft.com/v1.0'.$path, $headers, $body);
    }

    /**
     * Lage-niveau HTTP-aanroep via curl.
     * @return array ['code'=>int, 'body'=>string, 'json'=>array|null]
     */
    private function httpRaw($method, $url, $headers, $body)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return array('code' => 0, 'body' => 'curl-fout: '.$err, 'json' => null);
        }
        return array('code' => $code, 'body' => $raw, 'json' => json_decode($raw, true));
    }
}
