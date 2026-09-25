/**
 * Génère dist/okno-bridge.js (IIFE + auto-init) depuis src/index.js.
 * Aucune dépendance : node bridge/build.js (ESM, comme le package).
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname( fileURLToPath( import.meta.url ) );
const src = fs.readFileSync( path.join( dir, 'src/index.js' ), 'utf8' );

const body = src
	.replace( /^export function initOknoBridge/m, 'function initOknoBridge' )
	.replace( /\/\/ Pas d'auto-init en ESM[\s\S]*$/m, '' )
	.trimEnd();

const out = `/*!
 * okno-bridge v5 — standalone (auto-init).
 * Généré par bridge/build.js depuis src/index.js — ne pas éditer à la main.
 *
 * Chargement recommandé (le bridge ne sert qu'à l'éditeur) :
 *   <script>
 *     if (window.self !== window.top) {
 *       var s = document.createElement('script');
 *       s.src = '/okno-bridge.js';
 *       s.setAttribute('data-wp-origin', 'https://admin.monsite.fr');
 *       document.head.appendChild(s);
 *     }
 *   </script>
 */
( function () {
	'use strict';

${ body
	.split( '\n' )
	.map( ( line ) => ( line.trim() ? '\t' + line : line ) )
	.join( '\n' ) }

	window.initOknoBridge = initOknoBridge;

	// Auto-init depuis l'attribut du tag <script>.
	var script = document.currentScript;
	var origin = script && script.getAttribute( 'data-wp-origin' );
	if ( origin ) {
		initOknoBridge( {
			wpOrigin: origin,
			cloakTimeout: Number( script.getAttribute( 'data-okno-cloak-timeout' ) ) || undefined,
		} );
	}
} )();
`;

fs.writeFileSync( path.join( dir, 'dist/okno-bridge.js' ), out );
console.log( 'dist/okno-bridge.js écrit (' + out.length + ' octets)' );

// Copie livrée avec le plugin : téléchargeable depuis wp-admin (Okno → Démarrer).
const pluginCopy = path.join( dir, '../plugin/assets/bridge/okno-bridge.js' );
fs.mkdirSync( path.dirname( pluginCopy ), { recursive: true } );
fs.writeFileSync( pluginCopy, out );
console.log( 'plugin/assets/bridge/okno-bridge.js mis à jour' );
