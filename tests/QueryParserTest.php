<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Query\Parser\MySQL;
use Utopia\Query\Parser\PostgreSQL;
use Utopia\Query\Type as QueryType;

class QueryParserTest extends TestCase
{
    protected PostgreSQL $pgParser;

    protected MySQL $mysqlParser;

    protected function setUp(): void
    {
        $this->pgParser = new PostgreSQL();
        $this->mysqlParser = new MySQL();
    }

    // ---------------------------------------------------------------
    // PostgreSQL Simple Query Protocol
    // ---------------------------------------------------------------

    /**
     * Build a PostgreSQL Simple Query ('Q') message
     *
     * Format: 'Q' | int32 length | query string \0
     */
    private function buildPgQuery(string $sql): string
    {
        $body = $sql . "\x00";
        $length = \strlen($body) + 4; // length includes itself but not the type byte

        return 'Q' . \pack('N', $length) . $body;
    }

    /**
     * Build a PostgreSQL Parse ('P') message (extended query protocol)
     */
    private function buildPgParse(string $stmtName, string $sql): string
    {
        $body = $stmtName . "\x00" . $sql . "\x00" . \pack('n', 0); // 0 param types
        $length = \strlen($body) + 4;

        return 'P' . \pack('N', $length) . $body;
    }

    /**
     * Build a PostgreSQL Bind ('B') message
     */
    private function buildPgBind(): string
    {
        $body = "\x00\x00" . \pack('n', 0) . \pack('n', 0) . \pack('n', 0);
        $length = \strlen($body) + 4;

        return 'B' . \pack('N', $length) . $body;
    }

    /**
     * Build a PostgreSQL Execute ('E') message
     */
    private function buildPgExecute(): string
    {
        $body = "\x00" . \pack('N', 0);
        $length = \strlen($body) + 4;

        return 'E' . \pack('N', $length) . $body;
    }

    public function test_pg_select_query(): void
    {
        $data = $this->buildPgQuery('SELECT * FROM users WHERE id = 1');
        $this->assertSame(QueryType::Read, $this->pgParser->parse($data));
    }

    public function test_pg_select_lowercase(): void
    {
        $data = $this->buildPgQuery('select id, name from users');
        $this->assertSame(QueryType::Read, $this->pgParser->parse($data));
    }

    public function test_pg_select_mixed_case(): void
    {
        $data = $this->buildPgQuery('SeLeCt * FROM users');
        $this->assertSame(QueryType::Read, $this->pgParser->parse($data));
    }

    public function test_pg_show_query(): void
    {
        $data = $this->buildPgQuery('SHOW TABLES');
        $this->assertSame(QueryType::Read, $this->pgParser->parse($data));
    }

    public function test_pg_describe_query(): void
    {
        $data = $this->buildPgQuery('DESCRIBE users');
        $this->assertSame(QueryType::Read, $this->pgParser->parse($data));
    }

    public function test_pg_explain_query(): void
    {
        $data = $this->buildPgQuery('EXPLAIN SELECT * FROM users');
        $this->assertSame(QueryType::Read, $this->pgParser->parse($data));
    }

    public function test_pg_table_query(): void
    {
        $data = $this->buildPgQuery('TABLE users');
        $this->assertSame(QueryType::Read, $this->pgParser->parse($data));
    }

    public function test_pg_values_query(): void
    {
        $data = $this->buildPgQuery("VALUES (1, 'a'), (2, 'b')");
        $this->assertSame(QueryType::Read, $this->pgParser->parse($data));
    }

    public function test_pg_insert_query(): void
    {
        $data = $this->buildPgQuery("INSERT INTO users (name) VALUES ('test')");
        $this->assertSame(QueryType::Write, $this->pgParser->parse($data));
    }

    public function test_pg_update_query(): void
    {
        $data = $this->buildPgQuery("UPDATE users SET name = 'test' WHERE id = 1");
        $this->assertSame(QueryType::Write, $this->pgParser->parse($data));
    }

