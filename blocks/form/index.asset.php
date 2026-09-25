<?php
/**
 * Dependencies for blocks/form/index.js (there is no build step).
 *
 * @package SendBeam
 */

return array(
	// wp-server-side-render is gone with the live preview it was for: the
	// editor renders a placeholder card now. Loading a script nothing uses is
	// how an editor gets slow one dependency at a time.
	'dependencies' => array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-api-fetch' ),
	'version'      => '1.8.5',
);
