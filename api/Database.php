<?php

class Database {
    /**
     * Segundos que la conexión puede quedarse quieta antes de que el servidor
     * la corte.
     *
     * El servidor viene con wait_timeout en 20 segundos, que alcanza de sobra
     * para atender un request web pero no para el cron del importador: ahí
     * entre una consulta y la siguiente hay esperas largas a propósito, para
     * no atropellar a los sitios ajenos. Con 20 segundos la conexión se moría
     * en medio de la corrida y la fuente terminaba con "MySQL server has gone
     * away" después de haber hecho todo el trabajo.
     */
    const ESPERA_MAXIMA = 600;

    private $host = DB_HOST;
    private $db_name = DB_NAME;
    private $username = DB_USER;
    private $password = DB_PASS;
    private $conn = null;

    public function connect() {
        if ($this->conn === null) {
            try {
                $this->conn = new PDO(
                    "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                    $this->username,
                    $this->password,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false
                    ]
                );

                // No todos los servidores dejan tocarlo. Si no se puede, la
                // conexión sirve igual: se sigue con el valor que haya en vez
                // de tirar abajo el pedido por una optimización.
                try {
                    $this->conn->exec('SET SESSION wait_timeout = ' . (int) self::ESPERA_MAXIMA);
                } catch (PDOException $e) {
                    // Sin timeout largo, pero conectados.
                }
            } catch(PDOException $e) {
                echo json_encode(['error' => 'Connection Error: ' . $e->getMessage()]);
                exit();
            }
        }
        return $this->conn;
    }
}