    public function test_pg_delete_query(): void
    {
        $data = $this->buildPgQuery('DELETE FROM users WHERE id = 1');
        $this->assertSame(QueryType::Write, $this->pgParser->parse($data));
    }

    public function test_pg_create_table(): void
    {
        $data = $this->buildPgQuery('CREATE TABLE test (id INT PRIMARY KEY)');
        $this->assertSame(QueryType::Write, $this->pgParser->parse($data));
    }

    public function test_pg_drop_table(): void
    {
        $data = $this->buildPgQuery('DROP TABLE IF EXISTS test');
        $this->assertSame(QueryType::Write, $this->pgParser->parse($data));
    }

    public function test_pg_alter_table(): void
    {
        $data = $this->buildPgQuery('ALTER TABLE users ADD COLUMN email TEXT');
        $this->assertSame(QueryType::Write, $this->pgParser->parse($data));
    }

    public function test_pg_truncate(): void
    {
        $data = $this->buildPgQuery('TRUNCATE TABLE users');
        $this->assertSame(QueryType::Write, $this->pgParser->parse($data));
    }

    public function test_pg_grant(): void
    {
        $data = $this->buildPgQuery('GRANT SELECT ON users TO readonly');
        $this->assertSame(QueryType::Write, $this->pgParser->parse($data));
    }

    public function test_pg_revoke(): void
    {
        $data = $this->buildPgQuery('REVOKE ALL ON users FROM public');
        $this->assertSame(QueryType::Write, $this->pgParser->parse($data));
    }

    public function test_pg_lock_table(): void
    {
        $data = $this->buildPgQuery('LOCK TABLE users IN ACCESS EXCLUSIVE MODE');
        $this->assertSame(QueryType::Write, $this->pgParser->parse($data));
    }

    public function test_pg_call(): void
    {
        $data = $this->buildPgQuery('CALL my_procedure()');
        $this->assertSame(QueryType::Write, $this->pgParser->parse($data));
    }

    public function test_pg_do(): void
    {
        $data = $this->buildPgQuery("DO $$ BEGIN RAISE NOTICE 'hello'; END $$");
        $this->assertSame(QueryType::Write, $this->pgParser->parse($data));
    }

    // ---------------------------------------------------------------
    // PostgreSQL Transaction Commands
    // ---------------------------------------------------------------

    public function test_pg_begin_transaction(): void
    {
        $data = $this->buildPgQuery('BEGIN');
        $this->assertSame(QueryType::TransactionBegin, $this->pgParser->parse($data));
    }

    public function test_pg_start_transaction(): void
    {
        $data = $this->buildPgQuery('START TRANSACTION');
        $this->assertSame(QueryType::TransactionBegin, $this->pgParser->parse($data));
    }

    public function test_pg_commit(): void
    {
        $data = $this->buildPgQuery('COMMIT');
        $this->assertSame(QueryType::TransactionEnd, $this->pgParser->parse($data));
    }

    public function test_pg_rollback(): void
    {
        $data = $this->buildPgQuery('ROLLBACK');
        $this->assertSame(QueryType::TransactionEnd, $this->pgParser->parse($data));
    }

    public function test_pg_savepoint(): void
    {
        $data = $this->buildPgQuery('SAVEPOINT sp1');
        $this->assertSame(QueryType::Transaction, $this->pgParser->parse($data));
    }

    public function test_pg_release_savepoint(): void
    {
        $data = $this->buildPgQuery('RELEASE SAVEPOINT sp1');
        $this->assertSame(QueryType::Transaction, $this->pgParser->parse($data));
    }

    public function test_pg_set_command(): void
    {
        $data = $this->buildPgQuery("SET search_path TO 'public'");
        $this->assertSame(QueryType::Transaction, $this->pgParser->parse($data));
    }

    // ---------------------------------------------------------------
    // PostgreSQL Extended Query Protocol
    // ---------------------------------------------------------------

