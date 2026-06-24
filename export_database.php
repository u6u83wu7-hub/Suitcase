<?php
// Database exporter for all_pass_db.

$isCli = (PHP_SAPI === 'cli');

function exportFail($message, $statusCode = 500)
{
    global $isCli;

    if ($isCli) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }

    http_response_code($statusCode);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

set_time_limit(0);
date_default_timezone_set('Asia/Taipei');

$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'all_pass_db';
$outputDir = __DIR__ . DIRECTORY_SEPARATOR . 'backups';
$outputFile = $isCli
    ? $outputDir . DIRECTORY_SEPARATOR . $dbname . '_' . date('Ymd_His') . '.sql'
    : $dbname . '_' . date('Ymd_His') . '.sql';
$rowsPerInsert = 200;

if ($isCli && !is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
    exportFail("Unable to create backup directory: {$outputDir}");
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    exportFail("Database connection failed: " . $conn->connect_error);
}

$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+08:00'");

if (!$isCli) {
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($outputFile) . '"');
}

$handle = fopen($isCli ? $outputFile : 'php://output', 'wb');
if ($handle === false) {
    exportFail("Unable to open output file: {$outputFile}");
    $conn->close();
}

function exportQuoteValue(mysqli $conn, $value)
{
    if ($value === null) {
        return 'NULL';
    }

    return "'" . $conn->real_escape_string((string)$value) . "'";
}

function exportWriteInsertBatch($handle, mysqli $conn, $tableName, array $columns, array $rows)
{
    if (empty($rows)) {
        return;
    }

    $columnList = implode(', ', array_map(static function ($column) {
        return '`' . str_replace('`', '``', $column) . '`';
    }, $columns));

    fwrite($handle, "INSERT INTO `{$tableName}` ({$columnList}) VALUES\n");

    $valueLines = [];
    foreach ($rows as $row) {
        $values = [];
        foreach ($columns as $column) {
            $values[] = exportQuoteValue($conn, $row[$column] ?? null);
        }
        $valueLines[] = '(' . implode(', ', $values) . ')';
    }

    fwrite($handle, implode(",\n", $valueLines) . ";\n\n");
}

fwrite($handle, "-- Database export generated on " . date('Y-m-d H:i:s') . "\n");
fwrite($handle, "-- Database: {$dbname}\n\n");
fwrite($handle, "SET NAMES utf8mb4;\n");
fwrite($handle, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

$tablesResult = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
if (!$tablesResult) {
    exportFail("Unable to list tables: " . $conn->error);
    fclose($handle);
    $conn->close();
}

$tableCount = 0;
$rowCount = 0;

while ($tableRow = $tablesResult->fetch_array(MYSQLI_NUM)) {
    $tableName = $tableRow[0];
    $tableCount++;

    fwrite($handle, "-- --------------------------------------------------------\n");
    fwrite($handle, "-- Table structure for table `{$tableName}`\n");
    fwrite($handle, "-- --------------------------------------------------------\n\n");
    fwrite($handle, "DROP TABLE IF EXISTS `{$tableName}`;\n");

    $createResult = $conn->query("SHOW CREATE TABLE `{$tableName}`");
    if (!$createResult) {
        fclose($handle);
        $conn->close();
        exportFail("Unable to read CREATE TABLE for {$tableName}: " . $conn->error);
    }

    $createRow = $createResult->fetch_assoc();
    $createSql = $createRow['Create Table'] ?? null;
    if ($createSql === null) {
        fclose($handle);
        $conn->close();
        exportFail("Missing CREATE TABLE SQL for {$tableName}.");
    }

    fwrite($handle, $createSql . ";\n\n");

    $dataResult = $conn->query("SELECT * FROM `{$tableName}`");
    if (!$dataResult) {
        fclose($handle);
        $conn->close();
        exportFail("Unable to read data from {$tableName}: " . $conn->error);
    }

    if ($dataResult->num_rows === 0) {
        fwrite($handle, "-- No data in table `{$tableName}`\n\n");
        continue;
    }

    $columns = [];
    $buffer = [];
    while ($dataRow = $dataResult->fetch_assoc()) {
        if (empty($columns)) {
            $columns = array_keys($dataRow);
        }

        $buffer[] = $dataRow;
        $rowCount++;

        if (count($buffer) >= $rowsPerInsert) {
            exportWriteInsertBatch($handle, $conn, $tableName, $columns, $buffer);
            $buffer = [];
        }
    }

    if (!empty($buffer)) {
        exportWriteInsertBatch($handle, $conn, $tableName, $columns, $buffer);
    }
}

fwrite($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");

fclose($handle);
$conn->close();

if ($isCli) {
    echo "Export completed.\n";
    echo "Tables exported: {$tableCount}\n";
    echo "Rows exported: {$rowCount}\n";
    echo "Output file: {$outputFile}\n";
}

exit(0);