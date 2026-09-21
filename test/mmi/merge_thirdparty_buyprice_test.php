<?php
/**
 * Fusion de tiers : les prix d'achat déplacés déclenchent les triggers — RM3234.
 *
 * Vérifie le patch de Societe::mergeCompany() (option
 * MMICORE_MERGE_THIRDPARTY_BUYPRICE_TRIGGERS) sur une base Dolibarr réelle, dans une
 * transaction ANNULÉE à la fin : aucune donnée ne reste, y compris les tiers de test.
 * Mails et synchro Presta coupés en mémoire.
 *
 * Usage : php7.4 test/mmi/merge_thirdparty_buyprice_test.php
 * Code de sortie 0 si tout passe. NE PAS lancer en prod.
 */

$script_file = basename(__FILE__);
chdir(__DIR__.'/../../htdocs');
require_once __DIR__.'/../../htdocs/master.inc.php';

require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.product.class.php';

global $db, $conf, $user;

// Garde-fous
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;
$conf->global->MMIPRESTASYNC_SYNC = 0;
$conf->global->MMI_PRODUCT_PRODUCT_DEFAULT_SUPPLIER_UPDATE = 1;

if (empty($user) || empty($user->id)) {
	$user = new User($db);
	$resql = $db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'user WHERE admin=1 AND statut=1 ORDER BY rowid LIMIT 1');
	$user->fetch($db->fetch_object($resql)->rowid);
	$user->getrights();
}

$fail = 0;
$n = 0;
function check($label, $cond, $detail = '')
{
	global $fail, $n;
	$n++;
	echo ($cond ? 'ok   ' : 'FAIL ').$label.(!$cond && $detail !== '' ? ' — '.$detail : '')."\n";
	if (!$cond)
		$fail++;
}
function mk_supplier($suffix)
{
	global $db, $user;
	$s = new Societe($db);
	$s->name = 'TEST-RM3234-'.$suffix.'-'.dol_print_date(dol_now(), '%H%M%S');
	$s->fournisseur = 1;
	$s->client = 0;
	$s->status = 1;
	$s->country_id = 1;
	// Le module de codification exige un code fournisseur valide : la méthode le pose
	// elle-même sur l'objet (elle ne retourne rien).
	$s->get_codefournisseur($s, 1);
	// Le module de codification comptable de l'instance (sfycustom) n'est pas chargé en CLI :
	// on pose un code comptable de test, ce que la validation demande.
	$s->code_compta_fournisseur = '401RM3234'.substr($suffix, 0, 4);
	$id = $s->create($user);
	if ($id <= 0) {
		echo "création tiers KO : ".$s->error.' '.json_encode($s->errors)."\n";
		exit(2);
	}
	return $s;
}
function mk_product($suffix)
{
	global $db, $user;
	$p = new Product($db);
	$p->ref = 'TEST-RM3234-'.$suffix.'-'.dol_print_date(dol_now(), '%H%M%S');
	$p->label = 'Test RM3234 fusion';
	$p->type = Product::TYPE_PRODUCT;
	$p->status = 1;
	$p->status_buy = 1;
	$id = $p->create($user);
	if ($id <= 0) {
		echo "création produit KO : ".$p->error."\n";
		exit(2);
	}
	return $p;
}
function add_price($product_id, $soc, $ref, $price)
{
	global $db, $user;
	$pf = new ProductFournisseur($db);
	$pf->id = $product_id;
	$pf->fetch($product_id);
	$r = $pf->update_buyprice(1, $price, $user, 'HT', $soc, 0, $ref, 20);
	return $r > 0 ? (int) $pf->product_fourn_price_id : $r;
}
function default_supplier_of($product_id)
{
	global $db;
	$p = new Product($db);
	$p->fetch($product_id);
	return [
		'fk_soc'=>(int) ($p->array_options['options_fk_soc_fournisseur'] ?? 0),
		'ref'=>(string) ($p->array_options['options_supplier_ref'] ?? ''),
	];
}
function line_supplier($pfp_id)
{
	global $db;
	$resql = $db->query('SELECT fk_soc, ref_fourn FROM '.MAIN_DB_PREFIX.'product_fournisseur_price WHERE rowid='.((int) $pfp_id));
	$o = $db->fetch_object($resql);
	return $o ? ['fk_soc'=>(int) $o->fk_soc, 'ref'=>$o->ref_fourn] : null;
}

$db->begin();

// La file de synchro Presta (_sync) est écrite par mmiprestasync sur une AUTRE connexion :
// elle échappe à notre transaction (et à son instantané de lecture). On l'interroge donc
// avec une connexion dédiée, et on nettoie nos propres lignes à la fin.
$sync_db = new mysqli($GLOBALS['dolibarr_main_db_host'], $GLOBALS['dolibarr_main_db_user'], $GLOBALS['dolibarr_main_db_pass'], $GLOBALS['dolibarr_main_db_name']);
function sync_count($sql)
{
	global $sync_db;
	$r = $sync_db->query($sql);
	$row = $r ? $r->fetch_row() : null;
	return $row ? (int) $row[0] : 0;
}
$sync_max_id = sync_count('SELECT IFNULL(MAX(id),0) FROM `_sync`');