    public function test_pg_parse_message_routes_to_write(): void
    {
        $data = $this->buildPgParse('stmt1', 'SELECT * FROM users');
        $this->assertSame(QueryType::Write, $this->pgParser->parse($data));
    }

    public function test_pg_bind_message_routes_to_write(): void
    {
        $data = $this->buildPgBind();
        $this->assertSame(QueryType::Write, $this->pgParser->parse($data));
    }

    public function test_pg_execute_message_routes_to_write(): void
    {
        $data = $this->buildPgExecute();
        $this->assertSame(QueryType::Write, $this->pgParser->parse($data));
    }

    // ---------------------------------------------------------------
    // PostgreSQL Edge Cases
    // ---------------------------------------------------------------

    public function test_pg_too_short_packet(): void
    {
        $this->assertSame(QueryType::Unknown, $this->pgParser->parse('Q'));
    }

    public function test_pg_unknown_message_type(): void
    {
        $data = 'X' . \pack('N', 5) . "\x00";
        $this->assertSame(QueryType::Unknown, $this->pgParser->parse($data));
    }

    // ---------------------------------------------------------------
    // MySQL COM_QUERY Protocol
    // ---------------------------------------------------------------

    /**
     * Build a MySQL COM_QUERY packet
     *
     * Format: 3-byte length (LE) | 1-byte seq | 0x03 | query string
     */
    private function buildMySQLQuery(string $sql): string
    {
        $payloadLen = 1 + \strlen($sql); // command byte + query
        $header = \pack('V', $payloadLen); // 4 bytes, but MySQL uses 3 bytes length + 1 byte seq
        $header[3] = "\x00"; // sequence id = 0

        return $header . "\x03" . $sql;
    }

    /**
     * Build a MySQL COM_STMT_PREPARE packet
     */
    private function buildMySQLStmtPrepare(string $sql): string
    {
        $payloadLen = 1 + \strlen($sql);
        $header = \pack('V', $payloadLen);
        $header[3] = "\x00";

        return $header . "\x16" . $sql;
    }

    /**
     * Build a MySQL COM_STMT_EXECUTE packet
     */
    private function buildMySQLStmtExecute(int $stmtId): string
    {
        $body = \pack('V', $stmtId) . "\x00" . \pack('V', 1); // stmt_id, flags, iteration_count
        $payloadLen = 1 + \strlen($body);
        $header = \pack('V', $payloadLen);
        $header[3] = "\x00";

        return $header . "\x17" . $body;
    }

    public function test_mysql_select_query(): void
    {
        $data = $this->buildMySQLQuery('SELECT * FROM users WHERE id = 1');
        $this->assertSame(QueryType::Read, $this->mysqlParser->parse($data));
    }

    public function test_mysql_select_lowercase(): void
    {
        $data = $this->buildMySQLQuery('select id from users');
        $this->assertSame(QueryType::Read, $this->mysqlParser->parse($data));
    }

    public function test_mysql_show_query(): void
    {
        $data = $this->buildMySQLQuery('SHOW DATABASES');
        $this->assertSame(QueryType::Read, $this->mysqlParser->parse($data));
    }

    public function test_mysql_describe_query(): void
    {
        $data = $this->buildMySQLQuery('DESCRIBE users');
        $this->assertSame(QueryType::Read, $this->mysqlParser->parse($data));
    }

    public function test_mysql_desc_query(): void
    {
        $data = $this->buildMySQLQuery('DESC users');
        $this->assertSame(QueryType::Read, $this->mysqlParser->parse($data));
    }

    public function test_mysql_explain_query(): void
    {
        $data = $this->buildMySQLQuery('EXPLAIN SELECT * FROM users');
        $this->assertSame(QueryType::Read, $this->mysqlParser->parse($data));
    }

    public function test_mysql_insert_query(): void
    {
        $data = $this->buildMySQLQuery("INSERT INTO users (name) VALUES ('test')");
        $this->assertSame(QueryType::Write, $this->mysqlParser->parse($data));
    }

