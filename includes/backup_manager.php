<?php
// includes/backup_manager.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../setup/migrations.php';

class BackupManager {
    private $pdo;
    private $backupDir;
    private $dbName;
    private $dbUser;
    private $dbPass;

    public function __construct($pdo, $backupDir = __DIR__ . '/../backups/') {
        $this->pdo = $pdo;
        $this->backupDir = $backupDir;
        $this->dbName = DB_NAME;
        $this->dbUser = DB_USERNAME;
        $this->dbPass = DB_PASSWORD;

        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0777, true);
        }
    }

    public function createBackup() {
        $date = date('Y-m-d_H-i-s');
        $filename = "backup_{$date}.sql";
        $filepath = $this->backupDir . $filename;

        // Since we are running on XAMPP Windows usually, mysqldump might not be globally in PATH.
        // For a robust pure PHP approach, we manually dump the tables.
        try {
            $tables = [];
            $stmt = $this->pdo->query("SHOW TABLES");
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                $tables[] = $row[0];
            }

            $sqlScript = "-- FinEase Database Backup\n";
            $sqlScript .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
            $sqlScript .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

            foreach ($tables as $table) {
                // Determine CREATE TABLE syntax
                $stmt = $this->pdo->query("SHOW CREATE TABLE `$table`");
                $row = $stmt->fetch(PDO::FETCH_NUM);
                
                $sqlScript .= "DROP TABLE IF EXISTS `$table`;\n";
                $sqlScript .= $row[1] . ";\n\n";

                // Get Table Data
                $stmt = $this->pdo->query("SELECT * FROM `$table`");
                $rowCount = $stmt->rowCount();

                if ($rowCount > 0) {
                    $sqlScript .= "INSERT INTO `$table` VALUES\n";
                    $dataRows = [];
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $values = [];
                        foreach ($row as $value) {
                            if (is_null($value)) {
                                $values[] = "NULL";
                            } else {
                                // Escape single quotes
                                $escapedValue = str_replace("'", "''", $value);
                                $values[] = "'$escapedValue'";
                            }
                        }
                        $dataRows[] = "(" . implode(", ", $values) . ")";
                    }
                    $sqlScript .= implode(",\n", $dataRows) . ";\n\n";
                }
            }

            $sqlScript .= "SET FOREIGN_KEY_CHECKS=1;\n";

            file_put_contents($filepath, $sqlScript);
            return ['success' => true, 'filename' => $filename];

        } catch (PDOException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getBackups() {
        $files = glob($this->backupDir . '*.sql');
        $backups = [];
        foreach ($files as $file) {
            $backups[] = [
                'filename' => basename($file),
                'filepath' => $file,
                'size' => filesize($file),
                'date' => filemtime($file)
            ];
        }
        // Sort newest first
        usort($backups, function($a, $b) {
            return $b['date'] - $a['date'];
        });
        return $backups;
    }

    public function restoreBackup($filepath) {
        if (!file_exists($filepath)) {
            return ['success' => false, 'message' => 'Backup file does not exist.'];
        }

        try {
            $sql = file_get_contents($filepath);
            
            // Execute the huge SQL dump safely by disabling foreign keys first
            $this->pdo->exec("SET FOREIGN_KEY_CHECKS=0;");
            
            // Fetch all lines
            // Fetch all lines
            $lines = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            // Normalize MySQL 8.0+ collations to MySQL 5.7 compatible equivalents
            // Needed when restoring backups from newer servers onto older XAMPP installs
            $lines = array_map(function($line) {
                $line = str_replace('utf8mb4_0900_ai_ci', 'utf8mb4_unicode_ci', $line);
                $line = str_replace('utf8mb4_0900_as_cs', 'utf8mb4_unicode_ci', $line);
                $line = str_replace('utf8mb4_0900_as_ci', 'utf8mb4_unicode_ci', $line);
                $line = str_replace('COLLATE utf8mb4_general_ci', 'COLLATE utf8mb4_unicode_ci', $line);
                $line = str_replace('charset=utf8mb4_0900_ai_ci', 'charset=utf8mb4', $line);
                return $line;
            }, $lines);

            $query = '';
            
            foreach ($lines as $line) {
                // Skip SQL comments
                if (substr($line, 0, 2) == '--' || $line == '') {
                    continue;
                }
                
                $query .= $line . "\n";
                
                // If it ends with a semicolon, execute it
                if (substr(trim($line), -1, 1) == ';') {
                    if (!empty(trim($query))) {
                        $this->pdo->exec($query);
                    }
                    $query = '';
                }
            }
            
            $this->pdo->exec("SET FOREIGN_KEY_CHECKS=1;");
            
            // Post-Restore: Ensure the latest v3.0 columns exist (Safe against old backups)
            $migrationResult = apply_v3_migrations($this->pdo);

            return ['success' => true, 'message' => 'Database restored successfully. Migration check: ' . $migrationResult['message']];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Restore failed: ' . $e->getMessage()];
        }
    }

    public function deleteBackup($filename) {
        $filepath = $this->backupDir . basename($filename);
        if (file_exists($filepath)) {
            // Remove error suppression to expose real problem if it exists
            return unlink($filepath);
        }
        return false;
    }
}
?>
