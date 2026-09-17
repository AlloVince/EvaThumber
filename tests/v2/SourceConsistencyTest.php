<?php

declare(strict_types=1);

namespace EvaThumber\Tests;

use EvaThumber\Cache\DiskCache;
use EvaThumber\Exception\ImageException;
use EvaThumber\Source\LocalSource;
use Jcupitt\Vips\Image;
use PHPUnit\Framework\TestCase;

final class SourceConsistencyTest extends TestCase
{
    public function testResolvedIdentityNeverDecodesReplacementBytes(): void
    {
        $root = sys_get_temp_dir() . '/eva-snapshot-' . bin2hex(random_bytes(6));
        mkdir($root);
        try {
            Image::black(40, 30, ['bands' => 3])->pngsave($root . '/foo.png');
            $original = (new LocalSource($root))->resolve('foo');
            Image::black(40, 30, ['bands' => 3])->add(200)->cast('uchar')->pngsave($root . '/new.png');
            rename($root . '/new.png', $root . '/foo.png');
            (new \EvaThumber\Image\Pipeline())->write($original, (new \EvaThumber\Transformation\Parser())->parse('f_png'), $root . '/output.png', 'png');
            self::assertSame(0.0, Image::newFromFile($root . '/output.png')->avg(), 'Old identity must decode its original bytes, never replacement bytes.');
        } finally {
            foreach (glob($root . '/*') as $file) { unlink($file); }
            rmdir($root);
        }
    }

