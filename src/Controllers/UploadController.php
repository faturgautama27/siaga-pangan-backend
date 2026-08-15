<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use App\Db\TursoConnection;
use App\Services\ExcelParserService;
use App\Config\Settings;

class UploadController
{
    public function __construct(private TursoConnection $db) {}

    public static function create(): self
    {
        return new self(new TursoConnection());
    }

    public function upload(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['file'] ?? null;

        if (!$file) {
            return $this->json($response, [
                'success' => false,
                'error'   => ['code' => 'NO_FILE', 'message' => 'File tidak ditemukan. Kirim file dengan field name "file".'],
            ], 422);
        }

        // Validasi ukuran
        if ($file->getSize() > Settings::maxUploadBytes()) {
            return $this->json($response, [
                'success' => false,
                'error'   => ['code' => 'FILE_TOO_LARGE', 'message' => 'Ukuran file melebihi batas 10 MB.'],
            ], 422);
        }

        // Validasi ekstensi
        $filename  = $file->getClientFilename() ?? 'upload.xlsx';
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($extension !== 'xlsx') {
            return $this->json($response, [
                'success' => false,
                'error'   => ['code' => 'INVALID_FILE_TYPE', 'message' => 'Hanya file .xlsx yang diterima.'],
            ], 422);
        }

        $userId = (int) $request->getAttribute('user_id');

        try {
            $parser = new ExcelParserService($this->db);
            $result = $parser->parse($file->getStream()->getContents(), $filename, $userId);

            return $this->json($response, ['success' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            return $this->json($response, [
                'success' => false,
                'error'   => ['code' => 'PARSE_ERROR', 'message' => $e->getMessage()],
            ], 500);
        }
    }

    public function log(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $page   = max(1, (int) ($params['page'] ?? 1));
        $limit  = min(50, max(1, (int) ($params['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $rows = $this->db->query(
            'SELECT ul.id, ul.nama_file, ul.uploaded_at, ul.jumlah_insert,
                    ul.jumlah_update, ul.jumlah_skip, ul.detail_skip,
                    u.nama AS uploaded_by
             FROM upload_log ul
             LEFT JOIN users u ON u.id = ul.uploaded_by
             ORDER BY ul.uploaded_at DESC
             LIMIT ? OFFSET ?',
            [$limit, $offset]
        );

        $total = $this->db->query('SELECT COUNT(*) AS total FROM upload_log')[0]['total'] ?? 0;

        return $this->json($response, [
            'success' => true,
            'data'    => $rows,
            'meta'    => ['page' => $page, 'limit' => $limit, 'total' => (int) $total],
        ]);
    }

    private function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
