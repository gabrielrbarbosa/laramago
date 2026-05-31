<?php

declare(strict_types=1);

/**
 * @param list<string> $command
 */
function run(array $command): int
{
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes);

    if (! is_resource($process)) {
        return 1;
    }

    return proc_close($process);
}

/**
 * @param list<string> $command
 * @return array{exitCode: int, output: string}
 */
function captureRun(array $command): array
{
    $process = proc_open($command, [
        0 => STDIN,
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (! is_resource($process)) {
        return ['exitCode' => 1, 'output' => ''];
    }

    if (! isset($pipes[1], $pipes[2])) {
        proc_close($process);

        return ['exitCode' => 1, 'output' => 'Unable to open process pipes.'];
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exitCode' => proc_close($process),
        'output' => (is_string($stdout) ? $stdout : '') . (is_string($stderr) ? $stderr : ''),
    ];
}

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function cleanup(string $path): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isDir()) {
            rmdir($file->getPathname());

            continue;
        }

        if ($file instanceof SplFileInfo) {
            unlink($file->getPathname());
        }
    }

    rmdir($path);
}
