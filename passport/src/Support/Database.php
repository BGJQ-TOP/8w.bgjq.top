<?php

namespace W8\Passport\Support;

use PDO;
use PDOException;
use RuntimeException;

/**
 * 数据库访问封装
 *
 * 懒连接：只有真正用到时才建连，避免每个请求都握手。
 * 统一禁用模拟预处理，强制走真正的服务端预处理。
 */
final class Database
{
    /** @var Config */
    private $config;

    /** @var PDO|null */
    private $pdo;

    /** @var Logger */
    private $logger;

    public function __construct(Config $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function pdo()
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $this->config->requireString('DB_HOST'),
            $this->config->getString('DB_PORT', '3306'),
            $this->config->requireString('DB_NAME'),
            $this->config->getString('DB_CHARSET', 'utf8mb4')
        );

        try {
            $this->pdo = new PDO(
                $dsn,
                $this->config->requireString('DB_USER'),
                $this->config->getString('DB_PASS'),
                array(
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                )
            );
        } catch (PDOException $e) {
            $this->logger->error('db.connect', array('message' => $e->getMessage()));
            throw new RuntimeException('数据库连接失败', 0, $e);
        }

        return $this->pdo;
    }

    /**
     * @param string $sql
     * @param array  $params
     * @return array<int,array<string,mixed>>
     */
    public function select($sql, array $params = array())
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        return is_array($rows) ? $rows : array();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function selectOne($sql, array $params = array())
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return mixed 第一列的值
     */
    public function selectValue($sql, array $params = array())
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    /**
     * @return int 受影响行数
     */
    public function execute($sql, array $params = array())
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * 插入一行，返回自增主键
     *
     * @param string $table
     * @param array<string,mixed> $data
     * @return int
     */
    public function insert($table, array $data)
    {
        $columns = array_keys($data);
        $placeholders = array_map(function ($column) {
            return ':' . $column;
        }, $columns);

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            '`' . implode('`, `', $columns) . '`',
            implode(', ', $placeholders)
        );

        $stmt = $this->pdo()->prepare($sql);
        foreach ($data as $column => $value) {
            $stmt->bindValue(':' . $column, $value);
        }
        $stmt->execute();

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * 按主键条件更新，返回受影响行数
     *
     * @param string $table
     * @param array<string,mixed> $data
     * @param string $where 形如 "id = :id"
     * @param array<string,mixed> $whereParams
     * @return int
     */
    public function update($table, array $data, $where, array $whereParams = array())
    {
        if (empty($data)) {
            return 0;
        }

        $assignments = array();
        $params = array();
        foreach ($data as $column => $value) {
            $assignments[] = '`' . $column . '` = :set_' . $column;
            $params[':set_' . $column] = $value;
        }
        foreach ($whereParams as $key => $value) {
            $params[':' . ltrim((string) $key, ':')] = $value;
        }

        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $assignments), $where);
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * 事务包装：回调抛异常即回滚
     *
     * @param callable $callback
     * @return mixed
     */
    public function transaction($callback)
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $result = $callback($this);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
