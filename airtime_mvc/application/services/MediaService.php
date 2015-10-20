<?php

require_once('ProxyStorageBackend.php');
require_once("FileIO.php");

class Application_Service_MediaService
{
    /** Move (or copy) a file to the stor/organize directory and send it off to the
    analyzer to be processed.
     * @param $callbackUrl
     * @param $filePath string Path to the local file to import to the library
     * @param $originalFilename string The original filename, if you want it to be preserved after import.
     * @param $ownerId string The ID of the user that will own the file inside Airtime.
     * @param $copyFile bool True if you want to copy the file to the "organize" directory, false if you want to move it (default)
     * @return Ambigous
     * @throws Exception
     */
    public static function importFileToLibrary($callbackUrl, $filePath, $originalFilename, $ownerId, $copyFile)
    {
        $CC_CONFIG = Config::getConfig();
        $apiKey = $CC_CONFIG["apiKey"][0];

        $importedStorageDirectory = "";
        if ($CC_CONFIG["current_backend"] == "file") {
            $storDir = Application_Model_MusicDir::getStorDir();
            $importedStorageDirectory = $storDir->getDirectory() . "/imported/" . $ownerId;
        }

        //Copy the temporary file over to the "organize" folder so that it's off our webserver
        //and accessible by airtime_analyzer which could be running on a different machine.
        $newTempFilePath = Application_Model_StoredFile::moveFileToStor($filePath, $originalFilename, $copyFile);

        //Dispatch a message to airtime_analyzer through RabbitMQ,
        //notifying it that there's a new upload to process!
        $storageBackend = new ProxyStorageBackend($CC_CONFIG["current_backend"]);
        Application_Model_RabbitMq::SendMessageToAnalyzer($newTempFilePath,
            $importedStorageDirectory, basename($originalFilename),
            $callbackUrl, $apiKey,
            $CC_CONFIG["current_backend"],
            $storageBackend->getFilePrefix());

        return $newTempFilePath;
    }


    /**
     * @param $fileId
     * @param bool $inline Set the Content-Disposition header to inline to prevent a download dialog from popping up (or attachment if false)
     * @throws Exception
     * @throws FileNotFoundException
     */
    public static function streamFileDownload($fileId, $inline=false)
    {
        $media = Application_Model_StoredFile::RecallById($fileId);
        if ($media == null) {
            throw new FileNotFoundException();
        }
        // Make sure we don't have some wrong result because of caching
        clearstatcache();

        $filePath = "";

        if ($media->getPropelOrm()->isValidPhysicalFile()) {
            $filename = $media->getPropelOrm()->getFilename();

            //Download user left clicks a track and selects Download.
            if (!$inline) {
                //We are using Content-Disposition to specify
                //to the browser what name the file should be saved as.
                header('Content-Disposition: attachment; filename="' . $filename . '"');
            } else {
                //user clicks play button for track and downloads it.
                header('Content-Disposition: inline; filename="' . $filename . '"');
            }

            /*
            In this block of code below, we're getting the list of download URLs for a track
            and then streaming the file as the response. A file can be stored in more than one location,
            with the alternate locations used as a fallback, so that's why we're looping until we
            are able to actually send the file.

            This mechanism is used to try fetching our file from our internal S3 caching proxy server first.
            If the file isn't found there (or the cache is down), then we attempt to download the file
            directly from Amazon S3. We do this to save bandwidth costs!
            */

            $filePaths = $media->getFilePaths();
            assert(is_array($filePaths));

            do {
                //Read from $filePath and stream it to the browser.
                $filePath = array_shift($filePaths);
                try {
                    $size= $media->getFileSize();
                    $mimeType = $media->getPropelOrm()->getDbMime();
                    Application_Common_FileIO::smartReadFile($filePath, $size, $mimeType);
                    break; //Break out of the loop if we successfully read the file!
                } catch (FileNotFoundException $e) {
                    //If we have no alternate filepaths left, then let the exception bubble up.
                    if (sizeof($filePaths) == 0) {
                        throw $e;
                    }
                }
                //Retry with the next alternate filepath in the list
            } while (sizeof($filePaths) > 0);

            exit;

        } else {
            throw new FileNotFoundException($filePath);
        }
    }

    /**
     * Publish or remove the file with the given file ID from the services
     * specified in the request data (ie. SoundCloud, the station podcast)
     *
     * @param int $fileId   ID of the file to be published
     * @param array $data   request data containing what services to publish to
     */
    public static function publish($fileId, $data) {
        foreach ($data as $k => $v) {
            $service = PublishServiceFactory::getService($k);
            $service->$v($fileId);
        }
    }

}

