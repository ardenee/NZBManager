<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/AppConfig.php';
require_once dirname(__DIR__) . '/src/AppSettings.php';
require_once dirname(__DIR__) . '/src/Modules/WorkspaceFiles.php';
require_once dirname(__DIR__) . '/src/Modules/ArchiveTools.php';
require_once dirname(__DIR__) . '/src/Modules/Par2Jobs.php';

use NZB\Modules\WorkspaceFiles;
use NZB\Modules\ArchiveTools;
use NZB\Modules\Par2Jobs;

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/nzbm-module-test-' . bin2hex(random_bytes(5));
$workspaceRoot = $root . '/downloads';
$inbox = $workspaceRoot . '/inbox';
$dest = $workspaceRoot . '/sorted';
mkdir($inbox, 0700, true);
mkdir($dest, 0700, true);

$previousImportRoot = getenv('NZB_IMPORT_ROOT');
$previousStorageDir = getenv('NZB_STORAGE_DIR');
$previousBinary = getenv('NZB_PAR2_BINARY');
$previousRepairFlag = getenv('NZB_PAR2_REPAIR_ENABLED');

putenv('NZB_IMPORT_ROOT=' . $root);
putenv('NZB_STORAGE_DIR=' . $root . '/nzb');
putenv('NZB_PAR2_REPAIR_ENABLED=0');
try
{
    $validated = \NZB\AppSettings::validate(['workspace_path' => $workspaceRoot]);
    check($validated['workspace_path'] === $workspaceRoot, 'Workspace root passes bounded Settings validation');
    expectFailure(static fn () => new WorkspaceFiles($root . '/../etc'), 'Workspace rejects parent traversal');
    $work = new WorkspaceFiles($workspaceRoot);
    check(count($work->list('')) === 2, 'Workspace lists only existing child directories');
    expectFailure(static fn () => $work->resolve('../outside'), 'Workspace traversal rejected');
    expectFailure(static fn () => $work->resolve('/etc/passwd'), 'Workspace absolute path rejected');
    expectFailure(static fn () => WorkspaceFiles::relative('inbox/../../outside'), 'Workspace nested traversal rejected');

    file_put_contents($inbox . '/unpacked.part1', 'test segment');
    $plan = $work->plan('inbox/unpacked.part1', 'sorted', 'renamed.part1');
    check($plan['source'] === 'inbox/unpacked.part1' && $plan['destination'] === 'sorted/renamed.part1', 'Organizer computes move/rename preview');
    check(is_file($inbox . '/unpacked.part1'), 'Organizer preview never moves source');
    check($work->apply($plan) === 'sorted/renamed.part1' && is_file($dest . '/renamed.part1'), 'Organizer applies atomic move/rename');
    check(!file_exists($inbox . '/unpacked.part1'), 'Organizer removes source only after destination exists');
    file_put_contents($inbox . '/collision.txt', 'different');
    file_put_contents($dest . '/collision.txt', 'protected');
    expectFailure(static fn () => $work->plan('inbox/collision.txt', 'sorted', 'collision.txt'), 'Organizer refuses overwriting existing file');
    file_put_contents($inbox . '/mutable.txt', 'first');
    $stale = $work->plan('inbox/mutable.txt', 'sorted', 'mutable.txt');
    file_put_contents($inbox . '/mutable.txt', 'different content');
    clearstatcache(true);
    expectFailure(static fn () => $work->apply($stale), 'Organizer rejects changed file after preview');

    if (class_exists(\ZipArchive::class))
    {
        $zipPath = $inbox . '/payload.zip';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true)
        {
            throw new RuntimeException('Unable to prepare test ZIP.');
        }
        $zip->addFromString('nested/hello.txt', 'valid ZIP content');
        $zip->addFromString('another.txt', 'extra');
        $zip->close();

        $preview = ArchiveTools::inspect($work, 'inbox/payload.zip');
        check($preview['supported'] && $preview['count'] === 2, 'ZIP inspection lists validated members');
        check($preview['bytes'] === strlen('valid ZIP content') + strlen('extra'), 'ZIP inspection sums expanded member sizes');
        $output = ArchiveTools::extract($work, 'inbox/payload.zip');
        check($output === 'payload-extracted' && file_get_contents($inbox . '/payload-extracted/nested/hello.txt') === 'valid ZIP content', 'ZIP extraction streams valid nested entries to a new folder');
        $second = ArchiveTools::extract($work, 'inbox/payload.zip');
        check($second === 'payload-extracted-1' && is_file($inbox . '/payload-extracted-1/another.txt'), 'Repeated extraction never overwrites existing output');
        check(is_file($zipPath), 'ZIP extraction never deletes the original archive');
        $badZip = $inbox . '/traversal.zip';
        $bad = new \ZipArchive();
        $bad->open($badZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $bad->addFromString('../escape.txt', 'unsafe');
        $bad->close();
        expectFailure(static fn () => ArchiveTools::inspect($work, 'inbox/traversal.zip'), 'ZIP traversal entry rejected before writing files');
        check(!file_exists($workspaceRoot . '/escape.txt'), 'ZIP traversal does not write outside extraction folder');
    }
    check(ArchiveTools::recognized('release.rar') && ArchiveTools::recognized('release.7z') && ArchiveTools::recognized('release.tar.gz'), 'Archive module recognizes RAR, 7z and TAR formats');
    check(!ArchiveTools::supported('release.rar'), 'RAR extraction is unavailable without a separate adapter');

    $par2Path = $inbox . '/release.par2';
    $header = str_pad("PAR2\0PKT", 64, "\0");
    $header = substr_replace($header, str_repeat("\x42", 16), 32, 16);
    $header = substr_replace($header, "PAR 2.0\0Main\0\0\0\0", 48, 16);
    file_put_contents($par2Path, $header);
    $info = Par2Jobs::inspect($work, 'inbox/release.par2');
    check($info['set_id'] === str_repeat('42', 16) && $info['bytes'] === 64, 'PAR2 scanner reads set ID from valid packet header');
    file_put_contents($inbox . '/invalid.par2', 'not a par2 packet');
    expectFailure(static fn () => Par2Jobs::inspect($work, 'inbox/invalid.par2'), 'PAR2 scanner rejects malformed headers');
    expectFailure(static fn () => Par2Jobs::submit($work, 'inbox/release.par2', 'repair'), 'PAR2 repair requires explicit operator opt-in');
    putenv('NZB_PAR2_BINARY=' . PHP_BINARY);
    if (Par2Jobs::binary() !== null)
    {
        $job = Par2Jobs::submit($work, 'inbox/release.par2', 'verify');
        check(preg_match('/\A[a-f0-9]{32}\z/D', $job) === 1, 'PAR2 verification enqueues an opaque private job');
        $jobs = Par2Jobs::all();
        check(count($jobs) === 1 && $jobs[0]['status'] === 'queued' && $jobs[0]['mode'] === 'verify', 'PAR2 job metadata is private and queued');
        if (function_exists('proc_open') && PHP_SAPI === 'cli')
        {
            check(Par2Jobs::runOne() === true, 'PAR2 CLI worker accepts one queued job');
            $finished = Par2Jobs::all();
            check($finished[0]['status'] === 'failed', 'PAR2 worker records external executable failure without claiming a repair');
            check(Par2Jobs::runOne() === false, 'PAR2 worker does not rerun finished jobs');
        }
    }
}
finally
{
    foreach ([$previousImportRoot => 'NZB_IMPORT_ROOT', $previousStorageDir => 'NZB_STORAGE_DIR', $previousBinary => 'NZB_PAR2_BINARY', $previousRepairFlag => 'NZB_PAR2_REPAIR_ENABLED'] as $old => $key)
    {
        // Restored below without depending on false/empty values as map keys.
    }
    foreach ([
        'NZB_IMPORT_ROOT' => $previousImportRoot, 'NZB_STORAGE_DIR' => $previousStorageDir,
        'NZB_PAR2_BINARY' => $previousBinary, 'NZB_PAR2_REPAIR_ENABLED' => $previousRepairFlag
    ] as $key => $old)
    {
        putenv($old === false ? $key : $key . '=' . $old);
    }
    if (is_dir($root))
    {
        $children = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($children as $item)
        {
            if ($item->isDir() && !$item->isLink())
            {
                @rmdir($item->getPathname());
            }
            else
            {
                @unlink($item->getPathname());
            }
        }
        @rmdir($root);
    }
}
