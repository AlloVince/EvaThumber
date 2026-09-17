<?php

declare(strict_types=1);

// Internal launcher: php process-guard.php EXPECTED_PARENT (--exec PROGRAM | PHP_FILE) [ARGS...]
// Arm before application/native initialization. PR_SET_PDEATHSIG survives ordinary
// exec, and SIGKILL works even while a codec is busy or the child is SIGSTOP'ed.
// Check the original parent AFTER arming to close the fork/exec/parent-exit race.
$expectedParent = (int) ($argv[1] ?? 0);
if ($expectedParent < 1 || !isset($argv[2])) { exit(70); }
if (PHP_OS_FAMILY === 'Linux') {
    try {
        $libc = FFI::cdef('int prctl(int option, unsigned long a2, unsigned long a3, unsigned long a4, unsigned long a5); int getppid(void);');
        if ($libc->prctl(1, 9, 0, 0, 0) !== 0 || $libc->getppid() !== $expectedParent) {
            throw new RuntimeException('Cannot establish parent-death guard');
        }
    } catch (Throwable $error) {
        fwrite(STDERR, 'process_guard: ' . $error->getMessage() . "\n");
        exit(70); // Linux production must not silently run without its guard.
    }
} elseif (posix_getppid() !== $expectedParent) {
    exit(70);
}
// Non-Linux supports normal supervisor cleanup, not asynchronous parent-death
// enforcement. Do not confuse an EOF/parent poll with a busy-worker kill bound.
if ($argv[2] === '--exec') {
    if (!isset($argv[3])) { exit(70); }
    pcntl_exec($argv[3], array_slice($argv, 4));
    fwrite(STDERR, "process_guard: exec failed\n");
    exit(70);
}
$argv = array_slice($argv, 2);
$argc = count($argv);
require $argv[0];