    public function test_mysql_update_query(): void
    {
        $data = $this->buildMySQLQuery("UPDATE users SET name = 'test' WHERE id = 1");
        $this->assertSame(QueryType::Write, $this->mysqlParser->parse($data));
    }

    public function test_mysql_delete_query(): void
    {
        $data = $this->buildMySQLQuery('DELETE FROM users WHERE id = 1');
        $this->assertSame(QueryType::Write, $this->mysqlParser->parse($data));
    }

    public function test_mysql_create_table(): void
    {
        $data = $this->buildMySQLQuery('CREATE TABLE test (id INT PRIMARY KEY)');
        $this->assertSame(QueryType::Write, $this->mysqlParser->parse($data));
    }

    public function test_mysql_drop_table(): void
    {
        $data = $this->buildMySQLQuery('DROP TABLE test');
        $this->assertSame(QueryType::Write, $this->mysqlParser->parse($data));
    }

    public function test_mysql_alter_table(): void
    {
        $data = $this->buildMySQLQuery('ALTER TABLE users ADD COLUMN email VARCHAR(255)');
        $this->assertSame(QueryType::Write, $this->mysqlParser->parse($data));
    }

    public function test_mysql_truncate(): void
    {
        $data = $this->buildMySQLQuery('TRUNCATE TABLE users');
        $this->assertSame(QueryType::Write, $this->mysqlParser->parse($data));
    }

    // ---------------------------------------------------------------
    // MySQL Transaction Commands
    // ---------------------------------------------------------------

    public function test_mysql_begin_transaction(): void
    {
        $data = $this->buildMySQLQuery('BEGIN');
        $this->assertSame(QueryType::TransactionBegin, $this->mysqlParser->parse($data));
    }

    public function test_mysql_start_transaction(): void
    {
        $data = $this->buildMySQLQuery('START TRANSACTION');
        $this->assertSame(QueryType::TransactionBegin, $this->mysqlParser->parse($data));
    }

    public function test_mysql_commit(): void
    {
        $data = $this->buildMySQLQuery('COMMIT');
        $this->assertSame(QueryType::TransactionEnd, $this->mysqlParser->parse($data));
    }

    public function test_mysql_rollback(): void
    {
        $data = $this->buildMySQLQuery('ROLLBACK');
        $this->assertSame(QueryType::TransactionEnd, $this->mysqlParser->parse($data));
    }

    public function test_mysql_set_command(): void
    {
        $data = $this->buildMySQLQuery("SET autocommit = 0");
        $this->assertSame(QueryType::Transaction, $this->mysqlParser->parse($data));
    }

    // ---------------------------------------------------------------
    // MySQL Prepared Statement Protocol
    // ---------------------------------------------------------------

    public function test_mysql_stmt_prepare_routes_to_write(): void
    {
        $data = $this->buildMySQLStmtPrepare('SELECT * FROM users WHERE id = ?');
        $this->assertSame(QueryType::Write, $this->mysqlParser->parse($data));
    }

    public function test_mysql_stmt_execute_routes_to_write(): void
    {
        $data = $this->buildMySQLStmtExecute(1);
        $this->assertSame(QueryType::Write, $this->mysqlParser->parse($data));
    }

    // ---------------------------------------------------------------
    // MySQL Edge Cases
    // ---------------------------------------------------------------

    public function test_mysql_too_short_packet(): void
    {
        $this->assertSame(QueryType::Unknown, $this->mysqlParser->parse("\x00\x00"));
    }

    public function test_mysql_unknown_command(): void
    {
        // COM_QUIT = 0x01
        $header = \pack('V', 1);
        $header[3] = "\x00";
        $data = $header . "\x01";
        $this->assertSame(QueryType::Unknown, $this->mysqlParser->parse($data));
    }

    // ---------------------------------------------------------------
    // SQL Classification (classifySQL) — Edge Cases
    // ---------------------------------------------------------------

