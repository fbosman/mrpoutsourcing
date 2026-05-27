<?php
/**
 * MRP Outsourcing - Send Order to Supplier
 */

$res = 0;
if (!$res && file_exists('../main.inc.php'))               { $res = @include '../main.inc.php'; }
if (!$res && file_exists('../../main.inc.php'))            { $res = @include '../../main.inc.php'; }
if (!$res && file_exists('../../../main.inc.php'))         { $res = @include '../../../main.inc.php'; }
if (!$res) die('Include of main failed');

require_once DOL_DOCUMENT_ROOT.'/core/class/html.formcompany.class.php';
require_once DOL_DOCUMENT_ROOT.'/mrp/class/mo.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once dol_buildpath('/mrpoutsourcing/class/mrpoutsourcingorder.class.php', 0);

$langs->loadLangs(array('mrp', 'mrpoutsourcing@mrpoutsourcing', 'mails'));

if (!$user->rights->mrpoutsourcing->write) accessforbidden();
if (empty($conf->mrpoutsourcing->enabled)) accessforbidden();

$action = GETPOST('action', 'aZ09');
$id     = GETPOSTINT('id');
$oid    = GETPOSTINT('oid');

$mo = new Mo($db);
if ($id && $mo->fetch($id) <= 0) { dol_print_error($db); exit; }

$outsource = new MrpOutsourcingOrder($db);

if ($action === 'create' && !empty($_POST['supplier_id'])) {
    $outsource->fk_mo          = $mo->id;
    $outsource->fk_supplier    = GETPOSTINT('supplier_id');
    $outsource->supplier_email = GETPOST('supplier_email', 'email');
    $outsource->reduce_stock   = GETPOSTINT('reduce_stock');
    $outsource->note_public    = GETPOST('note_public', 'restricthtml');
    $outsource->note_private   = GETPOST('note_private', 'restricthtml');

    $newid = $outsource->create($user);
    if ($newid > 0) {
        setEventMessages($langs->trans('OutsourcingOrderCreated'), null, 'mesgs');
        header('Location: send_order.php?id='.$mo->id.'&oid='.$newid.'&action=preview');
        exit;
    } else {
        setEventMessages($outsource->error, null, 'errors');
    }
}

if ($action === 'send' && $oid) {
    $outsource->fetch($oid);
    $result = $outsource->sendToSupplier($user);
    if ($result > 0) {
        setEventMessages($langs->trans('OutsourcingOrderSent'), null, 'mesgs');
        header('Location: list.php');
        exit;
    } else {
        setEventMessages($outsource->error, null, 'errors');
    }
}

if ($action === 'markdone' && $oid) {
    $outsource->fetch($oid);
    $result = $outsource->markAsDone($user, GETPOST('supplier_ref', 'alpha'), 'Handmatig gereed gemeld door '.$user->getFullName($langs));
    if ($result > 0) {
        setEventMessages($langs->trans('OutsourcingOrderDone'), null, 'mesgs');
        header('Location: list.php');
        exit;
    } else {
        setEventMessages($outsource->error, null, 'errors');
    }
}

if ($oid && empty($action)) {
    $outsource->fetch($oid);
}

llxHeader('', $langs->trans('OutsourcingOrder'), '');

$form        = new Form($db);
$formcompany = new FormCompany($db);

$head    = array();
$head[0] = array(dol_buildpath('/mrp/mo_card.php', 1).'?id='.$mo->id, $langs->trans('ManufacturingOrder'), 'mo');
dol_fiche_head($head, 'mo', '', -1, 'mrp');

print '<div class="fichecenter"><table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">'.$langs->trans('Ref').'</td><td>'.$mo->getNomUrl(1).'</td></tr>';
if ($mo->fk_product) {
    require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
    $prod = new Product($db);
    $prod->fetch($mo->fk_product);
    print '<tr><td>'.$langs->trans('Product').'</td><td>'.$prod->getNomUrl(1).' - '.dol_htmlentities($prod->label).'</td></tr>';
}
print '<tr><td>'.$langs->trans('Qty').'</td><td><strong>'.dol_htmlentities($mo->qty).'</strong></td></tr>';
print '</table></div>';
dol_fiche_end();

