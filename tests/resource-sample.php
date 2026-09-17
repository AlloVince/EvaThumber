<?php

declare(strict_types=1);

// Linux/container-only observer. Stop via stdin; hard bound protects failed harnesses.
stream_set_blocking(STDIN, false);
$cpu = static function (): int {
    $text = file_get_contents('/sys/fs/cgroup/cpu.stat');
    if ($text === false || !preg_match('/^usage_usec (\d+)/m', $text, $match)) {
        throw new RuntimeException('cgroup v2 CPU accounting unavailable');
    }
    return (int) $match[1];
};
$startCpu = $cpu();
$start = hrtime(true);
$peakRss = 0;
$peakCgroup = 0;
$workerPeaks = [];
$samples = 0;
echo "READY\n";
fflush(STDOUT);
do {
    $total = 0;
    foreach (glob('/proc/[0-9]*/status') as $file) {
        $pid = (int) basename(dirname($file));
        if ($pid === getmypid()) { continue; }
        $statusText = @file_get_contents($file);
        if ($statusText === false || !preg_match('/VmRSS:\s+(\d+)/', $statusText, $match)) { continue; }
        $rss = (int) $match[1] * 1024;
        $total += $rss;
        $command = @file_get_contents(dirname($file) . '/cmdline');
        if ($command !== false && (str_contains($command, '/pool-worker.php') || str_contains($command, '/transform.php'))) {
            $workerPeaks[$pid] = max($workerPeaks[$pid] ?? 0, $rss);
        }
    }
    $peakRss = max($peakRss, $total);
    $peakCgroup = max($peakCgroup, (int) file_get_contents('/sys/fs/cgroup/memory.current'));
    ++$samples;
    $read = [STDIN]; $write = $except = [];
    if (stream_select($read, $write, $except, 0, 10000) > 0) { break; }
} while (hrtime(true) - $start < 15_000_000_000);
echo json_encode([
    'cpu_seconds' => ($cpu() - $startCpu) / 1e6,
    'observer_seconds' => (hrtime(true) - $start) / 1e9,
    'sample_count' => $samples,
    'sample_interval_ms' => 10,
    'sampled_total_process_rss_peak_bytes' => $peakRss,
    'sampled_cgroup_memory_peak_bytes' => $peakCgroup,
    'worker_rss_peak_bytes_by_pid' => (object) $workerPeaks,
    'notes' => 'CPU includes observer and docker-exec overhead; RSS sums shared pages and excludes observer; cgroup includes page cache and observer. Sampled peaks may miss transients.',
], JSON_THROW_ON_ERROR) . "\n";
