<?php

namespace DealNews\DB;

/**
 * Helper for doing CRUD operations with PDO
 *
 * This class provides helper functionality for working with PDO objects.
 *
 * @author      Brian Moon <brianm@dealnews.com>
 * @copyright   1997-Present DealNews.com, Inc
 * @package     DB
 */
class CRUD {

    /**
     * PDO Object
     * @var \DealNews\DB\PDO
     */
    protected $pdo;

    /**
     * Creates a new CRUD object
     *
     * @param \DealNews\DB\PDO $pdo PDO object
     */
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Getter for getting the pdo object
     *
     * @param  string $var Property name. Only `pdo` is allowed.
     * @return \PDO
     */
    public function __get($var) {
        if ($var == 'pdo') {
            return $this->pdo;
        } else {
            throw new \LogicException("Invalid property $var for " . get_class($this));
        }
    }

    /**
     * Inserts a new row into a table
     *
     * @param  string $table Table name
     * @param  array  $data  Array of fields and their values to insert
     * @return bool
     * @throws \PDOException
     */
    public function create(string $table, array $data): bool {
        $params = [];

        foreach ($data as $key => $value) {
            if (!is_scalar($value) && !is_null($value)) {
                throw new \LogicException("Invalid insert value for $key");
            }
            $params[":$key"] = $value;
        }

        $this->run(
            'INSERT INTO ' . $table . '
                    (' . implode(', ', array_keys($data)) . ')
                    VALUES
                    (:' . implode(', :', array_keys($data)) . ')',
            $params
        );

        return true;
    }

    /**
     * Reads row from a table
     *
     * @param      string  $table   The table name
     * @param      array   $data    Fields and values to use in the where clause
     * @param      int     $limit   Number of rows to return
     * @param      int     $start   Row to start at
     * @param      array   $fields  List of fields to return
     * @param      string  $order   Order by clause
     *
     * @return     array
     * @throws \PDOException
     */
    public function read(string $table, array $data = [], int $limit = null, int $start = null, array $fields = ['*'], string $order = ""): array {
        $row = [];

        $query = $this->buildSelectQuery($table, $data, $limit, $start, $fields, $order);

        $sth = $this->run(
            $query,
            $this->buildParameters($data)
        );

        if ($sth) {
            $row = $sth->fetchAll(\PDO::FETCH_ASSOC);
            if (empty($row)) {
                $row = [];
            }
        }

        return $row;
    }

    /**
     * Updates rows in a table
     *
     * @param  string $table Table name
     * @param  array  $data  Fields and values to update
     * @param  array  $where Fields and values to use in the where clause
     * @return bool
     * @throws \PDOException
     */
    public function update(string $table, array $data, array $where): bool {
        $this->run(
            'UPDATE ' . $table . ' SET ' .
            $this->buildUpdateClause($data) . ' ' .
            'WHERE ' . $this->buildWhereClause($where),
            array_merge(
                $this->buildParameters($data),
                $this->buildParameters($where)
            )
        );

        return true;
    }

    /**
     * Deletes rows from a table
     * @param  string $table Table name
     * @param  array  $data  Fields and values to use in the where clause
     * @return bool
     * @throws \PDOException
     */
    public function delete(string $table, array $data): bool {
        $this->run(
            'DELETE FROM ' . $table . ' WHERE ' . $this->buildWhereClause($data),
            $this->buildParameters($data)
        );

        return true;
    }

    /**
     * Prepares a query, executes it and returns the \PDOStatement
     * @param  string $query  Query to execute
     * @param  array  $params List of fields and values to bind to the query
     * @return PDOStatement
     * @throws \PDOException
     */
    public function run(string $query, array $params = []): PDOStatement {
        $sth = $this->pdo->prepare($query);

        $success = $sth->execute($params);

        return $sth;
    }

