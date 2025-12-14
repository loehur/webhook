<?php
require_once 'app/Config/DBC.php';

class DB extends DBC
{
    private static $_instance = [];
    private $mysqli;
    private $query_result;

    // Query Builder Props
    private $qb_table = "";
    private $qb_where = [];
    private $qb_params = [];
    private $qb_types = ""; // s, i, d, b

    public function __construct($db = 0)
    {
        // Simple singleton logic for connections could be here or handled by getInstance
        $db_name = DBC::dbm[$db]['db'];
        $db_user = DBC::dbm[$db]['user'];
        $db_pass = DBC::dbm[$db]['pass'];

        $this->mysqli = new mysqli(DBC::db_host, $db_user, $db_pass, $db_name);

        if ($this->mysqli->connect_error) {
            die('Connect Error (' . $this->mysqli->connect_errno . ') ' . $this->mysqli->connect_error);
        }

        $this->mysqli->set_charset("utf8mb4");
    }

    public static function getInstance($db = 0)
    {
        if (!isset(self::$_instance[$db])) {
            self::$_instance[$db] = new DB($db);
        }
        return self::$_instance[$db];
    }

    /**
     * Raw Query with Optional Params (Prepared Statement)
     * Usage: $this->db()->query("SELECT * FROM table WHERE id = ?", [1]);
     */
    public function query($sql, $params = [])
    {
        $this->reset_qb(); // Clear builder state on raw query

        $stmt = $this->mysqli->prepare($sql);

        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->mysqli->error);
        }

        if (!empty($params)) {
            $types = "";
            foreach ($params as $param) {
                if (is_int($param)) $types .= "i";
                elseif (is_float($param)) $types .= "d";
                else $types .= "s";
            }
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }

        $this->query_result = $stmt->get_result();
        // If query was INSERT/UPDATE/DELETE, get_result returns false, which is fine
        // We can capture affected_rows or insert_id here if needed but for now we focus on SELECT compatibility

        return $this; // Return self for chaining result methods
    }
    
    // --- Query Builder Methods ---

    /**
     * Get Where
     * Usage: get_where('table', ['id' => 1])
     */
    public function get_where($table, $where = [], $limit = null)
    {
        $this->reset_qb();
        $this->qb_table = $table;

        $sql = "SELECT * FROM " . $table;
        $params = [];
        $types = "";

        if (!empty($where)) {
            $clauses = [];
            foreach ($where as $key => $val) {
                // Determine operator
                $op = "=";
                // Basic support for "key !=" => val syntax could be added, but keeping simple for now: "key" => val

                $clauses[] = "$key = ?";
                $params[] = $val;

                if (is_int($val)) $types .= "i";
                elseif (is_float($val)) $types .= "d";
                else $types .= "s";
            }
            $sql .= " WHERE " . implode(" AND ", $clauses);
        }

        if ($limit) {
            $sql .= " LIMIT ?";
            $params[] = $limit;
            $types .= "i";
        }

        $stmt = $this->mysqli->prepare($sql);
        if (!$stmt) throw new Exception("DB Error: " . $this->mysqli->error);

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $this->query_result = $stmt->get_result();

        return $this;
    }

    /**
     * Insert
     * Usage: insert('table', ['col' => 'val'])
     */
    public function insert($table, $data)
    {
        // $data must be associative array
        $cols = array_keys($data);
        $vals = array_values($data);

        $placeholders = array_fill(0, count($cols), "?");

        $sql = "INSERT INTO $table (" . implode(", ", $cols) . ") VALUES (" . implode(", ", $placeholders) . ")";

        $types = "";
        foreach ($vals as $val) {
            if (is_int($val)) $types .= "i";
            elseif (is_float($val)) $types .= "d";
            else $types .= "s";
        }

        $stmt = $this->mysqli->prepare($sql);
        if (!$stmt) return false;

        $stmt->bind_param($types, ...$vals);

        if ($stmt->execute()) {
            return $this->mysqli->insert_id;
        } else {
            return false;
        }
    }

    /**
     * Update
     * Usage: update('table', ['col' => 'newval'], ['id' => 1])
     */
    public function update($table, $data, $where)
    {
        $set_clauses = [];
        $params = [];
        $types = "";

        foreach ($data as $key => $val) {
            $set_clauses[] = "$key = ?";
            $params[] = $val;
            if (is_int($val)) $types .= "i";
            elseif (is_float($val)) $types .= "d";
            else $types .= "s";
        }

        // Where clauses
        $where_clauses = [];
        foreach ($where as $key => $val) {
            $where_clauses[] = "$key = ?";
            $params[] = $val;
            if (is_int($val)) $types .= "i";
            elseif (is_float($val)) $types .= "d";
            else $types .= "s";
        }

        $sql = "UPDATE $table SET " . implode(", ", $set_clauses) . " WHERE " . implode(" AND ", $where_clauses);

        $stmt = $this->mysqli->prepare($sql);
        if (!$stmt) return false;

        $stmt->bind_param($types, ...$params);

        return $stmt->execute();
    }

    /**
     * Update with Limit
     * Usage: update_limit('table', ['col' => 'newval'], ['id' => 1], 5)
     */
    public function update_limit($table, $data, $where, $limit = 1)
    {
        $set_clauses = [];
        $params = [];
        $types = "";

        foreach ($data as $key => $val) {
            $set_clauses[] = "$key = ?";
            $params[] = $val;
            if (is_int($val)) $types .= "i";
            elseif (is_float($val)) $types .= "d";
            else $types .= "s";
        }

        // Where clauses
        $where_clauses = [];
        foreach ($where as $key => $val) {
            $where_clauses[] = "$key = ?";
            $params[] = $val;
            if (is_int($val)) $types .= "i";
            elseif (is_float($val)) $types .= "d";
            else $types .= "s";
        }

        $sql = "UPDATE $table SET " . implode(", ", $set_clauses) . " WHERE " . implode(" AND ", $where_clauses) . " LIMIT ?";

        // Add limit to params
        $params[] = $limit;
        $types .= "i";

        $stmt = $this->mysqli->prepare($sql);
        if (!$stmt) return false;

        $stmt->bind_param($types, ...$params);

        return $stmt->execute();
    }

    /**
     * Delete
     * Usage: delete('table', ['id' => 1])
     */
    public function delete($table, $where)
    {
        $where_clauses = [];
        $params = [];
        $types = "";

        foreach ($where as $key => $val) {
            $where_clauses[] = "$key = ?";
            $params[] = $val;
            if (is_int($val)) $types .= "i";
            elseif (is_float($val)) $types .= "d";
            else $types .= "s";
        }

        $sql = "DELETE FROM $table WHERE " . implode(" AND ", $where_clauses);

        $stmt = $this->mysqli->prepare($sql);
        if (!$stmt) return false;

        $stmt->bind_param($types, ...$params);

        return $stmt->execute();
    }

    /**
     * Delete with Limit
     * Usage: delete_limit('table', ['id' => 1], 5)
     */
    public function delete_limit($table, $where, $limit = 1)
    {
        $where_clauses = [];
        $params = [];
        $types = "";

        foreach ($where as $key => $val) {
            $where_clauses[] = "$key = ?";
            $params[] = $val;
            if (is_int($val)) $types .= "i";
            elseif (is_float($val)) $types .= "d";
            else $types .= "s";
        }

        $sql = "DELETE FROM $table WHERE " . implode(" AND ", $where_clauses) . " LIMIT ?";

        // Add limit to params
        $params[] = $limit;
        $types .= "i";

        $stmt = $this->mysqli->prepare($sql);
        if (!$stmt) return false;

        $stmt->bind_param($types, ...$params);

        return $stmt->execute();
    }

    // --- Result Methods (Fluent) ---

    public function row()
    {
        if ($this->query_result) {
            return $this->query_result->fetch_object();
        }
        return null;
    }

    public function row_array()
    {
        if ($this->query_result) {
            return $this->query_result->fetch_assoc();
        }
        return null;
    }

    public function result()
    {
        $rows = [];
        if ($this->query_result) {
            while ($row = $this->query_result->fetch_object()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    public function result_array()
    {
        $rows = [];
        if ($this->query_result) {
            while ($row = $this->query_result->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    public function num_rows()
    {
        if ($this->query_result) {
            return $this->query_result->num_rows;
        }
        return 0;
    }

    // Internal Helper
    private function reset_qb()
    {
        $this->qb_table = "";
        $this->qb_where = [];
        $this->qb_params = [];
        $this->qb_types = "";
        $this->query_result = null;
    }

    // Helper to get raw connection if needed
    public function conn()
    {
        return $this->mysqli;
    }
}
