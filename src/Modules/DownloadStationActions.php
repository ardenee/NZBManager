<?php
declare(strict_types=1);

namespace NZB\Modules;

use NZB\DownloadClients\DownloadStationClient;
use NZB\DownloadClients\DownloadStationSettings;
use NZB\NzbStorage;
use RuntimeException;
use Throwable;

final class DownloadStationActions
{
    /** A package can be detected without having any user credentials. */
    public static function available(): bool
    {
        try
        {
            $cache = $_SESSION['download_station_available'] ?? null;
            if (is_array($cache) && is_int($cache['at'] ?? null) &&
                $cache['at'] <= time() && time() - $cache['at'] < 45)
            {
                return $cache['available'] === true;
            }
            $client = DownloadStationClient::fromEnvironment();
            if ($client === null && \NZB\AppConfig::privateDir() !== null)
            {
                // Query package discovery locally without performing authentication.
                $client = new DownloadStationClient('http://127.0.0.1:5000', 'probe', 'probe');
            }
            $available = $client !== null && $client->discover() !== null;
            $_SESSION['download_station_available'] = ['at' => time(), 'available' => $available];
            return $available;
        }
        catch (Throwable)
        {
            $_SESSION['download_station_available'] = ['at' => time(), 'available' => false];
            return false;
        }
    }

    public static function dispatch(NzbStorage $store, string $viewId): void
    {
        $action = $_POST['action'] ?? '';
        $return = in_array($action, ['downloadstation_save', 'downloadstation_clear'], true)
            ? 'index.php?module=settings' : 'index.php?module=download-client';
        try
        {
            if ($action === 'downloadstation_save')
            {
                $user = $_POST['dsm_user'] ?? null;
                $password = $_POST['dsm_password'] ?? null;
                if (!is_string($user) || !is_string($password))
                {
                    throw new RuntimeException('Enter an account permitted to use Download Station.');
                }
                DownloadStationSettings::save($user, $password);
                unset($_SESSION['download_station_available']);
                \message('Download Station connection saved in package-private storage.');
            }
            elseif ($action === 'downloadstation_clear')
            {
                DownloadStationSettings::clear();
                unset($_SESSION['download_station_available']);
                \message('Saved Download Station connection removed.');
            }
            else
            {
                $client = DownloadStationClient::fromEnvironment();
                if ($client === null)
                {
                    throw new RuntimeException('Configure a dedicated Download Station account in Settings.');
                }
                if ($action === 'downloadstation_submit')
                {
                    $id = $_POST['id'] ?? null;
                    $destination = $_POST['destination'] ?? '';
                    if (!is_string($id) || !is_string($destination))
                    {
                        throw new RuntimeException('Select an NZB and optional destination.');
                    }
                    $item = $store->info($id);
                    $client->submit($store->raw($id), $item['name'], $destination);
                    \message('NZB submitted directly to Synology Download Station.');
                }
                elseif (in_array($action, ['downloadstation_pause', 'downloadstation_resume', 'downloadstation_delete'], true))
                {
                    $taskId = $_POST['task_id'] ?? null;
                    if (!is_string($taskId))
                    {
                        throw new RuntimeException('Select a Download Station task.');
                    }
                    $method = substr($action, strlen('downloadstation_'));
                    $client->controlTask($method, $taskId);
                    \message('Download Station task ' . $method . ' completed.');
                }
                else
                {
                    throw new RuntimeException('Unsupported Download Station operation.');
                }
            }
        }
        catch (Throwable $error)
        {
            \message($error->getMessage(), 'error');
        }
        \redirectTo($return);
    }
}
