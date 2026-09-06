<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Keep settings and logs by default so uninstalling does not unexpectedly destroy data.
// Site owners can delete the plugin's option/table manually if they want full removal.
