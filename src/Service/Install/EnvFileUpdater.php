<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Install;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Dotenv\Exception\FormatException;

/**
 * Changes some values of an installed .env (re-pairing with the shop) without
 * touching anything else:
 *
 * - every other line, comment and value stays byte for byte; a changed key keeps
 *   its place and a new one is appended, quoted like EnvFileWriter does;
 * - the result is parsed back with Symfony Dotenv and must give exactly the new
 *   values and the old ones, or nothing is written;
 * - the previous file is kept as .env.bak-<date>-<time> (0600) next to it, and the
 *   new one replaces it with an atomic rename of a 0600 temporary file, under a lock;
 * - a key that another file (.env.local, .env.<env>.local, .env.local.php) or a
 *   real environment variable sets is refused: changing .env would not apply.
 */
final class EnvFileUpdater
{
    public const BACKUP_PREFIX = '.env.bak-';

    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly EnvFileWriter $writer = new EnvFileWriter(),
    ) {
    }

    /**
     * @param array<string, string> $values      the keys to set
     * @param array<string, string> $environment the process environment (getenv()) plus
     *                                           SYMFONY_DOTENV_VARS, the names Dotenv loaded
     *
     * @return string the backup file name, relative to the project folder
     *
     * @throws InstallException
     */
    public function update(#[\SensitiveParameter] array $values, array $environment, string $appEnv = 'prod'): string
    {
        foreach ($values as $name => $value) {
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) {
                throw new \InvalidArgumentException(sprintf('Invalid variable name "%s".', $name));
            }
            $this->writer->quote($value); // refuses control characters before anything is touched
        }

        $env = $this->projectDir . '/.env';
        $lock = @fopen($this->projectDir . '/var/.env.lock', 'c') ?: @fopen($env, 'r');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new InstallException('No se pudo abrir el archivo .env del TPV: revisa que exista y sus permisos.');
        }

        try {
            $old = @file_get_contents($env);
            if (!\is_string($old)) {
                throw new InstallException('No se pudo leer el archivo .env del TPV: revisa que exista y sus permisos.');
            }
            $this->assertNotOverridden(array_keys($values), $environment, $appEnv);

            $new = $this->replace($old, $values);
            $this->assertSameMeaning($old, $new, $values);

            $backup = $this->backupName();
            $this->writeFile($this->projectDir . '/' . $backup, $old, 'x');
            $tmp = $env . '.tmp-' . bin2hex(random_bytes(6));
            try {
                $this->writeFile($tmp, $new, 'x');
                if (!@rename($tmp, $env)) {
                    throw new InstallException('No se pudo sustituir el archivo .env del TPV: revisa los permisos de la carpeta del TPV.');
                }
            } finally {
                @unlink($tmp);
            }

            return $backup;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, string> $values */
    private function replace(string $content, array $values): string
    {
        $pending = $values;
        $lines = explode("\n", $content);
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=/', $line, $m) !== 1 || !\array_key_exists($m[1], $values)) {
                continue;
            }
            // A repeated key: the last line wins in Dotenv, so every one gets the value.
            $lines[$i] = $m[1] . '=' . $this->writer->quote($values[$m[1]]);
            unset($pending[$m[1]]);
        }
        $content = implode("\n", $lines);
        if ($pending !== []) {
            $content = rtrim($content, "\n") . "\n";
            foreach ($pending as $name => $value) {
                $content .= $name . '=' . $this->writer->quote($value) . "\n";
            }
        }

        return $content;
    }

    /**
     * @param array<string, string> $values
     *
     * @throws InstallException
     */
    private function assertSameMeaning(string $old, string $new, array $values): void
    {
        $dotenv = new Dotenv();
        try {
            $before = $dotenv->parse($old, '.env');
            $after = $dotenv->parse($new, '.env');
        } catch (FormatException) {
            throw new InstallException('El archivo .env del TPV tiene un formato que no se puede cambiar solo. Cambia las claves a mano o reinstala.');
        }
        $expected = array_merge($before, $values);
        ksort($expected);
        ksort($after);
        if ($after !== $expected) {
            throw new InstallException('El archivo .env del TPV tiene un formato que no se puede cambiar solo. Cambia las claves a mano o reinstala.');
        }
    }

    /**
     * @param list<string>          $names
     * @param array<string, string> $environment
     *
     * @throws InstallException
     */
    private function assertNotOverridden(array $names, array $environment, string $appEnv): void
    {
        if (is_file($this->projectDir . '/.env.local.php')) {
            throw new InstallException('El TPV usa .env.local.php (composer dump-env): cambia las claves ahí a mano o bórralo y vuelve a intentarlo.');
        }
        $loadedByDotenv = array_filter(explode(',', (string) ($environment['SYMFONY_DOTENV_VARS'] ?? '')));
        foreach ($names as $name) {
            if (\array_key_exists($name, $environment) && !\in_array($name, $loadedByDotenv, true)) {
                throw new InstallException(sprintf('%s está definida en la configuración del servidor (variable de entorno), no en .env: cámbiala ahí.', $name));
            }
        }
        $dotenv = new Dotenv();
        foreach (['.env.local', '.env.' . $appEnv, '.env.' . $appEnv . '.local'] as $file) {
            $content = @file_get_contents($this->projectDir . '/' . $file);
            if (!\is_string($content)) {
                continue;
            }
            try {
                $set = $dotenv->parse($content, $file);
            } catch (FormatException) {
                $set = [];
            }
            foreach ($names as $name) {
                if (\array_key_exists($name, $set)) {
                    throw new InstallException(sprintf('%s está definida en %s, que manda sobre .env: cámbiala ahí o quítala de ese archivo.', $name, $file));
                }
            }
        }
    }

    private function backupName(): string
    {
        $base = self::BACKUP_PREFIX . gmdate('Ymd-His');
        $name = $base;
        for ($i = 2; file_exists($this->projectDir . '/' . $name); ++$i) {
            $name = $base . '-' . $i;
        }

        return $name;
    }

    /** @throws InstallException */
    private function writeFile(string $path, string $content, string $mode): void
    {
        $handle = @fopen($path, $mode);
        if ($handle === false) {
            throw new InstallException('No se pudo escribir en la carpeta del TPV: revisa sus permisos.');
        }
        @chmod($path, 0600);
        $written = @fwrite($handle, $content);
        $flushed = @fflush($handle);
        @fclose($handle);
        if ($written !== \strlen($content) || !$flushed) {
            @unlink($path);
            throw new InstallException('No se pudo escribir en la carpeta del TPV: revisa el espacio libre y los permisos.');
        }
    }
}
