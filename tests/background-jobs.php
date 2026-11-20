<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Modules/BackgroundJobs.php';

use NZB\Modules\BackgroundJobs;
use NZB\Modules\WorkspaceFiles;

$jobRoot = str_replace('\\', '/', sys_get_temp_dir()) . '/nzbm-async-regression-' . bin2hex(random_bytes(6));
$jobWorkspace = $jobRoot . '/downloads';
$oldJobImport = getenv('NZB_IMPORT_ROOT');
$oldJobStorage = getenv('NZB_STORAGE_DIR');
mkdir($jobWorkspace, 0700, true);
putenv('NZB_IMPORT_ROOT=' . $jobRoot);
putenv('NZB_STORAGE_DIR=' . $jobRoot . '/nzb');
try
{
    \NZB\AppSettings::save(['workspace_path' => $jobWorkspace]);
    $work = new WorkspaceFiles($jobWorkspace);
    $path = $jobWorkspace . '/async-test.zip';
    $zip = new \ZipArchive();
    if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true)
    {
        throw new RuntimeException('Cannot create background ZIP fixture.');
    }
    $zip->addFromString('payload.txt', 'PHP-only background extraction');
    $zip->close();

    $id = BackgroundJobs::queue('archive_extract', $work, 'async-test.zip');
    check(preg_match('/\A[a-f0-9]{32}\z/D', $id) === 1, 'Async ZIP job creates an opaque private ID');
    $jobs = BackgroundJobs::all('archive_extract');
    check(count($jobs) === 1 && $jobs[0]['status'] === 'queued' && $jobs[0]['progress'] === 0,
        'Async jobs report queued 0% state before execution');
    BackgroundJobs::run($id);
    $jobs = BackgroundJobs::all('archive_extract');
    check($jobs[0]['status'] === 'completed' && $jobs[0]['progress'] === 100,
        'Async ZIP worker transitions to completed 100%');
    check(file_get_contents($jobWorkspace . '/async-test-extracted/payload.txt') === 'PHP-only background extraction',
        'Async ZIP worker produces the expected extracted contents');
    check(BackgroundJobs::all('par2') === [], 'PAR2 job monitor excludes archive jobs');
    expectFailure(static fn () => BackgroundJobs::rerun($id, 'par2', $work),
        'Archive job cannot be rerun from the PAR2 module');
    expectFailure(static fn () => BackgroundJobs::forget($id, 'par2'),
        'Archive job cannot be removed from the PAR2 module');
    $rerun = BackgroundJobs::rerun($id, 'archive_extract', $work);
    check($rerun !== $id && preg_match('/\A[a-f0-9]{32}\z/D', $rerun) === 1,
        'Finished archive job can be rerun as an independent queued job');
    expectFailure(static fn () => BackgroundJobs::forget($rerun, 'archive_extract'),
        'Queued archive job cannot be removed while still active');
    expectFailure(static fn () => BackgroundJobs::rerun($rerun, 'archive_extract', $work),
        'Queued archive job cannot be rerun again before finishing');
    BackgroundJobs::run($rerun);
    check(file_get_contents($jobWorkspace . '/async-test-extracted-1/payload.txt') === 'PHP-only background extraction',
        'Rerunning archive extraction creates a new numbered folder and preserves the original');
    BackgroundJobs::forget($id, 'archive_extract');
    check(count(array_filter(BackgroundJobs::all('archive_extract'),
        static fn (array $job): bool => $job['id'] === $id)) === 0,
        'Remove clears only the finished archive job record');
    check(is_file($jobWorkspace . '/async-test-extracted/payload.txt') &&
        is_file($jobWorkspace . '/async-test-extracted-1/payload.txt'),
        'Removing archive job history never deletes extracted output files');
    expectFailure(static fn () => BackgroundJobs::forget($id, 'archive_extract'),
        'Already removed job cannot be removed twice');
    BackgroundJobs::forget($rerun, 'archive_extract');
    check(BackgroundJobs::all('archive_extract') === [], 'Rerun history is independently removable');

    $cancelId = BackgroundJobs::queue('archive_extract', $work, 'async-test.zip');
    BackgroundJobs::requestCancel($cancelId);
    BackgroundJobs::run($cancelId);
    $cancelled = array_values(array_filter(BackgroundJobs::all('archive_extract'),
        static fn (array $job): bool => $job['id'] === $cancelId));
    check(count($cancelled) === 1 && $cancelled[0]['status'] === 'cancelled',
        'Async ZIP worker respects cancellation before publishing output');
    check(!is_file($jobWorkspace . '/async-test-extracted-2/payload.txt'),
        'Cancelled ZIP job does not publish a new folder');
    expectFailure(static fn () => BackgroundJobs::requestCancel($cancelId),
        'Completed background jobs cannot be cancelled after publication');
    expectFailure(static fn () => BackgroundJobs::queue('delete_workspace', $work, 'async-test.zip'),
        'Background queue only accepts allowlisted operations');
}
finally
{
    putenv($oldJobImport === false ? 'NZB_IMPORT_ROOT' : 'NZB_IMPORT_ROOT=' . $oldJobImport);
    putenv($oldJobStorage === false ? 'NZB_STORAGE_DIR' : 'NZB_STORAGE_DIR=' . $oldJobStorage);
    if (is_dir($jobRoot))
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($jobRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $item)
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
        @rmdir($jobRoot);
    }
}
