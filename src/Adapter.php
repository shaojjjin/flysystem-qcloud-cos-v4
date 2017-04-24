<?php

namespace Freyo\Flysystem\QcloudCOSv4;

use Freyo\Flysystem\QcloudCOSv4\Client\Conf;
use Freyo\Flysystem\QcloudCOSv4\Client\Cosapi;
use Freyo\Flysystem\QcloudCOSv4\Exceptions\RuntimeException;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use League\Flysystem\Util;

/**
 * Class Adapter.
 */
class Adapter extends AbstractAdapter
{
    /**
     * @var
     */
    protected $bucket;

    /**
     * @var
     */
    protected $debug;

    /**
     * Adapter constructor.
     *
     * @param $config
     */
    public function __construct($config)
    {
        Conf::setAppId($config['app_id']);
        Conf::setSecretId($config['secret_id']);
        Conf::setSecretKey($config['secret_key']);

        $this->bucket = $config['bucket'];
        $this->debug  = $config['debug'];

        $this->setPathPrefix($config['protocol'] . '://' . $config['domain'] . '/');

        Cosapi::setTimeout($config['timeout']);
        Cosapi::setRegion($config['region']);
    }

    /**
     * @return mixed
     */
    public function getBucket()
    {
        return $this->bucket;
    }

    /**
     * @param $path
     *
     * @return string
     */
    public function getUrl($path)
    {
        return $this->applyPathPrefix($path);
    }

    /**
     * @param string $path
     * @param string $contents
     * @param Config $config
     *
     * @throws RuntimeException
     *
     * @return array|false
     */
    public function write($path, $contents, Config $config)
    {
        $tmpfname = $this->writeTempFile($contents);

        try {
            $response = Cosapi::upload($this->getBucket(), $tmpfname, $path,
                    null, null, $config->get('insertOnly', 1));

            $this->deleteTempFile($tmpfname);

            $response = $this->normalizeResponse($response);

            if (false === $response) {
                return false;
            }
            
            $this->setContentType($path, $contents);
        } catch (RuntimeException $exception) {
            $this->deleteTempFile($tmpfname);

            throw $exception;
        }

        return $response;
    }

    /**
     * @param string   $path
     * @param resource $resource
     * @param Config   $config
     *
     * @throws RuntimeException
     *
     * @return array|false
     */
    public function writeStream($path, $resource, Config $config)
    {
        $uri = stream_get_meta_data($resource)['uri'];

        $response = Cosapi::upload($this->getBucket(), $uri, $path,
                null, null, $config->get('insertOnly', 1));

        $response = $this->normalizeResponse($response);

        if (false === $response) {
            return false;
        }        
        
        $this->setContentType($path, stream_get_contents($resource));

        return $response;
    }

    /**
     * @param string $path
     * @param string $contents
     * @param Config $config
     *
     * @throws RuntimeException
     *
     * @return array|false
     */
    public function update($path, $contents, Config $config)
    {
        $tmpfname = $this->writeTempFile($contents);

        try {
            $response = Cosapi::upload($this->getBucket(), $tmpfname, $path,
                    null, null, $config->get('insertOnly', 0));

            $this->deleteTempFile($tmpfname);

            $response = $this->normalizeResponse($response);
            
            if (false === $response) {
                return false;
            }
            
            $this->setContentType($path, $contents);
        } catch (RuntimeException $exception) {
            $this->deleteTempFile($tmpfname);

            throw $exception;
        }

        return $response;
    }

    /**
     * @param string   $path
     * @param resource $resource
     * @param Config   $config
     *
     * @throws RuntimeException
     *
     * @return array|false
     */
    public function updateStream($path, $resource, Config $config)
    {
        $uri = stream_get_meta_data($resource)['uri'];

        $response = Cosapi::upload($this->getBucket(), $uri, $path,
                null, null, $config->get('insertOnly', 0));

        $response = $this->normalizeResponse($response);

        if (false === $response) {
            return false;
        }        
        
        $this->setContentType($path, stream_get_contents($resource));

        return $response;
    }

    /**
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function rename($path, $newpath)
    {
        return $this->normalizeResponse(
            Cosapi::moveFile($this->getBucket(), $path, $newpath, 1)
        );
    }

    /**
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function copy($path, $newpath)
    {
        return $this->normalizeResponse(
            Cosapi::copyFile($this->getBucket(), $path, $newpath, 1)
        );
    }

    /**
     * @param string $path
     *
     * @return bool
     */
    public function delete($path)
    {
        return $this->normalizeResponse(
            Cosapi::delFile($this->getBucket(), $path)
        );
    }