    public function test_classify_leading_whitespace(): void
    {
        $this->assertSame(QueryType::Read, $this->pgParser->classifySQL("   \t\n  SELECT * FROM users"));
    }

    public function test_classify_leading_line_comment(): void
    {
        $this->assertSame(QueryType::Read, $this->pgParser->classifySQL("-- this is a comment\nSELECT * FROM users"));
    }

    public function test_classify_leading_block_comment(): void
    {
        $this->assertSame(QueryType::Read, $this->pgParser->classifySQL("/* block comment */ SELECT * FROM users"));
    }

    public function test_classify_multiple_comments(): void
    {
        $sql = "-- line comment\n/* block comment */\n  -- another line\n  SELECT 1";
        $this->assertSame(QueryType::Read, $this->pgParser->classifySQL($sql));
    }

    public function test_classify_nested_block_comment(): void
    {
        // Note: SQL standard doesn't support nested block comments; parser stops at first */
        $sql = "/* outer /* inner */ SELECT 1";
        $this->assertSame(QueryType::Read, $this->pgParser->classifySQL($sql));
    }

    public function test_classify_empty_query(): void
    {
        $this->assertSame(QueryType::Unknown, $this->pgParser->classifySQL(''));
    }

    public function test_classify_whitespace_only(): void
    {
        $this->assertSame(QueryType::Unknown, $this->pgParser->classifySQL("   \t\n  "));
    }

    public function test_classify_comment_only(): void
    {
        $this->assertSame(QueryType::Unknown, $this->pgParser->classifySQL('-- just a comment'));
    }

    public function test_classify_select_with_parenthesis(): void
    {
        $this->assertSame(QueryType::Read, $this->pgParser->classifySQL('SELECT(1)'));
    }

    public function test_classify_select_with_semicolon(): void
    {
        $this->assertSame(QueryType::Read, $this->pgParser->classifySQL('SELECT;'));
    }

    // ---------------------------------------------------------------
    // COPY Direction Classification
    // ---------------------------------------------------------------

    public function test_classify_copy_to(): void
    {
        $this->assertSame(QueryType::Read, $this->pgParser->classifySQL('COPY users TO STDOUT'));
    }

    public function test_classify_copy_from(): void
    {
        $this->assertSame(QueryType::Write, $this->pgParser->classifySQL("COPY users FROM '/tmp/data.csv'"));
    }

    public function test_classify_copy_ambiguous(): void
    {
        // No direction keyword - defaults to WRITE for safety
        $this->assertSame(QueryType::Write, $this->pgParser->classifySQL('COPY users'));
    }

    // ---------------------------------------------------------------
    // CTE (WITH) Classification
    // ---------------------------------------------------------------

    public function test_classify_cte_with_select(): void
    {
        $sql = 'WITH active_users AS (SELECT * FROM users WHERE active = true) SELECT * FROM active_users';
        $this->assertSame(QueryType::Read, $this->pgParser->classifySQL($sql));
    }

    public function test_classify_cte_with_insert(): void
    {
        $sql = 'WITH new_data AS (SELECT 1 AS id) INSERT INTO users SELECT * FROM new_data';
        $this->assertSame(QueryType::Write, $this->pgParser->classifySQL($sql));
    }

    public function test_classify_cte_with_update(): void
    {
        $sql = 'WITH src AS (SELECT id FROM staging) UPDATE users SET active = true FROM src WHERE users.id = src.id';
        $this->assertSame(QueryType::Write, $this->pgParser->classifySQL($sql));
    }

    public function test_classify_cte_with_delete(): void
    {
        $sql = 'WITH old AS (SELECT id FROM users WHERE created_at < now()) DELETE FROM users WHERE id IN (SELECT id FROM old)';
        $this->assertSame(QueryType::Write, $this->pgParser->classifySQL($sql));
    }