    public static function replacements(): array
    {
        return [['rename', 'png', 'q_80'], ['inplace', 'png', 'q_80'], ['rename', 'jpg', 'q_auto'], ['inplace', 'jpg', 'q_auto']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('replacements')]
    public function testConcurrentAbaCannotCacheNewPixelsUnderOldIdentity(string $mode, string $format, string $expression): void
    {
        $root = sys_get_temp_dir() . '/eva-aba-' . bin2hex(random_bytes(6));
        mkdir($root); mkdir($root . '/cache');
        $process = null;
        $pipes = [];
        try {
            Image::black(512, 384, ['bands' => 3])->pngsave($root . '/foo.png', ['compression' => 0]);
            Image::black(512, 384, ['bands' => 3])->add(200)->cast('uchar')->pngsave($root . '/.b', ['compression' => 0]);
            self::assertSame(filesize($root . '/foo.png'), filesize($root . '/.b'));
            $local = new LocalSource($root);
            $original = $local->resolve('foo');
            $mtime = filemtime($root . '/foo.png');
            // Separate PHP process. B stays installed for the entire decode/encode;
            // only restore A after the parent has finished reading the source.
            $writer = <<<'PHP'
[$script, $root, $mode] = $argv;
$a = file_get_contents($root . '/foo.png');
$b = file_get_contents($root . '/.b');
$mtime = filemtime($root . '/foo.png');
if ($mode === 'rename') {
    rename($root . '/foo.png', $root . '/.a');
    rename($root . '/.b', $root . '/foo.png');
} else {
    $fp = fopen($root . '/foo.png', 'r+b');
    fwrite($fp, $b); fflush($fp); fclose($fp);
    touch($root . '/foo.png', $mtime);
}
echo "B\n"; fflush(STDOUT);
if (trim((string) fgets(STDIN)) !== 'restore') { exit(2); }
if ($mode === 'rename') {
    rename($root . '/foo.png', $root . '/.b');
    rename($root . '/.a', $root . '/foo.png');
} else {
    $fp = fopen($root . '/foo.png', 'r+b');
    fwrite($fp, $a); fflush($fp); fclose($fp);
    touch($root . '/foo.png', $mtime);
}
echo "A\n"; fflush(STDOUT);
PHP;
            $process = proc_open([PHP_BINARY, '-r', $writer, $root, $mode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            stream_set_timeout($pipes[1], 10);
            self::assertSame("B\n", fgets($pipes[1]));
            self::assertSame(200.0, Image::pngload($root . '/foo.png')->avg());
            $cache = new DiskCache($root . '/cache');
            $entry = $cache->remember($original->identity, $format, static function (string $out) use ($original, $pipes, $format, $expression): void {
                (new \EvaThumber\Image\Pipeline())->write($original, (new \EvaThumber\Transformation\Parser())->parse($expression), $out, $format);
                fwrite($pipes[0], "restore\n"); fflush($pipes[0]);
                self::assertSame("A\n", fgets($pipes[1]));
            });
            self::assertSame(0.0, Image::newFromFile($entry->path)->avg());
            self::assertSame($mtime, filemtime($root . '/foo.png'));
            self::assertSame($original->snapshot->digest, hash_file('sha256', $root . '/foo.png'));
            // ctime granularity can expose ABA; either rejection or original bytes is safe.
            try {
                $checked = $local->assertIdentity('foo', $original->identity);
                self::assertSame($original->snapshot->content, $checked->snapshot->content);
            } catch (ImageException $error) {
                self::assertSame('source_changed', $error->error);
            }
            unset($entry);
            $hit = $cache->remember($original->identity, $format, static fn () => self::fail('Expected cached original'));
            self::assertTrue($hit->hit);
            self::assertSame(0.0, Image::newFromFile($hit->path)->avg());
            unset($hit);
            foreach ($pipes as $pipe) { fclose($pipe); }
            $pipes = [];
            self::assertSame(0, proc_close($process));
            $process = null;
        } finally {
            if (is_resource($process)) { proc_terminate($process, 9); }
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            if (is_resource($process)) { proc_close($process); }
            foreach (new \DirectoryIterator($root . '/cache') as $file) { if (!$file->isDot()) { unlink($file->getPathname()); } }
            rmdir($root . '/cache');
            foreach (new \DirectoryIterator($root) as $file) { if (!$file->isDot()) { unlink($file->getPathname()); } }
            rmdir($root);
        }
    }

    public function testSnapshotDigestBytesAndLimitAreBoundTogether(): void
    {
        $root = sys_get_temp_dir() . '/eva-bound-' . bin2hex(random_bytes(6));
        mkdir($root);
        try {
            Image::black(40, 30, ['bands' => 3])->pngsave($root . '/foo.png');
            $bytes = (int) filesize($root . '/foo.png');
            $source = (new LocalSource($root, new \EvaThumber\Security\Limits(maxSourceBytes: $bytes)))->resolve('foo');
            self::assertSame($bytes, strlen($source->snapshot->content));
            self::assertSame(hash('sha256', $source->snapshot->content), $source->snapshot->digest);
            $stat = $source->snapshot->stat;
            self::assertSame(hash('sha256', $source->path . ':' . $stat['dev'] . ':' . $stat['ino'] . ':' . $stat['mtime'] . ':' . $stat['ctime'] . ':' . $stat['size'] . ':' . $source->snapshot->digest), $source->identity);
            try {
                (new \EvaThumber\Image\Pipeline(new \EvaThumber\Security\Limits(maxSourceBytes: $bytes - 1)))->write($source, (new \EvaThumber\Transformation\Parser())->parse('q_80'), $root . '/out', 'png');
                self::fail('Snapshot must obey the receiving Pipeline limit');
            } catch (ImageException $error) {
                self::assertSame(413, $error->status);
                self::assertFileDoesNotExist($root . '/out');
            }
            $this->expectException(ImageException::class);
            (new LocalSource($root, new \EvaThumber\Security\Limits(maxSourceBytes: $bytes - 1)))->resolve('foo');
        } finally {
            unlink($root . '/foo.png'); rmdir($root);
        }
    }

    public function testReadingAnOldAccessTimeDoesNotChangeIdentity(): void
    {
        $root = sys_get_temp_dir() . '/eva-atime-' . bin2hex(random_bytes(6));
        mkdir($root);
        try {
            Image::black(40, 30, ['bands' => 3])->jpegsave($root . '/foo.jpg');
            touch($root . '/foo.jpg', time() - 3600, time() - 86400);
            $local = new LocalSource($root);
            $source = $local->resolve('foo');
            self::assertSame($source->identity, $local->resolve('foo')->identity);
        } finally {
            unlink($root . '/foo.jpg');
            rmdir($root);
        }
    }

    public function testReplacementDuringProducerCannotPublishOldIdentity(): void
    {
        $root = sys_get_temp_dir() . '/eva-source-' . bin2hex(random_bytes(6));
        mkdir($root); mkdir($root . '/cache');
        try {
            Image::black(40, 30, ['bands' => 3])->jpegsave($root . '/foo.jpg');
            $local = new LocalSource($root);
            $original = $local->resolve('foo');
            $cache = new DiskCache($root . '/cache');
            try {
                $cache->remember($original->identity, 'jpg', static function (string $destination) use ($local, $original, $root): void {
                    $local->assertIdentity('foo', $original->identity);
                    Image::black(40, 30, ['bands' => 3])->add(200)->jpegsave($root . '/replacement.jpg');
                    rename($root . '/replacement.jpg', $root . '/foo.jpg');
                    copy($root . '/foo.jpg', $destination);
                    $local->assertIdentity('foo', $original->identity);
                });
                self::fail('Changed source must not publish');
            } catch (ImageException $error) {
                self::assertSame(409, $error->status);
                self::assertSame('source_changed', $error->error);
            }
            self::assertSame([], glob($root . '/cache/*.jpg'));
            self::assertSame([], glob($root . '/cache/.tmp-*'));
            $new = $local->resolve('foo');
            self::assertNotSame($original->identity, $new->identity);
            $entry = $cache->remember($new->identity, 'jpg', static fn (string $out) => copy($root . '/foo.jpg', $out));
            self::assertFalse($entry->hit);
            unset($entry);
        } finally {
            foreach (new \DirectoryIterator($root . '/cache') as $file) { if (!$file->isDot()) { unlink($file->getPathname()); } }
            rmdir($root . '/cache');
            foreach (glob($root . '/*') as $file) { unlink($file); }
            rmdir($root);
        }
    }
}
