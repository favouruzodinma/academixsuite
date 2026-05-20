<?php
namespace AcademixSuite\Services;

class CrudHandler {
    private $db;
    private $schoolId;
    private $tableSchema = [];
    private static $schemaCache = [];

    public function __construct($schoolDb, $schoolId = null) {
        $this->db = $schoolDb;
        $this->schoolId = $schoolId;
    }

    public function getSchema($table) {
        $this->validateTableName($table);
        $cacheKey = $this->getDbName() . '.' . $table;
        if (isset(self::$schemaCache[$cacheKey])) {
            return self::$schemaCache[$cacheKey];
        }

        $stmt = $this->db->prepare("
            SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, 
                   COLUMN_DEFAULT, EXTRA, COLUMN_KEY, CHARACTER_MAXIMUM_LENGTH,
                   REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            LEFT JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                ON COLUMNS.COLUMN_NAME = KEY_COLUMN_USAGE.COLUMN_NAME
                AND COLUMNS.TABLE_NAME = KEY_COLUMN_USAGE.TABLE_NAME
                AND COLUMNS.TABLE_SCHEMA = KEY_COLUMN_USAGE.TABLE_SCHEMA
                AND KEY_COLUMN_USAGE.REFERENCED_TABLE_NAME IS NOT NULL
            WHERE COLUMNS.TABLE_SCHEMA = DATABASE() 
              AND COLUMNS.TABLE_NAME = ?
            ORDER BY COLUMNS.ORDINAL_POSITION
        ");
        $stmt->execute([$table]);
        $columns = $stmt->fetchAll();

        $schema = [
            'table' => $table,
            'columns' => [],
            'primary_key' => null,
            'foreign_keys' => [],
            'auto_increment' => null,
            'has_school_id' => false,
        ];

        foreach ($columns as $col) {
            $columnInfo = [
                'name' => $col['COLUMN_NAME'],
                'type' => $col['DATA_TYPE'],
                'full_type' => $col['COLUMN_TYPE'],
                'nullable' => $col['IS_NULLABLE'] === 'YES',
                'default' => $col['COLUMN_DEFAULT'],
                'max_length' => $col['CHARACTER_MAXIMUM_LENGTH'],
                'extra' => $col['EXTRA'],
                'is_key' => $col['COLUMN_KEY'],
            ];
            $schema['columns'][$col['COLUMN_NAME']] = $columnInfo;

            if ($col['COLUMN_KEY'] === 'PRI') {
                $schema['primary_key'] = $col['COLUMN_NAME'];
                if (strpos($col['EXTRA'], 'auto_increment') !== false) {
                    $schema['auto_increment'] = $col['COLUMN_NAME'];
                }
            }

            if ($col['REFERENCED_TABLE_NAME']) {
                $schema['foreign_keys'][$col['COLUMN_NAME']] = [
                    'table' => $col['REFERENCED_TABLE_NAME'],
                    'column' => $col['REFERENCED_COLUMN_NAME'],
                ];
            }

            if ($col['COLUMN_NAME'] === 'school_id') {
                $schema['has_school_id'] = true;
            }
        }

        self::$schemaCache[$cacheKey] = $schema;
        return $schema;
    }

    public function listAll($table, $params = []) {
        $this->validateTableName($table);
        $schema = $this->getSchema($table);
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = min(100, max(1, (int)($params['per_page'] ?? 25)));
        $search = $params['search'] ?? '';
        $sortBy = $params['sort_by'] ?? $schema['primary_key'] ?? 'id';
        $sortDir = strtoupper($params['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        if (!isset($schema['columns'][$sortBy])) {
            $sortBy = $schema['primary_key'] ?? 'id';
        }

        $where = '1=1';
        $bindings = [];

        if ($schema['has_school_id'] && $this->schoolId) {
            $where .= ' AND school_id = ?';
            $bindings[] = $this->schoolId;
        }

        $searchableColumns = [];
        foreach ($schema['columns'] as $name => $info) {
            if (in_array($info['type'], ['varchar', 'char', 'text', 'tinytext', 'mediumtext', 'longtext', 'enum'])) {
                $searchableColumns[] = $name;
            }
        }

        if ($search && !empty($searchableColumns)) {
            $conditions = [];
            foreach ($searchableColumns as $col) {
                $conditions[] = "$col LIKE ?";
                $bindings[] = "%{$search}%";
            }
            $where .= ' AND (' . implode(' OR ', $conditions) . ')';
        }

        $countSql = "SELECT COUNT(*) as total FROM `$table` WHERE $where";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($bindings);
        $total = (int)$countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $sql = "SELECT * FROM `$table` WHERE $where ORDER BY `$sortBy` $sortDir LIMIT $perPage OFFSET $offset";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($bindings);
        $rows = $stmt->fetchAll();

        return [
            'data' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage),
            'schema' => $schema,
        ];
    }

    public function get($table, $id) {
        $this->validateTableName($table);
        $schema = $this->getSchema($table);
        $pk = $schema['primary_key'] ?? 'id';

        $where = "$pk = ?";
        $bindings = [$id];

        if ($schema['has_school_id'] && $this->schoolId) {
            $where .= ' AND school_id = ?';
            $bindings[] = $this->schoolId;
        }

        $sql = "SELECT * FROM `$table` WHERE $where LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($bindings);
        $row = $stmt->fetch();

        if (!$row) return null;

        return [
            'data' => $row,
            'schema' => $schema,
        ];
    }

    public function create($table, $data) {
        $this->validateTableName($table);
        $schema = $this->getSchema($table);
        $allowed = $this->filterColumns($schema, $data);

        if ($schema['has_school_id'] && $this->schoolId && !isset($allowed['school_id'])) {
            $allowed['school_id'] = $this->schoolId;
        }

        if (isset($allowed[$schema['auto_increment'] ?? '__none__'])) {
            unset($allowed[$schema['auto_increment']]);
        }

        foreach ($allowed as $key => $value) {
            if ($value === '' && $schema['columns'][$key]['nullable']) {
                $allowed[$key] = null;
            }
        }

        $columns = implode('`, `', array_keys($allowed));
        $placeholders = implode(', ', array_fill(0, count($allowed), '?'));

        $sql = "INSERT INTO `$table` (`$columns`) VALUES ($placeholders)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_values($allowed));

        return [
            'success' => true,
            'id' => (int)$this->db->lastInsertId(),
            'message' => 'Record created successfully',
        ];
    }

    public function update($table, $id, $data) {
        $this->validateTableName($table);
        $schema = $this->getSchema($table);
        $pk = $schema['primary_key'] ?? 'id';
        $allowed = $this->filterColumns($schema, $data);

        unset($allowed[$pk]);
        unset($allowed[$schema['auto_increment'] ?? '__none__']);

        foreach ($allowed as $key => $value) {
            if ($value === '' && $schema['columns'][$key]['nullable']) {
                $allowed[$key] = null;
            }
        }

        $setParts = [];
        $bindings = [];
        foreach ($allowed as $col => $val) {
            $setParts[] = "`$col` = ?";
            $bindings[] = $val;
        }

        $where = "$pk = ?";
        $bindings[] = $id;

        if ($schema['has_school_id'] && $this->schoolId) {
            $where .= ' AND school_id = ?';
            $bindings[] = $this->schoolId;
        }

        $sql = "UPDATE `$table` SET " . implode(', ', $setParts) . " WHERE $where";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($bindings);

        return [
            'success' => true,
            'affected' => $stmt->rowCount(),
            'message' => 'Record updated successfully',
        ];
    }

    public function delete($table, $id) {
        $this->validateTableName($table);
        $schema = $this->getSchema($table);
        $pk = $schema['primary_key'] ?? 'id';

        $where = "$pk = ?";
        $bindings = [$id];

        if ($schema['has_school_id'] && $this->schoolId) {
            $where .= ' AND school_id = ?';
            $bindings[] = $this->schoolId;
        }

        $sql = "DELETE FROM `$table` WHERE $where";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($bindings);

        return [
            'success' => true,
            'affected' => $stmt->rowCount(),
            'message' => 'Record deleted successfully',
        ];
    }

    public function getRelatedData($table) {
        $this->validateTableName($table);
        $schema = $this->getSchema($table);
        $related = [];

        foreach ($schema['foreign_keys'] as $fkCol => $fkInfo) {
            $refTable = $fkInfo['table'];
            $refCol = $fkInfo['column'];
            try {
                $refSchema = $this->getSchema($refTable);
                $labelCol = $this->getLabelColumn($refSchema);

                $where = '';
                $bindings = [];
                if ($refSchema['has_school_id'] && $this->schoolId) {
                    $where = 'WHERE school_id = ?';
                    $bindings[] = $this->schoolId;
                }

                $sql = "SELECT `$refCol` as value, `$labelCol` as label FROM `$refTable` $where ORDER BY `$labelCol`";
                $stmt = $this->db->prepare($sql);
                $stmt->execute($bindings);

                $related[$fkCol] = [
                    'column' => $fkCol,
                    'references' => $refTable . '.' . $refCol,
                    'options' => $stmt->fetchAll(),
                ];
            } catch (\Exception $e) {
                $related[$fkCol] = [
                    'column' => $fkCol,
                    'references' => $refTable . '.' . $refCol,
                    'options' => [],
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $related;
    }

    public function getTables() {
        $stmt = $this->db->prepare("
            SELECT TABLE_NAME 
            FROM INFORMATION_SCHEMA.TABLES 
            WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_TYPE = 'BASE TABLE'
            ORDER BY TABLE_NAME
        ");
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function getDisplayInfo($table) {
        $this->validateTableName($table);
        $displayNames = [
            'academic_terms' => 'Academic Terms',
            'academic_years' => 'Academic Years',
            'announcements' => 'Announcements',
            'attendance' => 'Attendance Records',
            'classes' => 'Classes',
            'class_subjects' => 'Class Subjects',
            'events' => 'Events',
            'exams' => 'Exams',
            'exam_grades' => 'Exam Grades',
            'fee_categories' => 'Fee Categories',
            'fee_structures' => 'Fee Structures',
            'guardians' => 'Guardians',
            'homework' => 'Homework',
            'invoices' => 'Invoices',
            'invoice_items' => 'Invoice Items',
            'payments' => 'Payments',
            'roles' => 'Roles',
            'sections' => 'Sections',
            'settings' => 'Settings',
            'students' => 'Students',
            'subjects' => 'Subjects',
            'teachers' => 'Teachers',
            'timetables' => 'Timetables',
            'users' => 'Users',
            'user_roles' => 'User Roles',
        ];
        return $displayNames[$table] ?? ucwords(str_replace('_', ' ', $table));
    }

    private function filterColumns($schema, $data) {
        $allowed = [];
        foreach ($data as $key => $value) {
            if (isset($schema['columns'][$key])) {
                $allowed[$key] = $value;
            }
        }
        return $allowed;
    }

    private function getLabelColumn($schema) {
        $priority = ['name', 'title', 'first_name', 'username', 'email', 'code', 'slug', 'id'];
        foreach ($priority as $col) {
            if (isset($schema['columns'][$col])) {
                return $col;
            }
        }
        return $schema['primary_key'] ?? 'id';
    }

    private function validateTableName(string $table): void {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException("Invalid table name: " . $table);
        }
    }

    private function getDbName() {
        $stmt = $this->db->prepare("SELECT DATABASE()");
        $stmt->execute();
        return $stmt->fetchColumn();
    }
}
