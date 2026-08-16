<?php

namespace Tests\Support;

/**
 * Doble de PDO para tests unitarios de handlers.
 *
 * Los handlers reciben la conexión por parámetro y sólo usan una superficie
 * pequeña de PDO (prepare, query, lastInsertId y transacciones), así que un
 * doble con tipado de pato alcanza y evita el ruido de los mocks de PHPUnit.
 *
 * Las reglas se asocian por fragmento de SQL:
 *
 *     $db->onSelect('FROM pages WHERE url_slug', [['id' => 1, 'title' => 'X']]);
 *     $db->onInsert('INSERT INTO links', 42);
 *
 * Si varias reglas comparten fragmento se consumen en orden de registro, lo
 * que permite modelar consultas repetidas dentro de un bucle.
 */
class FakePdo
{
    /** @var array Lista de reglas: ['fragment' => string, 'rows' => array, 'rowCount' => int, 'insertId' => ?string, 'once' => bool] */
    private $rules = [];

    /** @var array Log de ejecuciones: ['sql' => string, 'params' => array] */
    private $log = [];

    /** @var string */
    private $lastInsertId = '1';

    /** @var array Fragmentos que deben lanzar PDOException */
    private $failures = [];

    /** @var int */
    public $transactionDepth = 0;

    /** @var bool */
    public $committed = false;

    /** @var bool */
    public $rolledBack = false;

    // ---------------------------------------------------------------- reglas

    /** Carga las filas que devolverá la próxima consulta que contenga $fragment. */
    public function onSelect($fragment, array $rows)
    {
        $this->rules[] = [
            'fragment' => $this->normalize($fragment),
            'rows' => $rows,
            'rowCount' => count($rows),
            'insertId' => null,
            'consumed' => false,
        ];
        return $this;
    }

    /** Para INSERT: fija el id que devolverá lastInsertId() tras ejecutarse. */
    public function onInsert($fragment, $insertId)
    {
        $this->rules[] = [
            'fragment' => $this->normalize($fragment),
            'rows' => [],
            'rowCount' => 1,
            'insertId' => (string) $insertId,
            'consumed' => false,
        ];
        return $this;
    }

    /** Para UPDATE/DELETE: fija cuántas filas afectó. */
    public function onWrite($fragment, $rowCount = 1)
    {
        $this->rules[] = [
            'fragment' => $this->normalize($fragment),
            'rows' => [],
            'rowCount' => $rowCount,
            'insertId' => null,
            'consumed' => false,
        ];
        return $this;
    }

    /** Hace que las consultas que contengan $fragment lancen PDOException. */
    public function failOn($fragment, $message = 'fake database failure')
    {
        $this->failures[$this->normalize($fragment)] = $message;
        return $this;
    }

    // ------------------------------------------------------------ superficie PDO

    public function prepare($sql)
    {
        return $this->statementFor($sql);
    }

    public function query($sql)
    {
        $stmt = $this->statementFor($sql);
        $this->record($sql, []);
        return $stmt;
    }

    public function exec($sql)
    {
        $this->record($sql, []);
        return 1;
    }

    public function lastInsertId($name = null)
    {
        return $this->lastInsertId;
    }

    public function beginTransaction()
    {
        $this->transactionDepth++;
        return true;
    }

    public function commit()
    {
        $this->transactionDepth--;
        $this->committed = true;
        return true;
    }

    public function rollBack()
    {
        $this->transactionDepth--;
        $this->rolledBack = true;
        return true;
    }

    public function inTransaction()
    {
        return $this->transactionDepth > 0;
    }

    public function setAttribute($attr, $value)
    {
        return true;
    }

    // ------------------------------------------------------------- inspección

    /** @internal Lo llama FakeStatement al ejecutarse. */
    public function record($sql, array $params)
    {
        $this->log[] = ['sql' => $sql, 'params' => $params];
    }

    /** @internal Lo llama FakeStatement cuando la regla traía un insertId. */
    public function setLastInsertId($id)
    {
        $this->lastInsertId = (string) $id;
    }

    /** @internal */
    public function shouldFailOn($sql)
    {
        return $this->failureMessage($sql) !== null;
    }

    /** @internal */
    public function failureMessage($sql)
    {
        $normalized = $this->normalize($sql);
        foreach ($this->failures as $fragment => $message) {
            if (strpos($normalized, $fragment) !== false) {
                return $message;
            }
        }
        return null;
    }

    /** Todas las consultas ejecutadas, en orden. */
    public function log()
    {
        return $this->log;
    }

    /** true si se ejecutó alguna consulta que contenga $fragment. */
    public function ran($fragment)
    {
        return $this->firstCall($fragment) !== null;
    }

    /** Cuántas consultas ejecutadas contienen $fragment. */
    public function countCalls($fragment)
    {
        $needle = $this->normalize($fragment);
        $count = 0;
        foreach ($this->log as $entry) {
            if (strpos($this->normalize($entry['sql']), $needle) !== false) {
                $count++;
            }
        }
        return $count;
    }

    /** Parámetros de la primera consulta que contenga $fragment, o null. */
    public function paramsFor($fragment)
    {
        $call = $this->firstCall($fragment);
        return $call === null ? null : $call['params'];
    }

    /** Todas las llamadas que contengan $fragment. */
    public function callsFor($fragment)
    {
        $needle = $this->normalize($fragment);
        $calls = [];
        foreach ($this->log as $entry) {
            if (strpos($this->normalize($entry['sql']), $needle) !== false) {
                $calls[] = $entry;
            }
        }
        return $calls;
    }

    private function firstCall($fragment)
    {
        $calls = $this->callsFor($fragment);
        return empty($calls) ? null : $calls[0];
    }

    // ----------------------------------------------------------------- interno

    private function statementFor($sql)
    {
        $normalized = $this->normalize($sql);

        foreach ($this->rules as $index => $rule) {
            if ($rule['consumed']) {
                continue;
            }
            if (strpos($normalized, $rule['fragment']) !== false) {
                $this->rules[$index]['consumed'] = true;
                return new FakeStatement($this, $sql, $rule['rows'], $rule['rowCount'], $rule['insertId']);
            }
        }

        // Sin regla: conjunto vacío. Modela "no encontrado" sin configuración extra.
        return new FakeStatement($this, $sql, [], 0);
    }

    /** Colapsa espacios y saltos de línea para que los fragmentos matcheen SQL multilínea. */
    private function normalize($sql)
    {
        return preg_replace('/\s+/', ' ', trim($sql));
    }
}