    public function test_classify_cte_recursive_select(): void
    {
        $sql = 'WITH RECURSIVE tree AS (SELECT id, parent_id FROM categories WHERE parent_id IS NULL UNION ALL SELECT c.id, c.parent_id FROM categories c JOIN tree t ON c.parent_id = t.id) SELECT * FROM tree';
        $this->assertSame(QueryType::Read, $this->pgParser->classifySQL($sql));
    }

    public function test_classify_cte_no_final_keyword(): void
    {
        // Bare WITH with no recognizable final statement - defaults to READ
        $sql = 'WITH x AS (SELECT 1)';
        $this->assertSame(QueryType::Read, $this->pgParser->classifySQL($sql));
    }

    // ---------------------------------------------------------------
    // Keyword Extraction
    // ---------------------------------------------------------------

    public function test_extract_keyword_simple(): void
    {
        $this->assertSame('SELECT', $this->pgParser->extractKeyword('SELECT * FROM users'));
    }

    public function test_extract_keyword_lowercase(): void
    {
        $this->assertSame('INSERT', $this->pgParser->extractKeyword('insert into users'));
    }

    public function test_extract_keyword_with_whitespace(): void
    {
        $this->assertSame('DELETE', $this->pgParser->extractKeyword("  \t\n  DELETE FROM users"));
    }

    public function test_extract_keyword_with_comments(): void
    {
        $this->assertSame('UPDATE', $this->pgParser->extractKeyword("-- comment\nUPDATE users SET x = 1"));
    }

    public function test_extract_keyword_empty(): void
    {
        $this->assertSame('', $this->pgParser->extractKeyword(''));
    }

    public function test_extract_keyword_parenthesized(): void
    {
        $this->assertSame('SELECT', $this->pgParser->extractKeyword('SELECT(1)'));
    }

    // ---------------------------------------------------------------
    // Performance
    // ---------------------------------------------------------------

    public function test_parse_performance(): void
    {
        $pgData = $this->buildPgQuery('SELECT * FROM users WHERE id = 1');
        $mysqlData = $this->buildMySQLQuery('SELECT * FROM users WHERE id = 1');

        $iterations = 100_000;

        // PostgreSQL parse performance
        $start = \hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $this->pgParser->parse($pgData);
        }
        $pgElapsed = (\hrtime(true) - $start) / 1_000_000_000; // seconds
        $pgPerQuery = ($pgElapsed / $iterations) * 1_000_000; // microseconds

        // MySQL parse performance
        $start = \hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $this->mysqlParser->parse($mysqlData);
        }
        $mysqlElapsed = (\hrtime(true) - $start) / 1_000_000_000;
        $mysqlPerQuery = ($mysqlElapsed / $iterations) * 1_000_000;

        // Both should be under 1 microsecond per parse
        $this->assertLessThan(
            1.0,
            $pgPerQuery,
            \sprintf('PostgreSQL parse took %.3f us/query (target: < 1.0 us)', $pgPerQuery)
        );
        $this->assertLessThan(
            1.0,
            $mysqlPerQuery,
            \sprintf('MySQL parse took %.3f us/query (target: < 1.0 us)', $mysqlPerQuery)
        );
    }

    public function test_classify_sql_performance(): void
    {
        $queries = [
            'SELECT * FROM users WHERE id = 1',
            "INSERT INTO logs (msg) VALUES ('test')",
            'BEGIN',
            '   /* comment */ SELECT 1',
            'WITH cte AS (SELECT 1) SELECT * FROM cte',
        ];

        $iterations = 100_000;

        $start = \hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $this->pgParser->classifySQL($queries[$i % \count($queries)]);
        }
        $elapsed = (\hrtime(true) - $start) / 1_000_000_000;
        $perQuery = ($elapsed / $iterations) * 1_000_000;

        // Threshold is 2us to account for CTE queries which require parenthesis-depth scanning.
        // Simple queries (SELECT, INSERT, BEGIN) are well under 1us individually.
        $this->assertLessThan(
            2.0,
            $perQuery,
            \sprintf('classifySQL took %.3f us/query (target: < 2.0 us)', $perQuery)
        );
    }
}
