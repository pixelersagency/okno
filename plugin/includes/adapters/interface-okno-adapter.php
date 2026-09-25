<?php
defined( 'ABSPATH' ) || exit;

/**
 * Interface commune des sources de champs (ACF, JetEngine, Metabox, natif…).
 *
 * Un adapter parse les champs existants d'un post et produit des groupes
 * de champs normalisés :
 *
 * Groupe : { key, title, source, fields: Field[] }
 * Field  : {
 *   path       : identifiant stable du champ (data-wp-field côté front),
 *   key        : identifiant interne à la source (field key ACF…),
 *   type       : text|textarea|wysiwyg|image|link|gallery|repeater|group|flexible_content|…,
 *   label      : libellé humain,
 *   required   : bool,
 *   supported  : bool (false => lecture seule + edit_url, jamais masqué),
 *   value      : valeur brute éditable,
 *   meta       : données d'affichage ({ url } image, { rendered } wysiwyg,
 *                { items } gallery/relation, { label } post_object…),
 *   options    : choices (select, taxonomy, post_object),
 *   sub_fields : sous-champs (repeater, group, clone),
 *   layouts    : [{ name, label, sub_fields }] (flexible_content),
 *   edit_url   : lien d'édition WP classique (si !supported),
 *   source     : id de l'adapter,
 * }
 *
 * Chemins pointés : une cellule de conteneur se désigne par « champ.index.sous »
 * (repeater, flexible) ou « champ.sous » (group). Ces chemins sont acceptés en
 * lecture (annotation du front) comme en écriture (patch ciblé).
 */
interface Okno_Adapter_Interface {

	/** @return string Identifiant unique de l'adapter ('acf', 'jetengine', …). */
	public function get_id();

	/** @return bool La dépendance (plugin source) est-elle active ? */
	public function is_available();

	/**
	 * Groupes de champs normalisés pour un post.
	 *
	 * @param int $post_id Post concerné.
	 * @return array Liste de groupes (cf. docblock de l'interface).
	 */
	public function get_schema( $post_id );

	/**
	 * Écrit les valeurs appartenant à cet adapter.
	 *
	 * @param int   $post_id Post concerné.
	 * @param array $values  path => valeur brute reçue.
	 * @return array { saved: string[] (paths écrits), errors: array<string,string> (path => code) }
	 *               Les paths inconnus de l'adapter sont ignorés (ni saved ni errors).
	 */
	public function save_values( $post_id, $values );
}
