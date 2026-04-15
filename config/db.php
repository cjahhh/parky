<?php
echo "<pre>";
echo "All ENV vars:\n";
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'MYSQL') !== false || strpos($key, 'DB') !== false) {
        echo "$key = $value\n";
    }
}
echo "</pre>";
