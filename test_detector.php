<?php
// Find PHP-FPM config
echo shell_exec('find / -name "www.conf" 2>/dev/null');
echo "\n---\n";
echo shell_exec('php-fpm8* -i 2>/dev/null | grep "Loaded Configuration"');
echo "\n---\n";
echo shell_exec('ls /etc/php*/');