    /**
     * @param string $dirname
     *
     * @return bool
     */
    public function deleteDir($dirname)
    {
        return $this->normalizeResponse(
            Cosapi::delFolder($this->getBucket(), $dirname)
        );
    }

    /**
     * @param string $dirname
     * @param Config $config
     *
     * @return bool
     */
    public function createDir($dirname, Config $config)
    {
        return $this->normalizeResponse(
            Cosapi::createFolder($this->getBucket(), $dirname)
        );
    }

    /**
     * @param string $path
     * @param string $visibility
     *
     * @return bool
     */
    public function setVisibility($path, $visibility)
    {
        $visibility = $visibility === AdapterInterface::VISIBILITY_PUBLIC ? 'eWPrivateRPublic' : 'eWRPrivate';

        return $this->normalizeResponse(
            Cosapi::update($this->getBucket(), $path, null, $visibility)
        );
    }

    /**
     * @param string $path
     *
     * @return bool
     */
    public function has($path)
    {
        try {
            return (bool)$this->getMetadata($path);
        } catch (RuntimeException $exception) {
            return false;
        }
    }

    /**
     * @param string $path
     *
     * @return array
     */
    public function read($path)
    {
        return ['contents' => file_get_contents($this->getUrl($path))];
    }

    /**
     * @param string $path
     *
     * @return array
     */
    public function readStream($path)
    {
        return ['stream' => fopen($this->getUrl($path), 'r')];
    }

    /**
     * @param string $directory
     * @param bool   $recursive
     *
     * @return array|bool
     */
    public function listContents($directory = '', $recursive = false)
    {
        return $this->normalizeResponse(
            Cosapi::listFolder($this->getBucket(), $directory)
        );
    }

    /**
     * @param string $path
     *
     * @return array|bool
     */
    public function getMetadata($path)
    {
        return $this->normalizeResponse(
            Cosapi::stat($this->getBucket(), $path)
        );
    }

    /**
     * @param string $path
     *
     * @return array
     */
    public function getSize($path)
    {
        $stat = $this->getMetadata($path);

        if ($stat) {
            return ['size' => $stat['filesize']];
        }

        return ['size' => 0];
    }

    /**
     * @param string $path
     *
     * @return array
     */
    public function getMimetype($path)
    {
        $stat = $this->getMetadata($path);

        if ($stat && !empty($stat['custom_headers']) && !empty($stat['custom_headers']['Content-Type'])) {
            return ['mimetype' => $stat['custom_headers']['Content-Type']];
        }

        return ['mimetype' => ''];
    }

    /**
     * @param string $path
     *
     * @return array
     */
    public function getTimestamp($path)
    {
        $stat = $this->getMetadata($path);

        if ($stat) {
            return ['timestamp' => $stat['ctime']];
        }

        return ['timestamp' => 0];
    }

    /**
     * @param string $path
     *
     * @return array
     */
    public function getVisibility($path)
    {
        $stat = $this->getMetadata($path);

        $visibility = AdapterInterface::VISIBILITY_PRIVATE;

        if ($stat && $stat['authority'] === 'eWPrivateRPublic') {
            $visibility = AdapterInterface::VISIBILITY_PUBLIC;
        }

        return ['visibility' => $visibility];
    }

    /**
     * @param $content
     *
     * @return string|false
     */
    private function writeTempFile($content)
    {
        $tmpfname = tempnam('/tmp', 'dir');

        chmod($tmpfname, 0777);

        file_put_contents($tmpfname, $content);

        return $tmpfname;
    }

    /**
     * @param $tmpfname
     *
     * @return bool
     */
    private function deleteTempFile($tmpfname)
    {
        return unlink($tmpfname);
    }

    /**
     * @param $path
     * @param $content
     *
     * @return bool
     */
    protected function setContentType($path, $content)
    {
        $custom_headers = [
            'Content-Type' => Util::guessMimeType($path, $content),
        ];

        return $this->normalizeResponse(
            Cosapi::update($this->getBucket(), $path, null, null, $custom_headers)
        );
    }

    /**
     * @param $response
     *
     * @throws RuntimeException
     *
     * @return mixed
     */
    protected function normalizeResponse($response)
    {
        if ($response['code'] == 0) {
            return isset($response['data']) ? $response['data'] : true;
        }

        if ($this->debug) {
            throw new RuntimeException($response['message'], $response['code']);
        }

        return false;
    }
}
