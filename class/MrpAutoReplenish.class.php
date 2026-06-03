<?php
/**
 * Class MrpAutoReplenish
 *
 * Maakt automatisch fabricage-MO's (Manufacturing Orders) aan voor producten met
 * een gevalideerde maak-BOM (bomtype = 0) waarvan de werkelijke voorraad onder de
 * gewenste voorraad (product.desiredstock) is gezakt.
 *
 * Bedoeld om als geplande taak (cron) te draaien. Plaats dit bestand in de map
 * custom/<jouwmodule>/class/ en pas eventueel de class-naam aan.
 *
 * Cron-registratie (Home > Setup > Scheduled jobs, of in je module-descriptor):
 *   Class file:  /custom/<jouwmodule>/class/MrpAutoReplenish.class.php
 *   Class:       MrpAutoReplenish
 *   Method:      createReplenishmentMos
 *   Parameters:  1, 0          (autoValidate=1, roundToBomQty=0)  -> komma-gescheiden
 */
class MrpAutoReplenish
{
	/** @var DoliDB */
	public $db;

	public $error = '';
	public $errors = array();
	public $output = '';
	public $result;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Cron-methode: scan maakproducten en maak MO's aan waar voorraad < gewenste voorraad.
	 *
	 * @param int $autoValidate    1 = MO direct valideren (status "Te produceren"), 0 = als concept laten staan
	 * @param int $roundToBomQty   1 = te produceren aantal afronden naar boven op een veelvoud van de BOM-hoeveelheid
	 * @param int $fk_warehouse    Optioneel: vaste productiewerkplaats/magazijn voor de MO (0 = leeg laten)
	 * @return int                 0 bij succes, < 0 bij fout (verplichte signatuur voor cron)
	 */
	public function createReplenishmentMos($autoValidate = 0, $roundToBomQty = 0, $fk_warehouse = 0)
	{
		global $conf, $user, $langs;

		require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
		require_once DOL_DOCUMENT_ROOT.'/mrp/class/mo.class.php';

		$this->output = '';
		$this->error = '';
		$this->errors = array();
		$created = 0;
		$skipped = 0;
		$now = dol_now();

		// 1) Alle gevalideerde maak-BOM's (bomtype = 0) ophalen, met de bijbehorende productgegevens.
		//    Bij meerdere BOM's per product wordt de laagste rowid gekozen; pas dit aan als je
		//    een eigen 'standaard BOM'-logica hebt.
		$sql = "SELECT b.rowid as fk_bom, b.qty as bom_qty, b.fk_product,";
		$sql .= " p.ref, p.label, p.desiredstock";
		$sql .= " FROM ".MAIN_DB_PREFIX."bom_bom as b";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = b.fk_product";
		$sql .= " WHERE b.entity IN (".getEntity('bom').")";
		$sql .= " AND b.bomtype = 0";          // 0 = fabricage (geen demontage)
		$sql .= " AND b.status = 1";           // alleen gevalideerde BOM's
		$sql .= " AND p.desiredstock > 0";     // alleen producten met ingestelde gewenste voorraad
		$sql .= " AND p.tosell = 1";           // optioneel: alleen verkoopbare producten; weghalen indien ongewenst
		$sql .= " ORDER BY b.fk_product ASC, b.rowid ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->output = 'SQL error: '.$this->error;
			return -1;
		}

		$handledProducts = array();   // voorkom dubbele verwerking bij meerdere BOM's per product

		while ($obj = $this->db->fetch_object($resql)) {
			if (isset($handledProducts[$obj->fk_product])) {
				continue;
			}
			$handledProducts[$obj->fk_product] = 1;

			$product = new Product($this->db);
			if ($product->fetch($obj->fk_product) <= 0) {
				continue;
			}

			// Werkelijke voorraad over alle magazijnen heen.
			$product->load_stock();
			$currentStock = (float) $product->stock_reel;
			$desired = (float) $obj->desiredstock;

			if ($currentStock >= $desired) {
				continue;   // voorraad is op peil
			}

			// Sla over als er al een open MO (concept/gevalideerd/in productie) voor dit product bestaat.
			if ($this->hasOpenMo($obj->fk_product)) {
				$skipped++;
				continue;
			}

			$toProduce = $desired - $currentStock;
			if ($roundToBomQty && $obj->bom_qty > 0) {
				$toProduce = ceil($toProduce / $obj->bom_qty) * $obj->bom_qty;
			}
			if ($toProduce <= 0) {
				continue;
			}

			// 2) MO aanmaken. Door fk_bom te zetten genereert create() automatisch de
			//    te-produceren regel + alle te-verbruiken componentregels uit de BOM.
			$mo = new Mo($this->db);
			$mo->fk_bom = (int) $obj->fk_bom;
			$mo->fk_product = (int) $obj->fk_product;
			$mo->qty = $toProduce;
			$mo->mrptype = 0;                 // wordt door create() alsnog uit de BOM overgenomen
			$mo->label = $langs->trans('Auto aangevuld (voorraad onder gewenste niveau)');
			$mo->date_creation = $now;
			$mo->date_start_planned = $now;   // leeg laten = ASAP
			$mo->fk_user_creat = $user->id;
			if ($fk_warehouse > 0) {
				$mo->fk_warehouse = (int) $fk_warehouse;
			}

			$moId = $mo->create($user);
			if ($moId > 0) {
				if ($autoValidate) {
					$resval = $mo->validate($user);
					if ($resval <= 0) {
						$this->errors = array_merge($this->errors, (array) $mo->errors);
					}
				}
				$created++;
				dol_syslog("MrpAutoReplenish: MO ".$mo->ref." aangemaakt voor ".$obj->ref
					." (voorraad ".$currentStock." < gewenst ".$desired."), te produceren: ".$toProduce, LOG_INFO);
			} else {
				$this->errors = array_merge($this->errors, (array) $mo->errors);
				dol_syslog("MrpAutoReplenish: fout bij aanmaken MO voor product ".$obj->fk_product
					.": ".$mo->error, LOG_ERR);
			}
		}
		$this->db->free($resql);

		$this->output = 'Aangemaakte MO\'s: '.$created.' | overgeslagen (al open MO): '.$skipped;
		if (!empty($this->errors)) {
			$this->output .= ' | fouten: '.implode('; ', $this->errors);
			return -1;
		}

		return 0;
	}

	/**
	 * Controleert of er al een open MO (concept, gevalideerd of in productie) voor dit product bestaat.
	 *
	 * @param int $fk_product Product-ID
	 * @return bool
	 */
	private function hasOpenMo($fk_product)
	{
		require_once DOL_DOCUMENT_ROOT.'/mrp/class/mo.class.php';

		$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."mrp_mo";
		$sql .= " WHERE fk_product = ".((int) $fk_product);
		$sql .= " AND mrptype = 0";
		$sql .= " AND entity IN (".getEntity('mo').")";
		$sql .= " AND status IN (".Mo::STATUS_DRAFT.", ".Mo::STATUS_VALIDATED.", ".Mo::STATUS_INPROGRESS.")";

		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			$this->db->free($resql);
			return ((int) $obj->nb > 0);
		}
		return false;
	}
}
