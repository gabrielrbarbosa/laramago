<?php

declare(strict_types=1);

namespace Laramago\Concerns;

trait RunsMagoProcesses
{
    private function defaultAnalyzeFlags(string $projectRoot, array $arguments, bool $usesOverlays): array
    {
        $hasBaselineOption = false;

        foreach ($arguments as $argument) {
            if ($argument === '--ignore-baseline' || $argument === '--baseline' || str_starts_with($argument, '--baseline=')) {
                $hasBaselineOption = true;
                break;
            }
        }

        if ($hasBaselineOption) {
            return [];
        }

        if (! is_file($projectRoot . '/' . self::BASELINE_FILE)) {
            $runtimeBaseline = $projectRoot . '/' . self::RUNTIME_BASELINE_FILE;

            if (is_file($runtimeBaseline)) {
                @unlink($runtimeBaseline);
            }

            return ['--ignore-baseline'];
        }

        if ($usesOverlays && $this->translateBaselinePaths($projectRoot, overlayToOriginal: false, source: self::BASELINE_FILE, target: self::RUNTIME_BASELINE_FILE)) {
            return ['--baseline', self::RUNTIME_BASELINE_FILE];
        }

        return ['--baseline', self::BASELINE_FILE];
    }

    private function translateBaselinePaths(string $projectRoot, bool $overlayToOriginal, string $source, string $target): bool
    {
        $sourcePath = $projectRoot . '/' . $source;
        $targetPath = $projectRoot . '/' . $target;

        if (! is_file($sourcePath)) {
            return false;
        }

        $map = $this->overlayPathMap($projectRoot);

        if ($map === []) {
            return false;
        }

        $baseline = file_get_contents($sourcePath);

        if (! is_string($baseline)) {
            return false;
        }

        foreach ($map as $entry) {
            if (! is_array($entry) || ! isset($entry['original'], $entry['overlay']) || ! is_string($entry['original']) || ! is_string($entry['overlay'])) {
                continue;
            }

            $from = $overlayToOriginal ? $entry['overlay'] : $entry['original'];
            $to = $overlayToOriginal ? $entry['original'] : $entry['overlay'];
            $baseline = str_replace('file = "' . $from . '"', 'file = "' . $to . '"', $baseline);
        }

        $this->ensureDirectory(dirname($targetPath));

        return file_put_contents($targetPath, $baseline) !== false;
    }

    private function stripLaramagoOptions(array $arguments): array
    {
        return array_values(array_filter(
            $arguments,
            static fn (string $argument): bool => ! str_starts_with($argument, '--project=')
                && ! str_starts_with($argument, '--phpstan-level=')
                && $argument !== '--force'
                && $argument !== '--no-laravel-model-overlays'
                && $argument !== '--no-laravel-framework-overlays'
                && $argument !== '--no-phpstan-pragma-overlays'
                && $argument !== '--find-unused-definitions'
        ));
    }

    /**
     * @param list<string> $arguments
     * @return list<string>
     */
    private function magoAnalyzePathArguments(array $arguments): array
    {
        $arguments = $this->stripLaramagoOptions($arguments);
        $paths = [];
        $consumeNext = false;
        $optionsWithValues = [
            '--baseline' => true,
            '--reporting-target' => true,
            '--reporting-format' => true,
            '--minimum-fail-level' => true,
            '--minimum-report-level' => true,
            '--retain-code' => true,
            '--substitute' => true,
            '-m' => true,
        ];

        foreach ($arguments as $argument) {
            if ($consumeNext) {
                $consumeNext = false;

                continue;
            }

            if ($argument === '--') {
                $consumeNext = false;

                continue;
            }

            if (isset($optionsWithValues[$argument])) {
                $consumeNext = true;

                continue;
            }

            if (str_starts_with($argument, '-')) {
                continue;
            }

            $paths[] = $argument;
        }

        return $paths;
    }

    private function process(array $command, string $cwd): int
    {
        $stdoutPath = tempnam(sys_get_temp_dir(), 'laramago-stdout-');
        $stderrPath = tempnam(sys_get_temp_dir(), 'laramago-stderr-');

        if (! is_string($stdoutPath) || ! is_string($stderrPath)) {
            $this->line('Unable to create temporary output files.');

            return 1;
        }

        $process = proc_open($command, [
            0 => STDIN,
            1 => ['file', $stdoutPath, 'w'],
            2 => ['file', $stderrPath, 'w'],
        ], $pipes, $cwd);

        if (! is_resource($process)) {
            $this->line('Unable to start process.');

            return 1;
        }

        $exitCode = proc_close($process);
        $stdout = file_get_contents($stdoutPath);
        $stderr = file_get_contents($stderrPath);
        @unlink($stdoutPath);
        @unlink($stderrPath);

        if (is_string($stdout) && $stdout !== '') {
            fwrite(STDOUT, $this->translateOutputPaths($cwd, $stdout));
        }

        if (is_string($stderr) && $stderr !== '') {
            fwrite(STDERR, $this->translateOutputPaths($cwd, $stderr));
        }

        return $exitCode;
    }

