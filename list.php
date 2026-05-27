<?php
/**
 * MRP Outsourcing - List of all outsourcing orders
 */

$res = 0;
if (!$res && file_exists('../main.inc.php'))       { $res = @include '../main.inc.php'; }
if (!$res && file_exists('../../main.inc.php'))    { $res = @include '../../main.inc.php'; }
if (!$res && file_exists('../../../main.inc.php')) { $res = @include '../../../main.inc.php'; }
if (!$res) die('Include of main failed');

require_once dol_buildpath('/mrpoutsourcing/class/mrpoutsourcingorder.class.php', 0);

$langs->loadLangs(array('mrp', 'mrpoutsourcing@mrpoutsourcing'));

if (!$user->rights->mrpoutsourcing->read) accessforbidden();
if (empty($conf->mrpoutsourcing->enabled)) accessforbidden();

$statusFilter = GETPOST('status_filter', 'alpha');

llxHeader('', $langs->trans('OutsourcedOrders'));
print load_fiche_titre($langs->trans('OutsourcedOrders'), '', '');

print '<form method="GET" action="list.php" class="listoptionsform">';
print '<div class="divsearchfield"><label>'.$langs->trans('Status').':</label> ';
print '<select name="status_filter" class="flat" onchange="this.form.submit()">';
print '<option value="">'.$langs->trans('All').'</option>';
foreach (['draft' => 'Concept', 'sent' => 'Verzonden', 'confirmed' => 'Bevestigd', 'done' => 'Gereed', 'cancelled' => 'Geannuleerd'] as $s => $l) {
    print '<option value="'.$s.'"'.($statusFilter === $s ? ' selected' : '').'>'.$l.'</option>';
}
print '</select></div></form>';

$sql = "SELECT o.rowid, o.fk_mo, o.fk_supplier, o.supplier_email, o.status,
               o.reduce_stock, o.date_send, o.date_done, o.supplier_ref,
               m.ref as mo_ref, m.qty, s.nom as supplier_name
        FROM ".MAIN_DB_PREFIX."mrpoutsourcing_order o
        LEFT JOIN ".MAIN_DB_PREFIX."mrp_mo m ON m.rowid = o.fk_mo
        LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = o.fk_supplier
        WHERE o.entity = ".$conf->entity;
if ($statusFilter) $sql .= " AND o.status = '".$db->escape($statusFilter)."'";
$sql .= " ORDER BY o.rowid DESC";

$res = $db->query($sql);

$statusColors  = ['draft' => '#6b7280', 'sent' => '#2563eb', 'confirmed' => '#d97706', 'done' => '#16a34a', 'cancelled' => '#dc2626'];
$statusLabels  = ['draft' => 'Concept', 'sent' => 'Verzonden', 'confirmed' => 'Bevestigd', 'done' => 'Gereed', 'cancelled' => 'Geannuleerd'];

print '<div class="div-table-responsive"><table class="tagtable nobottomiftotal liste">';
print '<thead><tr class="liste_titre">';
foreach (['#', 'MRP-order', 'Leverancier', 'E-mail', 'Aantal', 'Voorraad', 'Verzonden', 'Gereed', 'Status', ''] as $h) {
    print '<th>'.$h.'</th>';
}
print '</tr></thead><tbody>';

$i = 0;
if ($res) {
    while ($obj = $db->fetch_object($res)) {
        $color = $statusColors[$obj->status] ?? '#6b7280';
        $label = $statusLabels[$obj->status] ?? $obj->status;
        print '<tr class="oddeven">';
        print '<td><a href="send_order.php?oid='.$obj->rowid.'">#'.$obj->rowid.'</a></td>';
        print '<td><a href="'.DOL_URL_ROOT.'/mrp/mo_card.php?id='.$obj->fk_mo.'">'.dol_htmlentities($obj->mo_ref).'</a></td>';
        print '<td>'.dol_htmlentities($obj->supplier_name).'</td>';
        print '<td>'.dol_htmlentities($obj->supplier_email).'</td>';
        print '<td>'.dol_htmlentities($obj->qty).'</td>';
        print '<td>'.($obj->reduce_stock ? '✅' : '—').'</td>';
        print '<td>'.($obj->date_send ? dol_print_date($db->jdate($obj->date_send), 'dayhour') : '—').'</td>';
        print '<td>'.($obj->date_done ? dol_print_date($db->jdate($obj->date_done), 'dayhour') : '—').'</td>';
        print '<td><span style="display:inline-block;padding:2px 10px;border-radius:9999px;font-size:11px;font-weight:600;background:'.dol_htmlentities($color).'22;color:'.dol_htmlentities($color).';">'.dol_htmlentities($label).'</span></td>';
        print '<td>';
        if ($obj->status === 'draft') print '<a class="butAction" href="send_order.php?id='.$obj->fk_mo.'&oid='.$obj->rowid.'&action=preview">Verzenden</a>';
        if (in_array($obj->status, ['sent', 'confirmed'])) print '<a class="butAction" href="send_order.php?id='.$obj->fk_mo.'&oid='.$obj->rowid.'&action=markdoneform">Gereed melden</a>';
        print '</td></tr>';
        $i++;
    }
}
if ($i === 0) print '<tr><td colspan="10" class="opacitymedium center">'.$langs->trans('NoOutsourcingOrders').'</td></tr>';
print '</tbody></table></div>';

llxFooter();
$db->close();