if (empty($oid) || $action === 'new') {
    print '<form method="POST" action="send_order.php?id='.$mo->id.'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="create">';
    print load_fiche_titre($langs->trans('NewOutsourcingOrder'), '', '');
    print '<table class="border centpercent">';
    print '<tr><td class="fieldrequired titlefield">'.$langs->trans('Supplier').'</td><td>';
    print $form->select_company('', 'supplier_id', '(s.fournisseur:=:1)', $langs->trans('SelectSupplier'), 1, 0, null, 0, 'minwidth200');
    print '</td></tr>';
    print '<tr><td class="fieldrequired">'.$langs->trans('EmailSupplier').'</td><td>';
    print '<input type="email" name="supplier_email" id="supplier_email" class="flat minwidth300" required placeholder="leverancier@bedrijf.nl">';
    print '</td></tr>';
    $defaultReduce = getDolGlobalInt('MRPOUTSOURCING_DEFAULT_REDUCE_STOCK');
    print '<tr><td>'.$langs->trans('ReduceComponentStock').'</td><td>';
    print '<select name="reduce_stock" class="flat">';
    print '<option value="0"'.($defaultReduce ? '' : ' selected').'>'.$langs->trans('No').' - '.$langs->trans('ReduceStockNo').'</option>';
    print '<option value="1"'.($defaultReduce ? ' selected' : '').'>'.$langs->trans('Yes').' - '.$langs->trans('ReduceStockYes').'</option>';
    print '</select>';
    print '<br><span class="opacitymedium small">'.$langs->trans('ReduceStockHelp').'</span>';
    print '</td></tr>';
    print '<tr><td class="tdtop">'.$langs->trans('NoteForSupplier').'</td><td>';
    print '<textarea name="note_public" class="flat" rows="4" style="width:100%;"></textarea>';
    print '</td></tr>';
    print '<tr><td class="tdtop">'.$langs->trans('NotePrivate').'</td><td>';
    print '<textarea name="note_private" class="flat" rows="2" style="width:100%;"></textarea>';
    print '</td></tr>';
    print '</table>';
    print '<div class="center" style="margin-top:16px;">';
    print '<input type="submit" class="button" value="'.$langs->trans('CreateOutsourcingOrder').'">';
    print ' &nbsp; <a class="button button-cancel" href="'.dol_buildpath('/mrp/mo_card.php', 1).'?id='.$mo->id.'">'.$langs->trans('Cancel').'</a>';
    print '</div></form>';
}

if ($oid && $outsource->id && $action === 'preview') {
    print load_fiche_titre($langs->trans('OutsourcingOrderPreview'), '', '');
    print '<div style="background:#fff5e6;border:1px solid #f59e0b;border-radius:6px;padding:14px 18px;margin-bottom:16px;">';
    print '<strong>'.$langs->trans('PreviewNotYetSent').'</strong> - '.$langs->trans('ReviewBeforeSend');
    print '</div>';
    $supplier = new Societe($db);
    $supplier->fetch($outsource->fk_supplier);
    print '<table class="border centpercent" style="margin-bottom:16px;">';
    print '<tr><td class="titlefield">'.$langs->trans('Supplier').'</td><td>'.$supplier->getNomUrl(1).'</td></tr>';
    print '<tr><td>'.$langs->trans('EmailSupplier').'</td><td>'.dol_htmlentities($outsource->supplier_email).'</td></tr>';
    print '<tr><td>'.$langs->trans('ReduceComponentStock').'</td><td>'.($outsource->reduce_stock ? '<span class="badge badge-status4">'.$langs->trans('Yes').'</span>' : '<span class="badge badge-status0">'.$langs->trans('No').'</span>').'</td></tr>';
    print '<tr><td>'.$langs->trans('Status').'</td><td>'.$outsource->getStatusLabel().'</td></tr>';
    if ($outsource->note_public) print '<tr><td>'.$langs->trans('NoteForSupplier').'</td><td>'.nl2br(dol_htmlentities($outsource->note_public)).'</td></tr>';
    print '</table>';
    if ($outsource->reduce_stock) {
        print '<div style="background:#fef3c7;border-left:4px solid #f59e0b;padding:10px 14px;margin-bottom:14px;border-radius:4px;">';
        print '<strong>⚠ '.$langs->trans('StockWillBeReduced').'</strong><br>'.$langs->trans('StockWillBeReducedDetail');
        print '</div>';
    }
    print '<div class="center">';
    print '<a class="button buttonaction" href="send_order.php?id='.$mo->id.'&oid='.$outsource->id.'&action=send&token='.newToken().'">📧 '.$langs->trans('SendNow').'</a>';
    print ' &nbsp; <a class="button button-cancel" href="send_order.php?id='.$mo->id.'">'.$langs->trans('Cancel').'</a>';
    print '</div>';
}

if ($oid && $outsource->id && $action === 'markdoneform') {
    print load_fiche_titre($langs->trans('ManuallyMarkDone'), '', '');
    print '<form method="POST" action="send_order.php?id='.$mo->id.'&oid='.$oid.'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="markdone">';
    print '<table class="border centpercent">';
    print '<tr><td class="titlefield">'.$langs->trans('SupplierRef').'</td><td><input type="text" name="supplier_ref" class="flat minwidth200"></td></tr>';
    print '</table>';
    print '<div class="center" style="margin-top:12px;">';
    print '<input type="submit" class="button buttonaction" value="'.$langs->trans('MarkAsDone').'">';
    print ' &nbsp; <a class="button button-cancel" href="list.php">'.$langs->trans('Cancel').'</a>';
    print '</div></form>';
}

print '<script>
$(document).on("change","select[name=supplier_id]",function(){
    var sid=$(this).val();
    if(!sid)return;
    $.ajax({url:"'.dol_buildpath('/mrpoutsourcing/ajax/get_supplier_email.php',1).'",data:{id:sid,token:"'.currentToken().'"},success:function(resp){if(resp.email)$("#supplier_email").val(resp.email);}});
});
</script>';

llxFooter();
$db->close();