    private function writeCapturedOutput(string $projectRoot, array $result): void
    {
        $stdout = $result['stdout'] ?? '';
        $stderr = $result['stderr'] ?? '';

        if (is_string($stdout) && $stdout !== '') {
            fwrite(STDOUT, $this->translateOutputPaths($projectRoot, $stdout));
        }

        if (is_string($stderr) && $stderr !== '') {
            fwrite(STDERR, $this->translateOutputPaths($projectRoot, $stderr));
        }
    }

    private function compareAnalyzeArguments(array $arguments): array
    {
        $arguments = $this->stripLaramagoOptions($arguments);

        if (! $this->hasMagoAnalyzeOption($arguments, '--ignore-baseline') && ! $this->hasMagoAnalyzeOption($arguments, '--baseline')) {
            array_unshift($arguments, '--ignore-baseline');
        }

        if (! $this->hasMagoAnalyzeOption($arguments, '--reporting-format')) {
            array_unshift($arguments, '--reporting-format=count');
        }

        return $arguments;
    }

    private function hasMagoAnalyzeOption(array $arguments, string $option): bool
    {
        foreach ($arguments as $argument) {
            if ($argument === $option || str_starts_with($argument, $option . '=')) {
                return true;
            }
        }

        return false;
    }

    private function translateOutputPaths(string $projectRoot, string $output): string
    {
        if ($output === '') {
            return $output;
        }

        foreach ($this->overlayPathMap($projectRoot) as $entry) {
            if (! is_array($entry) || ! isset($entry['original'], $entry['overlay']) || ! is_string($entry['original']) || ! is_string($entry['overlay'])) {
                continue;
            }

            $output = str_replace($projectRoot . '/' . $entry['overlay'], $projectRoot . '/' . $entry['original'], $output);
            $output = str_replace($entry['overlay'], $entry['original'], $output);
        }

        return $output;
    }

    private function overlayPathMap(string $projectRoot): array
    {
        $map = [];

        foreach ([self::MODEL_OVERLAY_MAP, self::PHPSTAN_PRAGMA_OVERLAY_MAP] as $mapFile) {
            $mapPath = $projectRoot . '/' . $mapFile;

            if (! is_file($mapPath)) {
                continue;
            }

            try {
                $entries = json_decode((string) file_get_contents($mapPath), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (! is_array($entry) || ! isset($entry['original'], $entry['overlay']) || ! is_string($entry['original']) || ! is_string($entry['overlay'])) {
                    continue;
                }

                $map[] = [
                    'original' => $entry['original'],
                    'overlay' => $entry['overlay'],
                ];
            }
        }

        return $map;
    }

    private function capture(array $command, string $cwd): array
    {
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $cwd);

        if (! is_resource($process)) {
            return ['exitCode' => 1, 'stdout' => '', 'stderr' => 'Unable to start process.'];
        }

        if (! isset($pipes[0], $pipes[1], $pipes[2])) {
            proc_close($process);

            return ['exitCode' => 1, 'stdout' => '', 'stderr' => 'Unable to open process pipes.'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exitCode' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function captureWithTimeout(array $command, string $cwd, int $timeoutSeconds): array
    {
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $cwd);

        if (! is_resource($process)) {
            return ['exitCode' => 1, 'stdout' => '', 'stderr' => 'Unable to start process.', 'timedOut' => false];
        }

        if (! isset($pipes[0], $pipes[1], $pipes[2])) {
            proc_close($process);

            return ['exitCode' => 1, 'stdout' => '', 'stderr' => 'Unable to open process pipes.', 'timedOut' => false];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + max(1, $timeoutSeconds);

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);

            if (! ($status['running'] ?? false)) {
                break;
            }

            if (microtime(true) >= $deadline) {
                proc_terminate($process);
                usleep(100_000);

                $status = proc_get_status($process);

                if ($status['running'] ?? false) {
                    proc_terminate($process, 9);
                }

                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return ['exitCode' => 1, 'stdout' => $stdout, 'stderr' => $stderr, 'timedOut' => true];
            }

            usleep(10_000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exitCode' => proc_close($process),
            'stdout' => $stdout,
            'stderr' => $stderr,
            'timedOut' => false,
        ];
    }

    private function withProjectLock(string $projectRoot, \Closure $callback): int
    {
        $this->ensureDirectory($projectRoot . '/' . self::STATE_DIR);

        $handle = fopen($projectRoot . '/' . self::LOCK_FILE, 'c');

        if ($handle === false) {
            $this->line('Unable to open Laramago project lock.');

            return 1;
        }

        if (! flock($handle, LOCK_EX)) {
            fclose($handle);
            $this->line('Unable to acquire Laramago project lock.');

            return 1;
        }

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Unable to create directory {$directory}.");
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
        } catch (\UnexpectedValueException) {
            return;
        }

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isDir()) {
                $path = $file->getPathname();

                if (is_dir($path)) {
                    @rmdir($path);
                }

                continue;
            }

            if ($file instanceof \SplFileInfo) {
                $path = $file->getPathname();

                if (is_file($path) || is_link($path)) {
                    @unlink($path);
                }
            }
        }

        if (is_dir($directory)) {
            @rmdir($directory);
        }
    }

    private function line(string $message): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }

    private function error(string $message): void
    {
        fwrite(STDERR, $message . PHP_EOL);
    }
}
