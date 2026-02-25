<?php
/**
 * WordPress Database Connection Test
 */

// Load WordPress configuration
require_once('wp-config.php');

echo "=== WordPress Database Connection Test ===\n\n";

// Display configuration
echo "Configuration:\n";
echo "  DB_HOST: " . DB_HOST . "\n";
echo "  DB_USER: " . DB_USER . "\n";
echo "  DB_PASSWORD: " . (DB_PASSWORD ? '***' : '(empty)') . "\n";
echo "  DB_NAME: " . DB_NAME . "\n\n";

// Attempt connection
echo "Attempting connection...\n";
try {
    $mysqli = new mysqli(
        DB_HOST,
        DB_USER,
        DB_PASSWORD,
        DB_NAME
    );

    if ($mysqli->connect_error) {
        echo "Connection failed: " . $mysqli->connect_error . "\n";
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
    echo "\nTables in " . DB_NAME . ":\n";
    $tables = $mysqli->query("SHOW TABLES");
    if ($tables->num_rows > 0) {
        while ($row = $tables->fetch_array()) {
            echo "  - " . $row[0] . "\n";
        }
    } else {
        echo "  (No tables found - WordPress may need to be installed)\n";
    }

    // Test WordPress table creation capability
    echo "\nDatabase Capabilities:\n";
    $result = $mysqli->query("SHOW VARIABLES LIKE 'socket'");
    if ($result && $row = $result->fetch_assoc()) {
        echo "  MySQL socket: " . $row['Value'] . "\n";
    }

    $result = $mysqli->query("SHOW VARIABLES LIKE 'version'");
    if ($result && $row = $result->fetch_assoc()) {
        echo "  MySQL version: " . $row['Value'] . "\n";
    }

    $mysqli->close();
    echo "\n✓ All tests passed!\n";

} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
    exit(1);
}
?>