    /**
     * Builds a parameter list
     *
     * @param  array       $fields Array of fields and values
     * @param  int|integer $depth  Depth passed along during recursion
     * @return array
     */
    public function buildParameters(array $fields, int $depth = 0): array {
        $parameters = [];
        foreach ($fields as $field => $value) {
            if (!is_numeric($field) && $field != 'OR' && $field != 'AND') {
                if (is_array($value)) {
                    foreach ($value as $key => $val) {
                        $parameters[":{$field}{$key}{$depth}"] = $val;
                    }
                } else {
                    $parameters[":{$field}{$depth}"] = $value;
                }
            } elseif (is_array($value)) {
                if (count($fields) > 1 && ($field === 'OR' || $field === 'AND')) {
                    throw new \LogicException('Only one value allowed when AND/OR specified');
                }
                $depth++;
                $parameters = array_merge(
                    $parameters,
                    $this->buildParameters($value, $depth)
                );
            }
        }

        return $parameters;
    }

    /**
     * Builds a field list for a prepared statement and array of parameters
     * to bind to the query.
     *
     * @param  array  $fields      Array of fields and values
     * @param  string $conjunction Join string for the field list
     * @return string
     */
    public function buildWhereClause(array $fields, int $depth = 0): string {
        $conjunction = 'AND';
        $clauses     = [];

        if (isset($fields['OR']) || isset($fields['AND'])) {
            if (count($fields) > 1) {
                throw new \LogicException('Only one value allowed when AND/OR specified');
            }
            $conjunction = key($fields);
            $fields      = current($fields);
            $depth++;
        }

        foreach ($fields as $field => $value) {
            if (!is_numeric($field)) {
                if (is_scalar($value)) {
                    $clauses[] = "$field = :{$field}{$depth}";
                } elseif (is_array($value)) {
                    $field_clauses = [];
                    foreach ($value as $key => $val) {
                        $field_clauses[] = "$field = :{$field}{$key}{$depth}";
                    }
                    if (count($field_clauses) > 1) {
                        $clauses[] = '(' . implode(' OR ', $field_clauses) . ')';
                    } else {
                        $clauses[] = reset($field_clauses);
                    }
                } else {
                    throw new \InvalidArgumentException('Invalid field value ' . gettype($value), 1);
                }
            } elseif (is_array($value)) {
                $depth++;
                $clauses[] = $this->buildWhereClause($value, $depth);
            }
        }

        $where = '';
        if (!empty($clauses)) {
            $where = '(' . implode(" $conjunction ", $clauses) . ')';
        }

        return $where;
    }

    /**
     * Builds and update clause from an array of fields
     *
     * @param  array  $fields      Array of fields and values
     * @return string
     */
    public function buildUpdateClause(array $fields): string {
        $clauses = [];
        foreach ($fields as $field => $value) {
            if (is_numeric($field)) {
                throw new \LogicException("Invalid field name $field for update clause.", 1);
            }
            if (empty($field)) {
                throw new \LogicException("Invalid value for $field in update.", 2);
            }
            $clauses[] = "$field = :{$field}0";
        }

        return implode(', ', $clauses);
    }

    /**
     * Builds a select query.
     *
     * @param      string  $table   The table
     * @param      array   $data    The data
     * @param      int     $limit   The limit
     * @param      int     $start   The start
     * @param      array   $fields  The fields
     * @param      string  $order   The order
     *
     * @return     string  The select query.
     */
    public function buildSelectQuery(string $table, array $data = [], int $limit = null, int $start = null, array $fields = ['*'], string $order = ""): string {

        $query = 'SELECT ' . implode(', ', $fields) . ' FROM ' . $table;

        if (!empty($data)) {
            $query .= ' WHERE ' . $this->buildWhereClause($data);
        }

        if (!empty($order)) {
            $query .= ' ORDER BY ' . $order;
        }

        if (!empty($limit)) {
            if ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) == 'pgsql') {
                $query .= ' LIMIT ' . ((int)$limit);
                if (!empty($start)) {
                    $query .= ' OFFSET ' . ((int)$start);
                }
            } else {
                $query .= ' LIMIT';
                if (!empty($start)) {
                    $query .= ' ' . ((int)$start) . ',';
                }
                $query .= ' ' . ((int)$limit);
            }
        }

        return $query;
    }
}
