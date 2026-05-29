<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use Illuminate\Http\Request;

class UpdateController extends Controller
{
    private const GITHUB_REPO   = 'theoapoel/resto-pos';
    private const GITHUB_BRANCH = 'main';

    public function index()
    {
        $info        = $this->localVersionInfo();
        $tokenSet    = !empty(env('GITHUB_TOKEN'));
        return view('update.index', ['local' => $info, 'tokenSet' => $tokenSet]);
    }

    public function checkLatest()
    {
        $token = env('GITHUB_TOKEN', '');

        if (empty($token)) {
            return response()->json([
                'success' => false,
                'error'   => 'GITHUB_TOKEN belum diset di .env. Hubungi HPY Solution untuk mendapatkan token.',
            ], 422);
        }

        try {
            $client = new Client(['timeout' => 10, 'verify' => false]);
            $res    = $client->get(
                'https://api.github.com/repos/' . self::GITHUB_REPO . '/commits/' . self::GITHUB_BRANCH,
                [
                    'headers' => [
                        'User-Agent'    => 'HPYSync-POS/1.0',
                        'Accept'        => 'application/vnd.github.v3+json',
                        'Authorization' => 'Bearer ' . $token,
                    ],
                ]
            );

            $data = json_decode($res->getBody()->getContents(), true);

            return response()->json([
                'success' => true,
                'sha'     => substr($data['sha'] ?? '', 0, 7),
                'message' => $data['commit']['message'] ?? '',
                'date'    => $data['commit']['author']['date'] ?? '',
                'author'  => $data['commit']['author']['name'] ?? '',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => 'Tidak dapat menghubungi GitHub: ' . $e->getMessage(),
            ], 422);
        }
    }

    public function run(Request $request)
    {
        $request->validate(['key' => 'required|string']);

        $validKey = env('UPDATE_KEY', '');
        if (empty($validKey) || $request->key !== $validKey) {
            return response()->json([
                'success' => false,
                'error'   => 'Key tidak valid. Hubungi HPY Solution untuk mendapatkan key update.',
            ], 403);
        }

        $token = env('GITHUB_TOKEN', '');
        $log   = [];
        $base  = base_path();

        // Gunakan token di URL agar git pull bisa autentikasi tanpa credential store
        if ($token) {
            $pullUrl = 'https://' . $token . '@github.com/' . self::GITHUB_REPO . '.git';
        } else {
            $pullUrl = 'origin';
        }

        $log[] = '▶ git pull ' . self::GITHUB_BRANCH;
        exec(
            'git -C ' . escapeshellarg($base) . ' pull ' . escapeshellarg($pullUrl) . ' ' . self::GITHUB_BRANCH . ' 2>&1',
            $pullOut,
            $code
        );
        foreach ($pullOut as $line) $log[] = $line;

        if ($code !== 0) {
            return response()->json(['success' => false, 'error' => 'git pull gagal.', 'log' => $log]);
        }

        // Migrate
        $log[] = '';
        $log[] = '▶ php artisan migrate --force';
        exec(PHP_BINARY . ' ' . escapeshellarg($base . '/artisan') . ' migrate --force 2>&1', $migrateOut);
        foreach ($migrateOut as $line) $log[] = $line;

        // Cache clear
        $log[] = '';
        $log[] = '▶ cache:clear / config:clear / view:clear / route:clear';
        foreach (['cache:clear', 'config:clear', 'view:clear', 'route:clear'] as $cmd) {
            $out = [];
            exec(PHP_BINARY . ' ' . escapeshellarg($base . '/artisan') . ' ' . $cmd . ' 2>&1', $out);
            foreach ($out as $line) $log[] = $line;
        }

        return response()->json(['success' => true, 'log' => $log]);
    }

    private function localVersionInfo(): array
    {
        $base     = base_path();
        $headFile = $base . '/.git/HEAD';

        if (!file_exists($headFile)) {
            return ['sha' => 'unknown', 'date' => '', 'message' => '', 'branch' => ''];
        }

        $head   = trim(file_get_contents($headFile));
        $sha    = 'unknown';
        $branch = '';

        if (str_starts_with($head, 'ref: ')) {
            $ref     = substr($head, 5);
            $branch  = basename($ref);
            $refFile = $base . '/.git/' . $ref;
            if (file_exists($refFile)) {
                $sha = trim(file_get_contents($refFile));
            }
        } else {
            $sha = $head;
        }

        $date    = '';
        $message = '';
        $logOut  = [];
        exec('git -C ' . escapeshellarg($base) . ' log -1 --format="%ci|||%s" HEAD 2>&1', $logOut);
        if (!empty($logOut[0]) && str_contains($logOut[0], '|||')) {
            [$date, $message] = explode('|||', $logOut[0], 2);
        }

        return [
            'sha'     => substr($sha, 0, 7),
            'date'    => trim($date),
            'message' => trim($message),
            'branch'  => $branch,
        ];
    }
}