// --- 1. Option ACTIVE : la fusion doit déplacer les prix ET mettre à jour le fournisseur par défaut
$conf->global->MMICORE_MERGE_THIRDPARTY_BUYPRICE_TRIGGERS = 1;
// Pour ce cas, on veut vérifier que la synchro Presta est bien notifiée
$conf->global->MMIPRESTASYNC_SYNC = 1;

$origin = mk_supplier('ORIG');
$dest = mk_supplier('DEST');
$product = mk_product('P1');
$pfp = add_price($product->id, $origin, 'REF-A', 10);
check('préparation : prix créé chez le tiers d\'origine', $pfp > 0 && line_supplier($pfp)['fk_soc'] === (int) $origin->id);
check('préparation : fournisseur par défaut = origine', default_supplier_of($product->id)['fk_soc'] === (int) $origin->id);

$res = $dest->mergeCompany($origin->id);
check('fusion acceptée', $res >= 0, $dest->error.' '.implode(' ', $dest->errors));
check('prix d\'achat déplacé vers la cible', line_supplier($pfp)['fk_soc'] === (int) $dest->id, json_encode(line_supplier($pfp)));
check('réf fournisseur conservée', line_supplier($pfp)['ref'] === 'REF-A');
$state = default_supplier_of($product->id);
check('fournisseur par défaut suit la cible', $state['fk_soc'] === (int) $dest->id, json_encode($state));
check('réf du produit conservée', $state['ref'] === 'REF-A', json_encode($state));
check('tiers d\'origine supprimé', (int) $db->fetch_object($db->query('SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX.'societe WHERE rowid='.((int) $origin->id)))->nb === 0);

// La mise à jour du produit doit avoir déposé une notification de synchro Presta
$nbnotif = sync_count('SELECT COUNT(*) FROM `_sync` WHERE id>'.$sync_max_id.' AND type="product" AND t_oid='.((int) $product->id));
check('synchro Presta notifiée pour le produit', $nbnotif > 0, "notifications=$nbnotif");
$conf->global->MMIPRESTASYNC_SYNC = 0;

// --- 2. Collision de réf fournisseur : la fusion est refusée AVANT toute écriture
$origin2 = mk_supplier('ORIG2');
$dest2 = mk_supplier('DEST2');
$product2 = mk_product('P2');
$pfp2a = add_price($product2->id, $origin2, 'REF-DUP', 10);
$pfp2b = add_price($product2->id, $dest2, 'REF-DUP', 12);
$res = $dest2->mergeCompany($origin2->id);
check('collision de réf → fusion refusée', $res < 0);
check('collision → message explicite', strpos($dest2->error.' '.implode(' ', $dest2->errors), 'REF-DUP') !== false, $dest2->error);
check('collision → rien n\'a bougé', line_supplier($pfp2a)['fk_soc'] === (int) $origin2->id);
check('collision → tiers d\'origine toujours là', (int) $db->fetch_object($db->query('SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX.'societe WHERE rowid='.((int) $origin2->id)))->nb === 1);

// --- 3. Option INACTIVE : comportement natif (les prix bougent, le défaut reste sur le tiers supprimé)
$conf->global->MMICORE_MERGE_THIRDPARTY_BUYPRICE_TRIGGERS = 0;
$origin3 = mk_supplier('ORIG3');
$dest3 = mk_supplier('DEST3');
$product3 = mk_product('P3');
$pfp3 = add_price($product3->id, $origin3, 'REF-C', 10);
$res = $dest3->mergeCompany($origin3->id);
check('option off : fusion acceptée', $res >= 0, $dest3->error);
check('option off : prix déplacé (comportement natif)', line_supplier($pfp3)['fk_soc'] === (int) $dest3->id);
check('option off : fournisseur par défaut NON mis à jour', default_supplier_of($product3->id)['fk_soc'] === (int) $origin3->id);

$db->rollback();

// Nettoyage de la file de synchro (hors transaction) : nos notifications visent des objets
// qui viennent d'être annulés.
$sync_db->query('DELETE FROM `_sync` WHERE id>'.$sync_max_id);
check('file de synchro nettoyée', sync_count('SELECT COUNT(*) FROM `_sync` WHERE id>'.$sync_max_id) === 0);

$nb = (int) $db->fetch_object($db->query('SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX.'societe WHERE nom LIKE "TEST-RM3234-%"'))->nb;
$nb += (int) $db->fetch_object($db->query('SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX.'product WHERE ref LIKE "TEST-RM3234-%"'))->nb;
check('rollback : aucune donnée de test restante', $nb === 0, "restant=$nb");

echo "\n".($n - $fail)."/$n OK\n";
exit($fail ? 1 : 0);
