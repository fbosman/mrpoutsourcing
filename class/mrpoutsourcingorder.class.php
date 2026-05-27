<?php
/**
 * MrpOutsourcingOrder - Business Object
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

class MrpOutsourcingOrder extends CommonObject
{
    public $element        = 'mrpoutsourcing_order';
    public $table_element  = 'mrpoutsourcing_order';
    public $picto          = 'mrpoutsourcing@mrpoutsourcing';

    public $fk_mo;
    public $fk_supplier;
    public $supplier_email;
    public $status;
    public $reduce_stock;
    public $stock_reduced;
    public $token;
    public $date_send;
    public $date_confirmed;
    public $date_done;
    public $supplier_ref;
    public $note_private;
    public $note_public;
    public $mail_log;
    public $callback_log;

    const STATUS_DRAFT     = 'draft';
    const STATUS_SENT      = 'sent';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_DONE      = 'done';
    const STATUS_CANCELLED = 'cancelled';

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function create($user, $notrigger = 0)
    {
        global $conf;
        $this->token = $this->_generateToken();
        $now = $this->db->idate(dol_now());

        $sql = "INSERT INTO ".MAIN_DB_PREFIX."mrpoutsourcing_order
            (fk_mo, fk_supplier, supplier_email, status, reduce_stock, stock_reduced,
             token, note_private, note_public, date_creation, fk_user_creat, entity)
            VALUES (
                ".(int)$this->fk_mo.",
                ".(int)$this->fk_supplier.",
                '".$this->db->escape($this->supplier_email)."',
                '".self::STATUS_DRAFT."',
                ".(int)$this->reduce_stock.",
                0,
                '".$this->db->escape($this->token)."',
                '".$this->db->escape($this->note_private)."',
                '".$this->db->escape($this->note_public)."',
                '".$now."',
                ".(int)$user->id.",
                ".(int)$conf->entity."
            )";

        $this->db->begin();
        $res = $this->db->query($sql);
        if ($res) {
            $this->id = $this->db->last_insert_id(MAIN_DB_PREFIX.'mrpoutsourcing_order');
            $this->status = self::STATUS_DRAFT;
            $this->db->commit();
            return $this->id;
        }
        $this->db->rollback();
        $this->error = $this->db->lasterror();
        return -1;
    }

    public function fetch($id, $token = '')
    {
        $sql = "SELECT * FROM ".MAIN_DB_PREFIX."mrpoutsourcing_order WHERE ";
        if ($token) {
            $sql .= "token='".$this->db->escape($token)."'";
        } else {
            $sql .= "rowid=".(int)$id;
        }

        $res = $this->db->query($sql);
        if (!$res) { $this->error = $this->db->lasterror(); return -1; }
        if ($this->db->num_rows($res) == 0) return 0;

        $obj = $this->db->fetch_object($res);
        $this->id             = $obj->rowid;
        $this->fk_mo          = $obj->fk_mo;
        $this->fk_supplier    = $obj->fk_supplier;
        $this->supplier_email = $obj->supplier_email;
        $this->status         = $obj->status;
        $this->reduce_stock   = $obj->reduce_stock;
        $this->stock_reduced  = $obj->stock_reduced;
        $this->token          = $obj->token;
        $this->date_send      = $this->db->jdate($obj->date_send);
        $this->date_confirmed = $this->db->jdate($obj->date_confirmed);
        $this->date_done      = $this->db->jdate($obj->date_done);
        $this->supplier_ref   = $obj->supplier_ref;
        $this->note_private   = $obj->note_private;
        $this->note_public    = $obj->note_public;
        $this->mail_log       = $obj->mail_log;
        $this->callback_log   = $obj->callback_log;
        return 1;
    }

    public function update($user, $notrigger = 0)
    {
        $sql = "UPDATE ".MAIN_DB_PREFIX."mrpoutsourcing_order SET
            fk_supplier     = ".(int)$this->fk_supplier.",
            supplier_email  = '".$this->db->escape($this->supplier_email)."',
            reduce_stock    = ".(int)$this->reduce_stock.",
            note_private    = '".$this->db->escape($this->note_private)."',
            note_public     = '".$this->db->escape($this->note_public)."',
            date_modification = '".$this->db->idate(dol_now())."',
            fk_user_modif   = ".(int)$user->id."
            WHERE rowid = ".(int)$this->id;

        $res = $this->db->query($sql);
        if ($res) return 1;
        $this->error = $this->db->lasterror();
        return -1;
    }

    public function sendToSupplier($user)
    {
        global $conf, $langs;
        $langs->load('mrpoutsourcing@mrpoutsourcing');

        if ($this->status !== self::STATUS_DRAFT) {
            $this->error = 'Order already sent';
            return -1;
        }

        require_once DOL_DOCUMENT_ROOT.'/mrp/class/mo.class.php';
        $mo = new Mo($this->db);
        if ($mo->fetch($this->fk_mo) <= 0) {
            $this->error = 'Cannot load MO '.$this->fk_mo;
            return -1;
        }

        require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
        $supplier = new Societe($this->db);
        if ($supplier->fetch($this->fk_supplier) <= 0) {
            $this->error = 'Cannot load supplier';
            return -1;
        }

        if ($this->reduce_stock && !$this->stock_reduced) {
            $res = $this->_reduceComponentStock($mo, $user);
            if ($res < 0) return -1;
        }

        $callbackUrl = $this->_getCallbackUrl();
        $subject = $langs->trans('OutsourcingOrderSubject', $mo->ref);
        $body    = $this->_buildEmailBody($mo, $supplier, $callbackUrl, $langs);

        $result = $this->_sendMail($this->supplier_email, $subject, $body, $user);
        if ($result < 0) return -1;

        $sql = "UPDATE ".MAIN_DB_PREFIX."mrpoutsourcing_order SET
            status = '".self::STATUS_SENT."',
            date_send = '".$this->db->idate(dol_now())."',
            mail_log = '".$this->db->escape($body)."',
            stock_reduced = ".(int)($this->reduce_stock ? 1 : 0)."
            WHERE rowid = ".(int)$this->id;

        $this->db->query($sql);
        $this->status    = self::STATUS_SENT;
        $this->date_send = dol_now();
        return 1;
    }

    public function markAsDone($user, $supplierRef = '', $callbackLog = '')
    {
        if (!in_array($this->status, [self::STATUS_SENT, self::STATUS_CONFIRMED])) {
            $this->error = 'Order cannot be completed from status: '.$this->status;
            return -1;
        }

        $this->db->begin();

        $sql = "UPDATE ".MAIN_DB_PREFIX."mrpoutsourcing_order SET
            status = '".self::STATUS_DONE."',
            date_done = '".$this->db->idate(dol_now())."',
            supplier_ref = '".$this->db->escape($supplierRef)."',
            callback_log = CONCAT(IFNULL(callback_log,''), '\n---\n', '".
                $this->db->escape(date('Y-m-d H:i:s').' - '.$callbackLog)."')
            WHERE rowid = ".(int)$this->id;

        if (!$this->db->query($sql)) {
            $this->db->rollback();
            $this->error = $this->db->lasterror();
            return -1;
        }

        require_once DOL_DOCUMENT_ROOT.'/mrp/class/mo.class.php';
        $mo = new Mo($this->db);
        if ($mo->fetch($this->fk_mo) > 0) {
            if ($mo->status == Mo::STATUS_INPROGRESS || $mo->status == Mo::STATUS_VALIDATED) {
                $mo->qty_produced = $mo->qty;
                $mo->setStatut(Mo::STATUS_PRODUCED, null, $user);
            }
        }

        $this->db->commit();
        $this->status = self::STATUS_DONE;
        return 1;
    }

    private function _reduceComponentStock($mo, $user)
    {
        global $conf;
        require_once DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php';

        $sql = "SELECT ml.fk_product, ml.qty, ml.fk_warehouse
                FROM ".MAIN_DB_PREFIX."mrp_production ml
                WHERE ml.fk_mo = ".(int)$mo->id."
                  AND ml.role = 'consumed'
                  AND ml.disable_stock_change = 0";

        $res = $this->db->query($sql);
        if (!$res) { $this->error = $this->db->lasterror(); return -1; }

        $stockMovement = new MouvementStock($this->db);
        $stockMovement->setOrigin($mo->element, $mo->id);

        while ($obj = $this->db->fetch_object($res)) {
            $result = $stockMovement->livraison(
                $user,
                $obj->fk_product,
                $obj->fk_warehouse ?: getDolGlobalInt('MRPOUTSOURCING_DEFAULT_WAREHOUSE'),
                $obj->qty,
                0,
                'Uitbestede productie: '.$mo->ref.' (Outsourcing #'.$this->id.')'
            );
            if ($result < 0) {
                $this->error = 'Stock reduction failed for product '.$obj->fk_product.': '.$stockMovement->error;
                return -1;
            }
        }
        return 1;
    }

    private function _buildEmailBody($mo, $supplier, $callbackUrl, $langs)
    {
        global $conf, $mysoc;

        $sql = "SELECT p.ref, p.label, ml.qty, u.symbol as unit_symbol
                FROM ".MAIN_DB_PREFIX."mrp_production ml
                LEFT JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = ml.fk_product
                LEFT JOIN ".MAIN_DB_PREFIX."c_units u ON u.rowid = p.fk_unit
                WHERE ml.fk_mo = ".(int)$mo->id." AND ml.role = 'consumed'";

        $resLines = $this->db->query($sql);
        $componentRows = '';
        if ($resLines) {
            while ($line = $this->db->fetch_object($resLines)) {
                $componentRows .= '<tr>
                    <td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;">'.htmlspecialchars($line->ref).'</td>
                    <td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;">'.htmlspecialchars($line->label).'</td>
                    <td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;text-align:right;">'.number_format($line->qty, 2, ',', '.').' '.$line->unit_symbol.'</td>
                </tr>';
            }
        }

        $companyName = htmlspecialchars($mysoc->name ?: getDolGlobalString('MAIN_INFO_SOCIETE_NOM'));
        $moRef       = htmlspecialchars($mo->ref);
        $product     = '';
        if ($mo->fk_product) {
            require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
            $prod = new Product($this->db);
            if ($prod->fetch($mo->fk_product) > 0) {
                $product = htmlspecialchars($prod->ref.' - '.$prod->label);
            }
        }
        $qty        = number_format($mo->qty, 2, ',', '.');
        $dateNeeded = $mo->date_end_planned ? dol_print_date($mo->date_end_planned, 'day') : '-';
        $notePublic = nl2br(htmlspecialchars($this->note_public ?: ''));
        $doneUrl    = htmlspecialchars($callbackUrl.'&action=done');
        $confirmUrl = htmlspecialchars($callbackUrl.'&action=confirm');
        $mailFrom   = htmlspecialchars(getDolGlobalString('MRPOUTSOURCING_MAIL_FROM'));

        $stockInfo = $this->reduce_stock
            ? '<p style="background:#fef3c7;border-left:4px solid #f59e0b;padding:10px 14px;margin:16px 0;border-radius:4px;"><strong>Opmerking:</strong> De benodigde componenten zijn al afgeschreven uit onze voorraad en staan ter beschikking voor uw productie.</p>'
            : '<p style="background:#dbeafe;border-left:4px solid #3b82f6;padding:10px 14px;margin:16px 0;border-radius:4px;"><strong>Opmerking:</strong> De componenten voor deze opdracht worden separaat aangeleverd of zijn reeds aanwezig bij u.</p>';

        return '<!DOCTYPE html><html lang="nl"><head><meta charset="UTF-8"></head>
<body style="font-family:\'Segoe UI\',Arial,sans-serif;background:#f9fafb;margin:0;padding:24px;">
<div style="max-width:680px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 8px rgba(0,0,0,0.08);">
  <div style="background:linear-gradient(135deg,#1e3a5f 0%,#2563eb 100%);padding:28px 32px;color:#fff;">
    <div style="font-size:11px;letter-spacing:2px;text-transform:uppercase;opacity:0.75;margin-bottom:6px;">Productie-opdracht</div>
    <div style="font-size:26px;font-weight:700;">'.$moRef.'</div>
    <div style="margin-top:4px;opacity:0.85;font-size:14px;">'.$companyName.'</div>
  </div>
  <div style="padding:28px 32px;">
    <p style="margin:0 0 18px;font-size:15px;color:#374151;">Geachte '.htmlspecialchars($supplier->name).',</p>
    <p style="color:#4b5563;line-height:1.6;margin:0 0 20px;">Hierbij ontvangt u de productie-opdracht met referentie <strong>'.$moRef.'</strong>.</p>
    <table style="width:100%;border-collapse:collapse;background:#f8fafc;border-radius:6px;overflow:hidden;margin-bottom:20px;">
      <tr><td style="padding:10px 14px;font-weight:600;color:#6b7280;font-size:12px;text-transform:uppercase;width:40%;">Product</td><td style="padding:10px 14px;color:#111827;font-size:14px;">'.$product.'</td></tr>
      <tr style="background:#f1f5f9;"><td style="padding:10px 14px;font-weight:600;color:#6b7280;font-size:12px;text-transform:uppercase;">Hoeveelheid</td><td style="padding:10px 14px;color:#111827;font-size:14px;font-weight:700;">'.$qty.'</td></tr>
      <tr><td style="padding:10px 14px;font-weight:600;color:#6b7280;font-size:12px;text-transform:uppercase;">Opleverdatum</td><td style="padding:10px 14px;color:#111827;font-size:14px;">'.$dateNeeded.'</td></tr>
    </table>
    '.$stockInfo.'
    '.($componentRows ? '<h3 style="font-size:13px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:0.5px;margin:24px 0 10px;">Benodigde Componenten</h3>
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
      <thead><tr style="background:#1e3a5f;color:#fff;"><th style="padding:8px 12px;text-align:left;">Ref</th><th style="padding:8px 12px;text-align:left;">Omschrijving</th><th style="padding:8px 12px;text-align:right;">Hoeveelheid</th></tr></thead>
      <tbody>'.$componentRows.'</tbody>
    </table>' : '').'
    '.($notePublic ? '<div style="margin:20px 0;padding:14px;background:#f0fdf4;border-radius:6px;border-left:4px solid #16a34a;color:#15803d;font-size:13px;line-height:1.6;"><strong>Opmerking:</strong><br>'.$notePublic.'</div>' : '').'
    <div style="margin:28px 0 8px;text-align:center;">
      <a href="'.$confirmUrl.'" style="display:inline-block;padding:12px 28px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;font-size:14px;margin:4px;">✓ Opdracht ontvangen</a>
      &nbsp;&nbsp;
      <a href="'.$doneUrl.'" style="display:inline-block;padding:12px 28px;background:#16a34a;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;font-size:14px;margin:4px;">✅ Gereedmelding</a>
    </div>
    <p style="text-align:center;font-size:11px;color:#9ca3af;margin-top:8px;">
      Of stuur uw gereedmelding naar: <a href="mailto:'.$mailFrom.'" style="color:#2563eb;">'.$mailFrom.'</a><br>
      Vermeld in het onderwerp: GEREED-'.htmlspecialchars($mo->ref).'-'.htmlspecialchars($this->token).'
    </p>
  </div>
  <div style="background:#f1f5f9;padding:16px 32px;border-top:1px solid #e5e7eb;font-size:11px;color:#9ca3af;text-align:center;">
    '.$companyName.' &bull; Automatisch gegenereerd door Dolibarr MRP Outsourcing &bull; '.date('d-m-Y H:i').'
  </div>
</div>
</body></html>';
    }

    private function _sendMail($to, $subject, $body, $user)
    {
        global $conf;
        require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
        $from = getDolGlobalString('MRPOUTSOURCING_MAIL_FROM') ?: getDolGlobalString('MAIN_MAIL_EMAIL_FROM');
        $mail = new CMailFile($subject, $to, $from, $body, array(), array(), array(), '', '', 0, 1);
        if ($mail->sendfile()) return 1;
        $this->error = $mail->error;
        return -1;
    }

    private function _generateToken()
    {
        return bin2hex(random_bytes(24));
    }

    private function _getCallbackUrl()
    {
        return DOL_MAIN_URL_ROOT.'/custom/mrpoutsourcing/public/callback.php?token='.urlencode($this->token);
    }

    public function getStatusLabel()
    {
        $map = [
            self::STATUS_DRAFT     => '<span style="background:#e5e7eb;color:#374151;padding:2px 8px;border-radius:9999px;font-size:11px;">Concept</span>',
            self::STATUS_SENT      => '<span style="background:#dbeafe;color:#1d4ed8;padding:2px 8px;border-radius:9999px;font-size:11px;">Verzonden</span>',
            self::STATUS_CONFIRMED => '<span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:9999px;font-size:11px;">Bevestigd</span>',
            self::STATUS_DONE      => '<span style="background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:9999px;font-size:11px;">Gereed</span>',
            self::STATUS_CANCELLED => '<span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:9999px;font-size:11px;">Geannuleerd</span>',
        ];
        return $map[$this->status] ?? $this->status;
    }
}
