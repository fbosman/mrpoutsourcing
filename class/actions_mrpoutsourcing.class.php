<?php
/**
 * Hook handler: adds outsourcing button and status indicator to MRP MO card.
 *
 * Loaded by Dolibarr's hookmanager from /mrpoutsourcing/class/actions_mrpoutsourcing.class.php
 * (file name and class name must follow the actions_<module> / Actions<Module> convention).
 */

class ActionsMrpoutsourcing
{
    public $results = array();
    public $resprints;
    public $errors = array();

    public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
    {
        global $conf, $user, $langs, $db;

        if (!in_array('mocard', explode(':', $parameters['currentcontext']))) return 0;
        if (empty($conf->mrpoutsourcing->enabled)) return 0;
        if (!$user->hasRight('mrpoutsourcing', 'write')) return 0;
        if ($object->element !== 'mo') return 0;

        $langs->load('mrpoutsourcing@mrpoutsourcing');

        require_once dol_buildpath('/mrpoutsourcing/class/mrpoutsourcingorder.class.php', 0);

        $sql = "SELECT rowid, status FROM ".MAIN_DB_PREFIX."mrpoutsourcing_order
                WHERE fk_mo = ".(int)$object->id." AND entity = ".(int)$conf->entity."
                ORDER BY rowid DESC LIMIT 1";
        $res      = $db->query($sql);
        $existing = $res ? $db->fetch_object($res) : null;

        $url = dol_buildpath('/mrpoutsourcing/send_order.php', 1).'?id='.$object->id;

        if (!$existing) {
            print '<a class="butAction" href="'.$url.'">'.
                  '<span class="fa fa-paper-plane"></span> '.$langs->trans('SendToSupplier').
                  '</a>';
        } elseif (in_array($existing->status, ['draft', 'sent', 'confirmed'])) {
            $o = new MrpOutsourcingOrder($db);
            $o->fetch($existing->rowid);
            print '<span style="display:inline-block;margin:4px 2px;padding:6px 14px;background:#fef3c7;border:1px solid #f59e0b;border-radius:5px;font-size:12px;color:#92400e;">';
            print '<span class="fa fa-truck"></span> Uitbesteed: '.$o->getStatusLabel();
            print ' &bull; <a href="'.dol_buildpath('/mrpoutsourcing/list.php', 1).'">'.$langs->trans('ViewDetails').'</a>';
            print '</span>';
        } elseif ($existing->status === 'done') {
            print '<span style="display:inline-block;margin:4px 2px;padding:6px 14px;background:#d1fae5;border:1px solid #6ee7b7;border-radius:5px;font-size:12px;color:#065f46;">✅ Uitbesteding gereed</span>';
        }

        return 0;
    }
}
