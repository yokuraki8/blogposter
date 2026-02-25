<?php
/**
 * Direct Database Connection Test (without WordPress)
 */

// Manual configuration (same as wp-config.php)
$db_host = 'localhost:10014';
$db_user = 'root';
$db_password = '';
$db_name = 'local';

echo "=== Direct Database Connection Test ===\n\n";

// Display configuration
echo "Configuration:\n";
echo "  DB_HOST: " . $db_host . "\n";
echo "  DB_USER: " . $db_user . "\n";
echo "  DB_PASSWORD: (empty)\n";
echo "  DB_NAME: " . $db_name . "\n\n";

// Attempt connection
echo "Attempting connection...\n";
try {
    $mysqli = new mysqli(
        $db_host,
        $db_user,
        $db_password,
        $db_name
    );

    if ($mysqli->connect_error) {
        echo "✗ Connection failed: " . $mysqli->connect_error . "\n";
        exit(1);
    }

    echo "✓ Connection successful!\n\n";

    // Check database
    echo "Database Status:\n";
    $result = $mysqli->query("SELECT DATABASE() as db_name");
    if ($result) {
        $row = $result->fetch_assoc();
        echo "  Current database: " . $row['db_name'] . "\n";
    }

    // List tables
    echo "\nTables in " . $db_name . ":\n";
    $tables = $mysqli->query("SHOW TABLES");
    if ($tables->num_rows > 0) {
        while ($row = $tables->fetch_array()) {
            echo "  - " . $row[0] . "\n";
        }
    } else {
        echo "  (No tables found - WordPress installation is required)\n";
    }

    // Test MySQL info
    echo "\nMySQL Information:\n";
    $result = $mysqli->query("SHOW VARIABLES LIKE 'socket'");
    if ($result && $row = $result->fetch_assoc()) {
        echo "  Socket: " . $row['Value'] . "\n";
    }

    $result = $mysqli->query("SHOW VARIABLES LIKE 'version'");
    if ($result && $row = $result->fetch_assoc()) {
        echo "  Version: " . $row['Value'] . "\n";
    }

    $result = $mysqli->query("SELECT USER()");
    if ($result && $row = $result->fetch_array()) {
        echo "  Current user: " . $row[0] . "\n";
    }

    // Check charset
    echo "\nCharset Information:\n";
    $result = $mysqli->query("SHOW VARIABLES LIKE 'character_set%'");
    while ($row = $result->fetch_assoc()) {
        echo "  " . $row['Variable_name'] . ": " . $row['Value'] . "\n";
    }

    $mysqli->close();
    echo "\n✓ All tests passed! Database connection is working.\n";

} catch (Exception $e) {
    echo "✗ Exception: " . $e->getMessage() . "\n";
    exit(1);
}
?>
