<?php
/**
 * RM3411 — Aucune entrée de menu de module ne doit déclarer comme 'leftmenu' celui de son parent.
 *
 * Menubase::menuLoad() rattache une entrée « fk_menu=-1 » à la DERNIÈRE entrée portant le
 * couple (fk_mainmenu, fk_leftmenu), pas à la première : une entrée qui reprend le leftmenu de
 * son parent devient parent des suivantes, et le menu part en escalier (prod Dercya, 2026-10-06).
 *
 * Analyse statique des descripteurs htdocs/custom/<module>/core/modules/mod*.class.php
 * (les modules non initialisés sont ignorés). Usage : php test/mmi/menu_leftmenu_parent_test.php
 * Code retour : 0 = OK, 1 = au moins une entrée fautive.
 */
$root = dirname(__DIR__, 2).'/htdocs/custom';
// Modules tiers non corrigés : aucune entrée de menu effective chez Dercya (2026-10-06).
// À retirer d'ici et à corriger si l'un d'eux affiche un jour ses menus.
$ignore = array('attachments', 'externalaccess');
$bad = 0;
$checked = 0;
foreach (glob($root.'/*/core/modules/mod*.class.php') as $file) {
	if (in_array(basename(dirname(dirname(dirname($file)))), $ignore)) {
		continue;
	}
	$src = file_get_contents($file);
	// Chaque bloc de déclaration de menu : de 'fk_menu' jusqu'au 'leftmenu' qui suit
	if (!preg_match_all("/'fk_menu'\s*=>\s*'([^']*)'(.*?)'leftmenu'\s*=>\s*'([^']*)'/s", $src, $m, PREG_SET_ORDER)) {
		continue;
	}
	foreach ($m as $entry) {
		if (strpos($entry[2], "'fk_menu'") !== false) {
			continue;	// le 'leftmenu' trouvé appartient à un bloc suivant
		}
		$checked++;
		if (preg_match('/fk_leftmenu=([^,\s]+)/', $entry[1], $p) && $p[1] === $entry[3]) {
			$bad++;
			echo 'ECHEC '.str_replace($root.'/', '', $file).' : leftmenu=\''.$entry[3].'\' identique au parent ('.$entry[1].")\n";
		}
	}
}
echo ($bad ? "ECHEC" : "OK")." : $checked entrée(s) de menu contrôlée(s), $bad fautive(s)\n";
exit($bad ? 1 : 0);
