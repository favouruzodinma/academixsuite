<?php
/**
 * Campus context helpers for school-admin pages.
 */

if (!defined('ACADEMIX_SCHOOL_ADMIN_BOOTSTRAPPED')) {
    require_once __DIR__ . '/admin-bootstrap.php';
}

if (!function_exists('academix_admin_campus_code')) {
    function academix_admin_campus_code(string $value): string {
        $code = strtoupper(preg_replace('/[^A-Z0-9]+/i', '-', trim($value)));
        $code = trim($code, '-');
        return $code !== '' ? substr($code, 0, 50) : 'MAIN';
    }
}

if (!function_exists('academix_admin_ensure_campuses')) {
    function academix_admin_ensure_campuses(PDO $db, array $school): void {
        try {
            if (!academix_admin_table_exists($db, 'campuses')) {
                $db->exec("
                    CREATE TABLE IF NOT EXISTS `campuses` (
                        `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                        `school_id` int(10) UNSIGNED NOT NULL,
                        `name` varchar(100) NOT NULL,
                        `code` varchar(50) NOT NULL,
                        `address` text DEFAULT NULL,
                        `city` varchar(100) DEFAULT NULL,
                        `state` varchar(100) DEFAULT NULL,
                        `country` varchar(100) DEFAULT NULL,
                        `phone` varchar(20) DEFAULT NULL,
                        `email` varchar(255) DEFAULT NULL,
                        `latitude` decimal(10,8) DEFAULT NULL,
                        `longitude` decimal(11,8) DEFAULT NULL,
                        `radius` int(10) UNSIGNED DEFAULT NULL,
                        `is_active` tinyint(1) DEFAULT 1,
                        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                        PRIMARY KEY (`id`),
                        UNIQUE KEY `unique_campus_code` (`school_id`,`code`),
                        KEY `idx_school` (`school_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }

            $stmt = $db->prepare('SELECT COUNT(*) FROM campuses WHERE school_id = ?');
            $stmt->execute([(int) $school['id']]);
            if ((int) $stmt->fetchColumn() > 0) {
                return;
            }

            $name = trim((string) ($school['name'] ?? 'Main Campus'));
            $stmt = $db->prepare("
                INSERT INTO campuses
                    (school_id, name, code, address, city, state, country, phone, email, is_active, created_at, updated_at)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
            ");
            $stmt->execute([
                (int) $school['id'],
                $name !== '' ? $name : 'Main Campus',
                academix_admin_campus_code($name !== '' ? $name : 'Main Campus'),
                $school['address'] ?? null,
                $school['city'] ?? null,
                $school['state'] ?? null,
                $school['country'] ?? null,
                $school['phone'] ?? null,
                $school['email'] ?? null,
            ]);
        } catch (Throwable $e) {
            error_log('Campus bootstrap failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('academix_admin_get_campuses')) {
    function academix_admin_get_campuses(PDO $db, array $school, bool $activeOnly = true): array {
        academix_admin_ensure_campuses($db, $school);

        try {
            $sql = 'SELECT * FROM campuses WHERE school_id = ?';
            if ($activeOnly) {
                $sql .= ' AND is_active = 1';
            }
            $sql .= ' ORDER BY is_active DESC, name ASC';
            $stmt = $db->prepare($sql);
            $stmt->execute([(int) $school['id']]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('Campus list failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('academix_admin_resolve_campus_id')) {
    function academix_admin_resolve_campus_id(PDO $db, array $school, bool $allowAll = false): int {
        $campuses = academix_admin_get_campuses($db, $school, true);
        $validIds = array_map(static fn($campus) => (int) $campus['id'], $campuses);

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $raw = $_POST['campus_id'] ?? ($_GET['campus_id'] ?? ($_SESSION['school_auth']['campus_id'] ?? 0));
        } else {
            $raw = $_GET['campus_id'] ?? ($_SESSION['school_auth']['campus_id'] ?? 0);
        }
        $selected = (int) $raw;

        if ($allowAll && array_key_exists('campus_id', $_GET) && $selected === 0) {
            return 0;
        }

        if (!in_array($selected, $validIds, true)) {
            $selected = (int) ($validIds[0] ?? 0);
        }

        if ($selected > 0) {
            $_SESSION['school_auth']['campus_id'] = $selected;
        }

        return $selected;
    }
}

if (!function_exists('academix_admin_campus_name')) {
    function academix_admin_campus_name(array $campuses, int $campusId): string {
        foreach ($campuses as $campus) {
            if ((int) ($campus['id'] ?? 0) === $campusId) {
                return (string) ($campus['name'] ?? 'Campus');
            }
        }
        return $campusId > 0 ? 'Campus #' . $campusId : 'All campuses';
    }
}

if (!function_exists('academix_admin_fresh_columns')) {
    function academix_admin_fresh_columns(PDO $db, string $table): array {
        try {
            $safeTable = str_replace('`', '', $table);
            $rows = $db->query("SHOW COLUMNS FROM `{$safeTable}`")->fetchAll(PDO::FETCH_ASSOC);
            return array_column($rows, 'Field');
        } catch (Throwable $e) {
            error_log('Fresh column check failed for ' . $table . ': ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('academix_admin_ensure_transactions_table')) {
    function academix_admin_ensure_transactions_table(PDO $db): void {
        try {
            if (!academix_admin_table_exists($db, 'transactions')) {
                $db->exec("
                    CREATE TABLE IF NOT EXISTS `transactions` (
                        `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                        `school_id` int(10) UNSIGNED NOT NULL,
                        `campus_id` int(10) UNSIGNED DEFAULT NULL,
                        `type` varchar(20) NOT NULL DEFAULT 'income',
                        `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
                        `description` text DEFAULT NULL,
                        `category` varchar(120) DEFAULT NULL,
                        `payment_method` varchar(60) DEFAULT NULL,
                        `reference` varchar(160) DEFAULT NULL,
                        `date` date DEFAULT NULL,
                        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                        `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
                        PRIMARY KEY (`id`),
                        KEY `idx_school` (`school_id`),
                        KEY `idx_campus` (`campus_id`),
                        KEY `idx_type` (`type`),
                        KEY `idx_date` (`date`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
                return;
            }

            $columns = academix_admin_fresh_columns($db, 'transactions');
            $required = [
                'campus_id' => "`campus_id` int(10) UNSIGNED DEFAULT NULL AFTER `school_id`",
                'type' => "`type` varchar(20) NOT NULL DEFAULT 'income'",
                'amount' => "`amount` decimal(12,2) NOT NULL DEFAULT 0.00",
                'description' => "`description` text DEFAULT NULL",
                'category' => "`category` varchar(120) DEFAULT NULL",
                'payment_method' => "`payment_method` varchar(60) DEFAULT NULL",
                'reference' => "`reference` varchar(160) DEFAULT NULL",
                'date' => "`date` date DEFAULT NULL",
                'created_at' => "`created_at` timestamp NOT NULL DEFAULT current_timestamp()",
                'updated_at' => "`updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()",
            ];

            foreach ($required as $column => $definition) {
                if (!in_array($column, $columns, true)) {
                    $db->exec("ALTER TABLE `transactions` ADD COLUMN {$definition}");
                }
            }
        } catch (Throwable $e) {
            error_log('Transaction table preparation failed: ' . $e->getMessage());
        }
    }
}
