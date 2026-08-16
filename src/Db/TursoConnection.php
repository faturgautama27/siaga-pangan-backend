<?php

declare(strict_types=1);

namespace App\Db;

use App\Config\Settings;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Wrapper HTTP client ke Turso HTTP API (endpoint /v2/pipeline).
 * Stateless — tidak ada persistent connection, cocok untuk serverless.
 */
class TursoConnection
{
    private Client $http;
    private string $baseUrl;
    private string $token;

    public function __construct()
    {
        $this->baseUrl = Settings::tursoUrl();
        $this->token   = Settings::tursoToken();
        $this->http    = new Client([
            'timeout' => 30,
            'verify'  => false, // disable SSL verify untuk development lokal Windows
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type'  => 'application/json',
            ],
        ]);
    }

    /**
     * Kirim satu atau lebih statement SQL ke Turso pipeline.
     *
     * @param  array<array{type:string, stmt:array}> $requests
     * @return array hasil response dari Turso
     * @throws \RuntimeException bila HTTP error atau Turso error
     */
    private function pipeline(array $requests): array
    {
        try {
            $response = $this->http->post($this->baseUrl . '/v2/pipeline', [
                'json' => ['requests' => $requests],
            ]);

            $body = json_decode((string) $response->getBody(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Invalid JSON response from Turso.');
            }

            return $body['results'] ?? [];
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Turso HTTP error: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Jalankan SELECT query dengan parameter binding.
     *
     * @param  string $sql   SQL query dengan placeholder ?
     * @param  array  $args  parameter value (string|int|float|null)
     * @return array  array of rows (associative)
     */
    public function query(string $sql, array $args = []): array
    {
        $results = $this->pipeline([
            [
                'type' => 'execute',
                'stmt' => [
                    'sql'  => $sql,
                    'args' => $this->buildArgs($args),
                ],
            ],
            ['type' => 'close'],
        ]);

        if (empty($results[0]['response']['result'])) {
            return [];
        }

        return $this->parseRows($results[0]['response']['result']);
    }

    /**
     * Jalankan INSERT/UPDATE/DELETE dengan parameter binding.
     *
     * @param  string $sql
     * @param  array  $args
     * @return array  ['affected_rows' => int, 'last_insert_id' => int]
     */
    public function execute(string $sql, array $args = []): array
    {
        $results = $this->pipeline([
            [
                'type' => 'execute',
                'stmt' => [
                    'sql'  => $sql,
                    'args' => $this->buildArgs($args),
                ],
            ],
            ['type' => 'close'],
        ]);

        $result = $results[0]['response']['result'] ?? [];

        return [
            'affected_rows'  => $result['affected_row_count'] ?? 0,
            'last_insert_id' => $result['last_insert_rowid'] ?? null,
        ];
    }

    /**
     * Jalankan beberapa statement dalam satu pipeline (batch).
     * Semua statement dieksekusi — tidak ada rollback otomatis.
     *
     * @param  array<array{sql:string, args:array}> $statements
     * @return array hasil tiap statement
     */
    public function batch(array $statements): array
    {
        $requests = [];
        foreach ($statements as $stmt) {
            $requests[] = [
                'type' => 'execute',
                'stmt' => [
                    'sql'  => $stmt['sql'],
                    'args' => $this->buildArgs($stmt['args'] ?? []),
                ],
            ];
        }
        $requests[] = ['type' => 'close'];

        return $this->pipeline($requests);
    }

    /**
     * Konversi array PHP ke format args Turso.
     */
    private function buildArgs(array $args): array
    {
        return array_map(function (mixed $val): array {
            if ($val === null) {
                return ['type' => 'null'];
            } elseif (is_int($val)) {
                return ['type' => 'integer', 'value' => (string) $val];
            } elseif (is_float($val)) {
                // Turso v2 pipeline API requires float value as native JSON number, not string
                return ['type' => 'float', 'value' => $val];
            } else {
                return ['type' => 'text', 'value' => (string) $val];
            }
        }, array_values($args));
    }

    /**
     * Parse rows dari format kolom Turso ke array associative.
     */
    private function parseRows(array $result): array
    {
        $cols = array_column($result['cols'] ?? [], 'name');
        $rows = [];

        foreach ($result['rows'] ?? [] as $row) {
            $assoc = [];
            foreach ($cols as $i => $col) {
                $cell       = $row[$i] ?? ['type' => 'null', 'value' => null];
                $assoc[$col] = $cell['type'] === 'null' ? null : $cell['value'];
            }
            $rows[] = $assoc;
        }

        return $rows;
    }
}
