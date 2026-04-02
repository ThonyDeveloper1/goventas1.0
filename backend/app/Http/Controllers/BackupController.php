<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BackupController extends Controller
{
    private string $configPath;
    private string $logPath;
    private string $scriptPath;
    private string $msmtprcPath;

    public function __construct()
    {
        $this->configPath  = base_path('../scripts/backup_config.json');
        $this->logPath     = '/var/log/backup_db.log';
        $this->scriptPath  = base_path('../scripts/backup_db.sh');
        $this->msmtprcPath = '/home/gouser/.msmtprc';
    }

    /** GET /api/admin/backup/config */
    public function getConfig()
    {
        $config = $this->readConfig();
        // Never expose the password
        unset($config['mail_password']);
        $config['mail_password_set'] = !empty($this->readConfig()['mail_password'] ?? '');

        return response()->json($config);
    }

    /** POST /api/admin/backup/config */
    public function saveConfig(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'mail_to' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $current = $this->readConfig();

        $config = [
            'mail_from'     => $current['mail_from']     ?? '',
            'mail_to'       => $request->mail_to,
            'mail_password' => $current['mail_password'] ?? '',
        ];

        file_put_contents($this->configPath, json_encode($config, JSON_PRETTY_PRINT));
        chmod($this->configPath, 0600);

        // Rewrite msmtprc
        $this->writeMsmtprc($config['mail_from'], $config['mail_password']);

        return response()->json(['message' => 'Configuración guardada correctamente.']);
    }

    /** POST /api/admin/backup/run */
    public function runBackup()
    {
        if (!file_exists($this->scriptPath)) {
            return response()->json(['error' => 'Script de backup no encontrado.'], 500);
        }

        $config = $this->readConfig();
        if (empty($config['mail_from']) || empty($config['mail_to']) || empty($config['mail_password'])) {
            return response()->json(['error' => 'Completa la configuración de correo antes de ejecutar el backup.'], 422);
        }

        // Run script in background, capture exit code
        $output = [];
        $exitCode = 0;
        exec("bash {$this->scriptPath} >> {$this->logPath} 2>&1", $output, $exitCode);

        if ($exitCode !== 0) {
            return response()->json(['error' => 'El backup falló. Revisa el log.'], 500);
        }

        return response()->json(['message' => 'Backup ejecutado y enviado correctamente.']);
    }

    /** GET /api/admin/backup/logs */
    public function getLogs()
    {
        if (!file_exists($this->logPath)) {
            return response()->json(['lines' => []]);
        }

        $lines = array_filter(explode("\n", shell_exec("tail -50 {$this->logPath} 2>/dev/null") ?? ''));
        return response()->json(['lines' => array_values(array_reverse($lines))]);
    }

    // ── Private helpers ──────────────────────────────────────

    private function readConfig(): array
    {
        if (!file_exists($this->configPath)) {
            return [
                'mail_from'     => 'kewinmendoza25@gmail.com',
                'mail_to'       => 'delacruzantony32@gmail.com',
                'mail_password' => '',
            ];
        }
        return json_decode(file_get_contents($this->configPath), true) ?? [];
    }

    private function writeMsmtprc(string $from, string $password): void
    {
        $content = <<<MSMTP
# msmtp config — GO Sistema backup emails
defaults
auth           on
tls            on
tls_trust_file /etc/ssl/certs/ca-certificates.crt
logfile        /var/log/msmtp.log

account        gmail
host           smtp.gmail.com
port           587
from           {$from}
user           {$from}
password       {$password}

account default : gmail
MSMTP;
        file_put_contents($this->msmtprcPath, $content);
        chmod($this->msmtprcPath, 0600);
    }
}
