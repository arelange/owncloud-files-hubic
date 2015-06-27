<?php

/**
 * ownCloud
 *
 * @author Alexandre Relange
 * @copyright 2013 Christian Berendt berendt@b1-systems.de
 * @copyright 2014 Alexandre Relange alexandre@relange.org
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace OC\Files\Storage;

use Guzzle\Http\Client;
use Guzzle\Http\Exception\ClientErrorResponseException;
use OpenCloud;
use OpenCloud\Common\Exceptions;
use OpenCloud\OpenStack;
use OpenCloud\ObjectStore\Resource\DataObject;
use OpenCloud\ObjectStore\Exception;
use OpenCloud\Common\Service\Endpoint;
use OpenCloud\Common\Service\CatalogItem;
use OpenCloud\Common\Service\Catalog;

class Hubic extends \OC\Files\Storage\Common {

	/**
	 * @var \OpenCloud\ObjectStore\Service
	 */
	private $connection;
	/**
	 * @var \OpenCloud\ObjectStore\Resource\Container
	 */
	private $container;
	/**
	 * @var \OpenCloud\OpenStack
	 */
	private $anchor;
	/**
	 * @var string
	 */
	private $bucket;
	/**
	 * Connection parameters
	 *
	 * @var array
	 */
	private $params;
	/**
	 * @var array
	 */
	private static $tmpFiles = array();

	/**
	 * @param string $path
	 */
	private function normalizePath($path) {
		$path = trim($path, '/');

		if (!$path) {
			$path = '.';
		}

		$path = str_replace('#', '%23', $path);

		return $path;
	}

	const SUBCONTAINER_FILE = '.subcontainers';

	/**
	 * saves mount configuration
	 */
	private function saveConfig() {
		// we read all mountpoints
		$mountPoints = \OC_Mount_Config::getAbsoluteMountPoints(\OCP\User::getUser());
		// we use the refresh token as unique ID
		$new_refresh_token = json_decode($this->params['hubic_token'], TRUE)['refresh_token'];
		foreach ($mountPoints as $mountPoint => $options) {
			if (($options['class'] == '\\OC\\Files\\Storage\\Hubic') &&
				(json_decode(   $options['options']['hubic_token'], TRUE)['refresh_token'] == $new_refresh_token)) {
				$shortMountPoint = end(explode('/', $mountPoint,4));
				if ($options['personal']) {
					$mountType = \OC_Mount_Config::MOUNT_TYPE_USER;
					$applicable = \OCP\User::getUser();
				} else {
					$mountType = $options['priority_type'];
					$applicable = $options['applicable'];
				}
				\OC_Mount_Config::addMountPoint($shortMountPoint,
						$options['class'],
						$this->params,
						$mountType,
						$applicable,
						$options['personal'],
						$options['priority']);
				break;
			}
		}

	}

	/**
	 * refresh OAuth2 access token
	 */
	private function refreshToken() {
		$refresh_token = json_decode($this->params['hubic_token'], TRUE)['refresh_token'];
		$hubicAuthClient = new Client('https://api.hubic.com');
		$resRefresh = $hubicAuthClient->post('oauth/token',
			array(),
			array('refresh_token' => $refresh_token,
				'grant_type' => 'refresh_token'),
			array('auth' => array($this->params['client_id'],$this->params['client_secret']))
		)->send();

		$hubic_token = $resRefresh->json();
		$hubic_token['refresh_token'] = $refresh_token;
		$this->params['hubic_token'] = json_encode($hubic_token);
	}

	/**
	 * import swift credentials in OpenStack object
	 */
	private function importCredentials() {
		$endpoint = json_decode($this->params['swift_token'], TRUE)['endpoint'];
		$token    = json_decode($this->params['swift_token'], TRUE)['token'];
		$this->anchor->importCredentials(array(
			'token' => $token,
			'catalog' => array(
				(object) array(
					'endpoints' => array(
						(object) array(
							'region'      => 'NCE',
							'publicURL'   => $endpoint
						)
					),
					'name' => 'cloudFiles',
					'type' => 'object-store'
				)
			)
		));
	}

	/**
	 * retreives OpenStack credentials from Hubic authentification server
	 */
	private function retreiveCredentials() {
		try {
			// refresh credentials
			$access_token = json_decode($this->params['hubic_token'], TRUE)['access_token'];
			$hubicAuthClient = new Client('https://api.hubic.com');
			$resCred = $hubicAuthClient->get('1.0/account/credentials',
				array('Authorization' => 'Bearer '.$access_token)
			)->send();
		} catch (ClientErrorResponseException $e) {
			$this->refreshToken();
			$access_token = json_decode($this->params['hubic_token'], TRUE)['access_token'];
			$resCred = $hubicAuthClient->get('1.0/account/credentials',
				array('Authorization' => 'Bearer '.$access_token)
			)->send();
		}
		$this->params['swift_token'] = $resCred->getBody(TRUE);
		self::saveConfig();
		$this->importCredentials();
	}

	/**
	 * translate directory path to container name
	 * @param string $path
	 * @return string
	 */
	private function getContainerName($path) {
		$path = trim(trim($this->root, '/') . "/".$path, '/.');
		return str_replace('/', '\\', $path);
	}

	/**
	 * @param string $path
	 */
	private function doesObjectExist($path) {
		try {
			$this->getContainer()->getPartialObject($path);
			return true;
		} catch (ClientErrorResponseException $e) {
			\OCP\Util::writeLog('files_hubic', $e->getMessage(), \OCP\Util::ERROR);
			return false;
		}
	}

	public function __construct($params) {
		if (isset($params['configured']) && $params['configured'] === 'true'
			&& isset($params['client_id']) && isset($params['client_secret'])
			&& isset($params['hubic_token'])
		) {
			$this->id = 'hubic::' . $params['client_id'] . md5('default');
			$this->bucket = 'default';
			$this->params = $params;

		} else {
			throw new \Exception('Creating \OC\Files\Storage\Hubic storage failed');
		}

	}

	public function mkdir($path) {
		$path = $this->normalizePath($path);

		if ($this->is_dir($path)) {
			return false;
		}

		try {
			$customHeaders = array('content-type' => 'application/directory');
			$metadataHeaders = DataObject::stockHeaders(array());
			$allHeaders = $customHeaders + $metadataHeaders;
			$this->getContainer()->uploadObject($path, '', $allHeaders);
		} catch (Exceptions\CreateUpdateError $e) {
			\OCP\Util::writeLog('files_hubic', $e->getMessage(), \OCP\Util::ERROR);
			return false;
		}

		return true;
	}

	public function file_exists($path) {
		$path = $this->normalizePath($path);

		return $this->doesObjectExist($path);
	}

	public function rmdir($path) {
		$path = $this->normalizePath($path);

		if (!$this->is_dir($path)) {
			return false;
		}

		$dh = $this->opendir($path);
		while ($file = readdir($dh)) {
			if ($file === '.' || $file === '..') {
				continue;
			}

			if ($this->is_dir($path . '/' . $file)) {
				$this->rmdir($path . '/' . $file);
			} else {
				$this->unlink($path . '/' . $file);
			}
		}

		try {
			$this->getContainer()->dataObject()->setName($path)->delete();
		} catch (Exceptions\DeleteError $e) {
			\OCP\Util::writeLog('files_hubic', $e->getMessage(), \OCP\Util::ERROR);
			return false;
		}

		return true;
	}

	public function opendir($path) {
		$path = $this->normalizePath($path);

		if ($path === '.') {
			$path = '';
		} else {
			$path .= '/';
		}

		$path = str_replace('%23', '#', $path); // the prefix is sent as a query param, so revert the encoding of #

		try {
			$files = array();
			/** @var OpenCloud\Common\Collection $objects */
			$objects = $this->getContainer()->objectList(array(
				'prefix' => $path,
				'delimiter' => '/'
			));

			/** @var OpenCloud\ObjectStore\Resource\DataObject $object */
			foreach ($objects as $object) {
				$file = basename($object->getName());
				if ($file !== basename($path)) {
					$files[] = $file;
				}
			}

			\OC\Files\Stream\Dir::register('swift' . $path, $files);
			return opendir('fakedir://swift' . $path);
		} catch (Exception $e) {
			\OCP\Util::writeLog('files_hubic', $e->getMessage(), \OCP\Util::ERROR);
			return false;
		}

	}

	public function stat($path) {
		$path = $this->normalizePath($path);

		try {
			$object = $this->getContainer()->getPartialObject($path);
		} catch (ClientErrorResponseException $e) {
			\OCP\Util::writeLog('files_hubic', $e->getMessage(), \OCP\Util::ERROR);
			return false;
		}

		$dateTime = \DateTime::createFromFormat(\DateTime::RFC1123, $object->getLastModified());
		if ($dateTime !== false) {
			$mtime = $dateTime->getTimestamp();
		} else {
			$mtime = null;
		}
		$objectMetadata = $object->getMetadata();
		$metaTimestamp = $objectMetadata->getProperty('timestamp');
		if (isset($metaTimestamp)) {
			$mtime = $metaTimestamp;
		}

		if (!empty($mtime)) {
			$mtime = floor($mtime);
		}

		$stat = array();
		$stat['size'] = (int)$object->getContentLength();
		$stat['mtime'] = $mtime;
		$stat['atime'] = time();
		return $stat;
	}

	public function filetype($path) {
		$path = $this->normalizePath($path);

		if ($this->doesObjectExist($path)) {
                    $object = $this->container->getPartialObject($path);
                    if ($object->getContentType() == 'application/directory') {
                        return 'dir';
                    }
                    elseif ($object->getContentType() == 'httpd/unix-directory') {
                        return 'dir';
                    }
                    else {
                        return 'file';
                    }
                }
	}

	public function unlink($path) {
		$path = $this->normalizePath($path);

		if ($this->is_dir($path)) {
			return $this->rmdir($path);
		}

		try {
			$this->getContainer()->dataObject()->setName($path)->delete();
		} catch (ClientErrorResponseException $e) {
			\OCP\Util::writeLog('files_hubic', $e->getMessage(), \OCP\Util::ERROR);
			return false;
		}

		return true;
	}

	public function fopen($path, $mode) {
		$path = $this->normalizePath($path);

		switch ($mode) {
			case 'r':
			case 'rb':
				$tmpFile = \OC_Helper::tmpFile();
				self::$tmpFiles[$tmpFile] = $path;
				try {
					$object = $this->getContainer()->getObject($path);
				} catch (ClientErrorResponseException $e) {
					\OCP\Util::writeLog('files_hubic', $e->getMessage(), \OCP\Util::ERROR);
					return false;
				} catch (Exception\ObjectNotFoundException $e) {
					\OCP\Util::writeLog('files_hubic', $e->getMessage(), \OCP\Util::ERROR);
					return false;
				}
				try {
					$objectContent = $object->getContent();
					$objectContent->rewind();
					$stream = $objectContent->getStream();
					file_put_contents($tmpFile, $stream);
				} catch (Exceptions\IOError $e) {
					\OCP\Util::writeLog('files_hubic', $e->getMessage(), \OCP\Util::ERROR);
					return false;
				}
				return fopen($tmpFile, 'r');
			case 'w':
			case 'wb':
			case 'a':
			case 'ab':
			case 'r+':
			case 'w+':
			case 'wb+':
			case 'a+':
			case 'x':
			case 'x+':
			case 'c':
			case 'c+':
				if (strrpos($path, '.') !== false) {
					$ext = substr($path, strrpos($path, '.'));
				} else {
					$ext = '';
				}
				$tmpFile = \OC_Helper::tmpFile($ext);
				\OC\Files\Stream\Close::registerCallback($tmpFile, array($this, 'writeBack'));
				if ($this->file_exists($path)) {
					$source = $this->fopen($path, 'r');
					file_put_contents($tmpFile, $source);
				}
				self::$tmpFiles[$tmpFile] = $path;

				return fopen('close://' . $tmpFile, $mode);
		}
	}

	public function getMimeType($path) {
		$path = $this->normalizePath($path);

		if ($this->is_dir($path)) {
			return 'httpd/unix-directory';
		} else if ($this->file_exists($path)) {
			$object = $this->getContainer()->getPartialObject($path);
			return $object->getContentType();
		}
		return false;
	}

	public function touch($path, $mtime = null) {
		$path = $this->normalizePath($path);
		if (is_null($mtime)) {
			$mtime = time();
		}
		$metadata = array('timestamp' => $mtime);
		if ($this->file_exists($path)) {

			$object = $this->getContainer()->getPartialObject($path);
			$object->saveMetadata($metadata);
			return true;
		} else {
			$mimeType = \OC_Helper::getMimetypeDetector()->detectPath($path);
			$customHeaders = array('content-type' => $mimeType);
			$metadataHeaders = DataObject::stockHeaders($metadata);
			$allHeaders = $customHeaders + $metadataHeaders;
			$this->getContainer()->uploadObject($path, '', $allHeaders);
			return true;
		}
	}

	public function copy($path1, $path2) {
		$path1 = $this->normalizePath($path1);
		$path2 = $this->normalizePath($path2);

		$fileType = $this->filetype($path1);
		if ($fileType === 'file') {

			// make way
			$this->unlink($path2);

			try {
				$source = $this->getContainer()->getPartialObject($path1);
				$source->copy($this->bucket . '/' . $path2);
			} catch (ClientErrorResponseException $e) {
				\OCP\Util::writeLog('files_hubic', $e->getMessage(), \OCP\Util::ERROR);
				return false;
			}

		} else if ($fileType === 'dir') {

			// make way
			$this->unlink($path2);

			try {
				$source = $this->getContainer()->getPartialObject($path1);
				$source->copy($this->bucket . '/' . $path2);
			} catch (ClientErrorResponseException $e) {
				\OCP\Util::writeLog('files_hubic', $e->getMessage(), \OCP\Util::ERROR);
				return false;
			}

			$dh = $this->opendir($path1);
			while ($file = readdir($dh)) {
				if ($file === '.' || $file === '..') {
					continue;
				}

				$source = $path1 . '/' . $file;
				$target = $path2 . '/' . $file;
				$this->copy($source, $target);
			}

		} else {
			//file does not exist
			return false;
		}

		return true;
	}

	public function rename($path1, $path2) {
		$path1 = $this->normalizePath($path1);
		$path2 = $this->normalizePath($path2);

		$fileType = $this->filetype($path1);

		if ($fileType === 'dir' || $fileType === 'file') {

			// make way
			$this->unlink($path2);

			// copy
			if ($this->copy($path1, $path2) === false) {
				return false;
			}

			// cleanup
			if ($this->unlink($path1) === false) {
				$this->unlink($path2);
				return false;
			}

			return true;
		}

		return false;
	}

	public function getId() {
		return $this->id;
	}

	/**
	 * Returns the connection
	 *
	 * @return OpenCloud\ObjectStore\Service connected client
	 * @throws \Exception if connection could not be made
	 */
	public function getConnection() {
		if (!is_null($this->connection)) {
			return $this->connection;
		}

		$this->anchor = new OpenStack('https://api.hubic.com/');
		
		if ($this->params['swift_token'] == 'false') {
			$this->retreiveCredentials();
		}
		$this->importCredentials();
		$this->connection = $this->anchor->objectStoreService('cloudFiles', 'NCE');

		return $this->connection;
	}

	/**
	 * Returns the initialized object store container.
	 *
	 * @return OpenCloud\ObjectStore\Resource\Container
	 */
	public function getContainer() {
		if (!is_null($this->container)) {
			return $this->container;
		}

		try {
			$this->container = $this->getConnection()->getContainer($this->bucket);
		} catch (ClientErrorResponseException $e) {
			$errorCode = $e->getResponse()->getStatusCode();
                        switch ($errorCode) {
				case 401:
					$this->retreiveCredentials();
					try {
						$this->container = $this->getConnection()->getContainer($this->bucket);
					} catch (ClientErrorResponseException $e) {
						$this->container = $this->getConnection()->createContainer($this->bucket);
					}
					break;
				case 404:
					$this->container = $this->getConnection()->createContainer($this->bucket);
					break;
				default:
					\OCP\Util::writeLog('hubic', "Unknown error code $errorCode", \OCP\Util::INFO);
					break;
			}
			
		}

		if (!$this->file_exists('.')) {
			$this->mkdir('.');
		}
		return $this->container;
	}

	public function writeBack($tmpFile) {
		if (!isset(self::$tmpFiles[$tmpFile])) {
			return false;
		}
		$fileData = fopen($tmpFile, 'r');
		$this->getContainer()->uploadObject(self::$tmpFiles[$tmpFile], $fileData);
		unlink($tmpFile);
	}

	/**
	 * check if curl is installed
	 */
	public static function checkDependencies() {
		if (function_exists('curl_init')) {
			return true;
		} else {
			return array('curl');
		}
	}

}
