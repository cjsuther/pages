<?php

namespace Tests\Support;

/**
 * Sentencia preparada falsa. Devuelve las filas que le cargó FakePdo y
 * registra los parámetros con los que se ejecutó, para poder afirmar sobre
 * ellos en los tests.
 */
class FakeStatement
{
    /** @var string */
    public $sql;

    /** @var array */
    public $params = [];

    /** @var array Filas pendientes de consumir */
    private $rows;

    /** @var int */
    private $rowCount;

    /** @var FakePdo */
    private $pdo;

    /** @var bool */
    private $executed = false;

    /** @var string|null Id que esta sentencia publica en lastInsertId() al ejecutarse */
    private $insertId;

    public function __construct(FakePdo $pdo, $sql, array $rows, $rowCount, $insertId = null)
    {
        $this->pdo = $pdo;
        $this->sql = $sql;
        $this->rows = $rows;
        $this->rowCount = $rowCount;
        $this->insertId = $insertId;
    }

    public function execute($params = [])
    {
        $this->executed = true;
        $this->params = $params === null ? [] : $params;
        $this->pdo->record($this->sql, $this->params);

        if ($this->insertId !== null) {
            $this->pdo->setLastInsertId($this->insertId);
        }

        if ($this->pdo->shouldFailOn($this->sql)) {
            throw new \PDOException($this->pdo->failureMessage($this->sql));
        }

        return true;
    }

    public function fetch($mode = null)
    {
        if (empty($this->rows)) {
            return false;
        }
        return array_shift($this->rows);
    }

    public function fetchAll($mode = null)
    {
        $rows = $this->rows;
        $this->rows = [];

        // FETCH_COLUMN devuelve escalares, no filas. Ignorar el modo dejaba
        // que el código bajo prueba recibiera arrays donde en producción
        // recibe strings, y el test pasaba por el motivo equivocado.
        if ($mode === \PDO::FETCH_COLUMN) {
            return array_map(function ($row) {
                return is_array($row) ? reset($row) : $row;
            }, $rows);
        }

        return $rows;
    }

    public function fetchColumn($column = 0)
    {
        $row = $this->fetch();
        if ($row === false) {
            return false;
        }
        $values = array_values($row);
        return isset($values[$column]) ? $values[$column] : false;
    }

    public function rowCount()
    {
        return $this->rowCount;
    }

    public function bindValue($param, $value, $type = null)
    {
        $this->params[$param] = $value;
        return true;
    }

    public function closeCursor()
    {
        return true;
    }

    public function wasExecuted()
    {
        return $this->executed;
    }
}
