<?php
class Database {
    private $host;
    private $port;
    private $db_name;
    private $db_user;
    private $db_password;
    private $conn;
    public function __construct() {
        $this->host = getenv('DB_HOST') ?: 'db';
        $this->port = getenv('DB_PORT') ?: '5432';
        $this->db_name = getenv('DB_NAME') ?: 'professore_presente';
        $this->db_user = getenv('DB_USER') ?: 'professor';
        $this->db_password = getenv('DB_PASSWORD') ?: 'secure_password_123';
    }
    public function connect() {
        $this->conn = null;
        try {
            $dsn = "pgsql:host=" . $this->host . 
                   ";port=" . $this->port . 
                   ";dbname=" . $this->db_name;
            $this->conn = new PDO(
                $dsn,
                $this->db_user,
                $this->db_password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );
            return $this->conn;
        } catch (PDOException $e) {
            error_log('DB connection failed: ' . $e->getMessage());
            return null;
        }
    }
}
?>
