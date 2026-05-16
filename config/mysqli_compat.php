<?php

class MbusResult
{
    private array $rows;
    private int $position = 0;

    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function fetchAssoc(): array|false
    {
        if ($this->position >= count($this->rows)) {
            return false;
        }

        return $this->rows[$this->position++];
    }

    public function numRows(): int
    {
        return count($this->rows);
    }

    public function dataSeek(int $offset): bool
    {
        $this->position = max(0, $offset);
        return true;
    }
}

class MbusConnection
{
    public PDO $pdo;
    public string $lastError = '';

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function query(string $sql): MbusResult|bool
    {
        $sql = mbus_translate_sql($sql);

        try {
            $stmt = $this->pdo->query($sql);
            if ($stmt === false) {
                return false;
            }

            if (preg_match('/^\s*(SELECT|WITH)\b/is', $sql)) {
                return new MbusResult($stmt->fetchAll(PDO::FETCH_ASSOC));
            }

            return true;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    public function escape(string $value): string
    {
        $quoted = $this->pdo->quote($value);
        return substr($quoted, 1, -1);
    }
}

function mbus_iso_year_week(string $column): string
{
    return "(EXTRACT(ISOYEAR FROM $column)::int * 100 + EXTRACT(WEEK FROM $column)::int)";
}

function mbus_translate_sql(string $sql): string
{
    $replacements = [
        // MySQL DATE(col) → PostgreSQL (col)::date
        // Word boundary before DATE ensures we don't match DATE_ADD etc.
        '/\bDATE\(([a-zA-Z_][a-zA-Z0-9_.]*)\)/i' => '($1)::date',

        // NOW() - INTERVAL 5 MINUTE → PostgreSQL interval syntax
        '/NOW\(\)\s*-\s*INTERVAL\s+5\s+MINUTE/i' => "NOW() - INTERVAL '5 minutes'",
        '/>\s*NOW\(\)\s*-\s*INTERVAL\s+5\s+MINUTE/i' => "> NOW() - INTERVAL '5 minutes'",
        '/<=\s*NOW\(\)\s*-\s*INTERVAL\s+5\s+MINUTE/i' => "<= NOW() - INTERVAL '5 minutes'",

        // Compound YEARWEEK with embedded DATE_ADD — must come before standalone DATE_ADD
        '/YEARWEEK\s*\(\s*([^,]+?)\s*,\s*1\s*\)\s*=\s*YEARWEEK\s*\(\s*DATE_ADD\s*\(\s*CURDATE\(\)\s*,\s*INTERVAL\s+1\s+WEEK\s*\)\s*,\s*1\s*\)/i'
            => mbus_iso_year_week('$1') . ' = ' . mbus_iso_year_week("(CURRENT_DATE + INTERVAL '1 week')::timestamp"),

        // Other YEARWEEK patterns — must come before standalone CURDATE replacement
        '/YEARWEEK\s*\(\s*([^,]+?)\s*,\s*1\s*\)\s*=\s*YEARWEEK\s*\(\s*CURDATE\(\)\s*,\s*1\s*\)/i'
            => mbus_iso_year_week('$1') . ' = ' . mbus_iso_year_week('CURRENT_DATE::timestamp'),
        '/YEARWEEK\s*\(\s*([^,]+?)\s*,\s*1\s*\)\s*=\s*YEARWEEK\s*\(\s*CURDATE\(\)\s*,\s*1\s*\)\s*\+\s*1/i'
            => mbus_iso_year_week('$1') . ' = ' . mbus_iso_year_week("(CURRENT_DATE + INTERVAL '1 week')::timestamp"),

        // Standalone DATE_ADD patterns — must come before simple CURDATE replacement
        '/DATE_ADD\s*\(\s*CURDATE\(\)\s*,\s*INTERVAL\s+1\s+DAY\s*\)/i' => "(CURRENT_DATE + INTERVAL '1 day')",
        '/DATE_ADD\s*\(\s*CURDATE\(\)\s*,\s*INTERVAL\s+1\s+WEEK\s*\)/i' => "(CURRENT_DATE + INTERVAL '1 week')",

        // Simple CURDATE() → CURRENT_DATE (after all compound patterns)
        '/\bCURDATE\(\)/i' => 'CURRENT_DATE',

        // MySQL DAYNAME() → PostgreSQL TO_CHAR
        '/\bDAYNAME\s*\(\s*([^)]+)\s*\)/i' => "TRIM(TO_CHAR($1, 'FMDay'))",
    ];

    foreach ($replacements as $pattern => $replacement) {
        $sql = preg_replace($pattern, $replacement, $sql);
    }

    return $sql;
}

function mbus_db_query(MbusConnection $conn, string $sql): MbusResult|bool
{
    return $conn->query($sql);
}

function mbus_db_fetch_assoc(MbusResult|false|null $result): array|false
{
    if ($result instanceof MbusResult) {
        return $result->fetchAssoc();
    }

    return false;
}

function mbus_db_num_rows(MbusResult|false|null $result): int
{
    if ($result instanceof MbusResult) {
        return $result->numRows();
    }

    return 0;
}

function mbus_db_data_seek(MbusResult|false|null $result, int $offset): bool
{
    if ($result instanceof MbusResult) {
        return $result->dataSeek($offset);
    }

    return false;
}

function mbus_db_escape(MbusConnection $conn, string $value): string
{
    return $conn->escape($value);
}

function mbus_db_error(MbusConnection $conn): string
{
    return $conn->lastError;
}

function mbus_db_connect_error(): string
{
    return $GLOBALS['mbus_connect_error'] ?? '';
}
