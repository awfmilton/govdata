<?php
try {
    $p = new PDO('mysql:host=35.230.186.163;port=3306;dbname=grantconts', 'pt_read', '123456789!!!!!!!!!', [\PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone='+00:00', SESSION MAX_EXECUTION_TIME=8000"]);
    echo "OK\n";
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}
