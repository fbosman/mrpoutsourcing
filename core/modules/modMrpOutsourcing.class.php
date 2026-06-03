<?php
/**
 * MRP Outsourcing Module - Dolibarr Extension
 * Enables sending MRP manufacturing orders to external suppliers,
 * managing component stock deduction, and receiving completion confirmations by email.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modMrpOutsourcing extends DolibarrModules
{
    public function __construct($db)
    {
        global $langs, $conf;

        $this->db = $db;
        $this->numero = 500100;
        $this->rights_class = 'mrpoutsourcing';
        $this->family = 'mrp';
        $this->module_position = '90';
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = 'MRP Outsourcing - Stuur productie-opdrachten naar externe leveranciers';
        $this->descriptionlong = 'Maakt het mogelijk MRP-productie-opdrachten te mailen naar externe leveranciers, component-voorraad optioneel te verlagen, en gereedmeldingen per mail te ontvangen.';
        $this->editor_name = 'Custom Module';
        $this->editor_url = '';
        $this->version = '1.0';
        $this->const_name = 'MAIN_MODULE_MRPOUTSOURCING';
        $this->picto = 'mrpoutsourcing@mrpoutsourcing';

        $this->module_parts = array(
            'triggers' => 1,
            'hooks'    => array('mrpindex', 'mrpcard'),
            'models'   => 0,
            'tpl'      => 1,
        );

        $this->dirs = array('/mrpoutsourcing/temp');

        $this->config_page_url = array('admin/mrpoutsourcing_setup.php@mrpoutsourcing');
        $this->langfiles = array('mrpoutsourcing@mrpoutsourcing');

        $this->const = array(
            0 => array('MRPOUTSOURCING_MAIL_FROM', 'chaine', '', 'Afzender e-mailadres voor leverancier-opdrachten', 0, 'current', 1),
            1 => array('MRPOUTSOURCING_TOKEN_SECRET', 'chaine', bin2hex(random_bytes(16)), 'Geheim token voor beveiligde gereedmelding-links', 0, 'current', 1),
            2 => array('MRPOUTSOURCING_DEFAULT_REDUCE_STOCK', 'chaine', '0', 'Standaard: componenten-voorraad verlagen bij uitbesteden (0=nee, 1=ja)', 0, 'current', 1),
        );

        $this->tables = array('mrpoutsourcing_order');
        $this->dictionaries = array();

        $this->rights = array();
        $r = 0;

        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Outsourcing-opdrachten lezen';
        $this->rights[$r][4] = 'read';
        $r++;

        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Outsourcing-opdrachten aanmaken en verzenden';
        $this->rights[$r][4] = 'write';
        $r++;

        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Outsourcing-opdrachten beheren en gereed melden';
        $this->rights[$r][4] = 'manage';

        $this->menus = array();
        $this->menus[0] = array(
            'fk_menu'  => 'fk_mainmenu=mrp',
            'type'     => 'left',
            'titre'    => 'Uitbestede Opdrachten',
            'mainmenu' => 'mrp',
            'leftmenu' => 'mrpoutsourcing',
            'url'      => '/mrpoutsourcing/list.php',
            'langs'    => 'mrpoutsourcing@mrpoutsourcing',
            'position' => 200,
            'enabled'  => '$conf->mrpoutsourcing->enabled',
            'perms'    => '$user->rights->mrpoutsourcing->read',
            'target'   => '',
            'user'     => 2,
        );
    }

    public function init($options = '')
    {
        $sql = array();
        return $this->_init($sql, $options);
    }

    public function remove($options = '')
    {
        $sql = array();
        return $this->_remove($sql, $options);
    }
}
