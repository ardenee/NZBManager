<?php
declare(strict_types=1);

namespace NZB\Modules;

use NZB\DownloadClients\DownloadStationClient;
use NZB\NzbStorage;
use RuntimeException;
use Throwable;

final class DownloadStationActions
{
    public static function available(): bool
    {
        try
        {
            $client = DownloadStationClient::fromEnvironment();
            if ($client === null)
            {
                return false;
            }
            $cache = $_SESSION['download_station_available'] ?? null;
            if (is_array($cache) && is_int($cache['at'] ?? null) &&
                $cache['at'] <= time() && time() - $cache['at'] < 45)
            {
                return $cache['available'] === true;
            }
            $available = $client->discover() !== null;
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
        $return = $viewId !== '' && preg_match('/\A[a-f0-9]{32}\z/D', $viewId)
            ? 'index.php?view=' . $viewId : 'index.php';
        try
        {
            $client = DownloadStationClient::fromEnvironment();
            if ($client === null)
            {
                throw new RuntimeException('Download Station is not configured.');
            }
            $id = $_POST['id'] ?? null;
            $destination = $_POST['destination'] ?? '';
            if (!is_string($id) || !is_string($destination))
            {
                throw new RuntimeException('Invalid NZB submission.');
            }
            $item = $store->info($id);
            $client->submit($store->raw($id), $item['name'], $destination);
            \message('NZB submitted to Synology Download Station.');
        }
        catch (Throwable $error)
        {
            \message($error->getMessage(), 'error');
        }
        \redirectTo($return);
    }
}
