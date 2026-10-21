<?php
// Clear PHP opcache
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPcache cleared<br>";
}

// Clear APC cache
if (function_exists('apc_clear_cache')) {
    apc_clear_cache();
    echo "APC cache cleared<br>";
}

echo "Cache clearing complete. Please refresh your dashboard.";
?>
