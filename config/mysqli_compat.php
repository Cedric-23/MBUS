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
        '/NOW\(\)\s*-\s*INTERVAL\s+5\s+MINUTE/i' => "NOW() - INTERVAL '5 minutes'",
        '/>\s*NOW\(\)\s*-\s*INTERVAL\s+5\s+MINUTE/i' => "> NOW() - INTERVAL '5 minutes'",
        '/<=\s*NOW\(\)\s*-\s*INTERVAL\s+5\s+MINUTE/i' => "<= NOW() - INTERVAL '5 minutes'",
        '/\bCURDATE\(\)/i' => 'CURRENT_DATE',
        '/DATE_ADD\s*\(\s*CURDATE\(\)\s*,\s*INTERVAL\s+1\s+DAY\s*\)/i' => "(CURRENT_DATE + INTERVAL '1 day')",
        '/DATE_ADD\s*\(\s*CURDATE\(\)\s*,\s*INTERVAL\s+1\s+WEEK\s*\)/i' => "(CURRENT_DATE + INTERVAL '1 week')",
        '/YEARWEEK\s*\(\s*([^,]+?)\s*,\s*1\s*\)\s*=\s*YEARWEEK\s*\(\s*CURDATE\(\)\s*,\s*1\s*\)/i'
            => mbus_iso_year_week('$1') . ' = ' . mbus_iso_year_week('CURRENT_DATE::timestamp'),
        '/YEARWEEK\s*\(\s*([^,]+?)\s*,\s*1\s*\)\s*=\s*YEARWEEK\s*\(\s*DATE_ADD\s*\(\s*CURDATE\(\)\s*,\s*INTERVAL\s+1\s+WEEK\s*\)\s*,\s*1\s*\)/i'
            => mbus_iso_year_week('$1') . ' = ' . mbus_iso_year_week("(CURRENT_DATE + INTERVAL '1 week')::timestamp"),
        '/YEARWEEK\s*\(\s*([^,]+?)\s*,\s*1\s*\)\s*=\s*YEARWEEK\s*\(\s*CURDATE\(\)\s*,\s*1\s*\)\s*\+\s*1/i'
            => mbus_iso_year_week('$1') . ' = ' . mbus_iso_year_week("(CURRENT_DATE + INTERVAL '1 week')::timestamp"),
        '/\bDAYNAME\s*\(\s*([^)]+)\s*\)/i' => "TRIM(TO_CHAR($1, 'FMDay'))",
    ];

    foreach ($replacements as $pattern => $replacement) {
        $sql = preg_replace($pattern, $replacement, $sql);
    }

    return $sql;
}

function mysqli_query($conn, string $sql): MbusResult|bool
{
    if ($conn instanceof MbusConnection) {
        return $conn->query($sql);
    }

    return \mysqli_query($conn, $sql);
}

function mysqli_fetch_assoc(MbusResult|mysqli_result|false|null $result): array|false
{
    if ($result instanceof MbusResult) {
        return $result->fetchAssoc();
    }

    if ($result instanceof mysqli_result) {
        return mysqli_fetch_assoc($result);
    }

    return false;
}

function mysqli_num_rows(MbusResult|mysqli_result|false|null $result): int
{
    if ($result instanceof MbusResult) {
        return $result->numRows();
    }

    if ($result instanceof mysqli_result) {
        return mysqli_num_rows($result);
    }

    return 0;
}

function mysqli_data_seek(MbusResult|mysqli_result|false|null $result, int $offset): bool
{
    if ($result instanceof MbusResult) {
        return $result->dataSeek($offset);
    }

    if ($result instanceof mysqli_result) {
        return mysqli_data_seek($result, $offset);
    }

    return false;
}

function mysqli_real_escape_string(MbusConnection|mysqli $conn, string $value): string
{
    if ($conn instanceof MbusConnection) {
        return $conn->escape($value);
    }

    return mysqli_real_escape_string($conn, $value);
}

function mysqli_error(MbusConnection|mysqli $conn): string
{
    if ($conn instanceof MbusConnection) {
        return $conn->lastError;
    }

    return mysqli_error($conn);
}

function mysqli_connect_error(): string
{
    return $GLOBALS['mbus_connect_error'] ?? '';
}
