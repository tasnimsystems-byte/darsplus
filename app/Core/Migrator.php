<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Migration files: database/migrations/NNN_name.php, each returning a list of SQL statements.
 * They are applied in filename order and recorded in the `migrations` table.
 * DDL auto-commits in MySQL/MariaDB, so a failed migration must be fixed forward.
 */
final class Migrator
{
    public function __construct(private Database $db, private string $dir)
    {
    }

    /** @return string[] names of the migrations that were applied */
    public function migrate(): array
    {
        $this->db->run(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(190) NOT NULL UNIQUE,
                ran_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $done = $this->db->column('SELECT migration FROM migrations');
        $files = glob($this->dir . '/*.php') ?: [];
        sort($files);

        $applied = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (in_array($name, $done, true)) {
                continue;
            }
            foreach (require $file as $sql) {
                $this->db->pdo()->exec($sql);
            }
            $this->db->insert('migrations', ['migration' => $name]);
            $applied[] = $name;
        }
        return $applied;
    }

    /** Drops every table. Refuses to run outside local/testing environments. */
    public function fresh(): array
    {
        if (!in_array(App::config('app.env'), ['local', 'testing'], true)) {
            throw new \RuntimeException('migrate:fresh is only allowed when APP_ENV is local or testing.');
        }
        $pdo = $this->db->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->db->column('SHOW TABLES') as $table) {
            $pdo->exec('DROP TABLE `' . str_replace('`', '', (string) $table) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        return $this->migrate();
    }
}
