<?php
/**
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 * @author Björn Schießle <bjoern@schiessle.org>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\FederatedFileSharing;

use OC\OCS\Result;
use OCA\Files_Sharing\Activity;
use OCP\AppFramework\Http;
use OCP\Constants;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\Share;
use OCP\Share\IShare;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * Class RequestHandler
 *
 * handles OCS Request to the federated share API
 *
 * @package OCA\FederatedFileSharing\API
 */
class RequestHandler {

	/** @var FederatedShareProvider */
	private $federatedShareProvider;

	/** @var IDBConnection */
	private $connection;

	/** @var Share\IManager */
	private $shareManager;

	/** @var IRequest */
	private $request;

	/** @var Notifications */
	private $notifications;

	/** @var AddressHandler */
	private $addressHandler;

	/** @var  FedShareManager */
	private $fedShareManager;

	/** @var EventDispatcherInterface  */
	private $eventDispatcher;

	/** @var string */
	private $shareTable = 'share';

	/**
	 * Server2Server constructor.
	 *
	 * @param FederatedShareProvider $federatedShareProvider
	 * @param IDBConnection $connection
	 * @param Share\IManager $shareManager
	 * @param IRequest $request
	 * @param Notifications $notifications
	 * @param AddressHandler $addressHandler
	 * @param FedShareManager $fedShareManager
	 * @param EventDispatcherInterface $eventDispatcher
	 */
	public function __construct(FederatedShareProvider $federatedShareProvider,
								IDBConnection $connection,
								Share\IManager $shareManager,
								IRequest $request,
								Notifications $notifications,
								AddressHandler $addressHandler,
								FedShareManager $fedShareManager,
								EventDispatcherInterface $eventDispatcher
	) {
		$this->federatedShareProvider = $federatedShareProvider;
		$this->connection = $connection;
		$this->shareManager = $shareManager;
		$this->request = $request;
		$this->notifications = $notifications;
		$this->addressHandler = $addressHandler;
		$this->fedShareManager = $fedShareManager;
		$this->eventDispatcher = $eventDispatcher;
	}

	/**
	 * create a new share
	 *
	 * @param array $params
	 * @return Result
	 */
	public function createShare($params) {
		if (!$this->isS2SEnabled(true)) {
			return new Result(null, 503, 'Server does not support federated cloud sharing');
		}

		$remote = isset($_POST['remote']) ? $_POST['remote'] : null;
		$token = isset($_POST['token']) ? $_POST['token'] : null;
		$name = isset($_POST['name']) ? $_POST['name'] : null;
		$owner = isset($_POST['owner']) ? $_POST['owner'] : null;
		$sharedBy = isset($_POST['sharedBy']) ? $_POST['sharedBy'] : null;
		$shareWith = isset($_POST['shareWith']) ? $_POST['shareWith'] : null;
		$remoteId = isset($_POST['remoteId']) ? (int)$_POST['remoteId'] : null;
		$sharedByFederatedId = isset($_POST['sharedByFederatedId']) ? $_POST['sharedByFederatedId'] : null;
		$ownerFederatedId = isset($_POST['ownerFederatedId']) ? $_POST['ownerFederatedId'] : null;

		if ($remote && $token && $name && $owner && $remoteId && $shareWith) {
			if (!\OCP\Util::isValidFileName($name)) {
				return new Result(null, 400, 'The mountpoint name contains invalid characters.');
			}

			// FIXME this should be a method in the user management instead
			\OCP\Util::writeLog('files_sharing', 'shareWith before, ' . $shareWith, \OCP\Util::DEBUG);
			\OCP\Util::emitHook(
				'\OCA\Files_Sharing\API\Server2Server',
				'preLoginNameUsedAsUserName',
				['uid' => &$shareWith]
			);
			\OCP\Util::writeLog('files_sharing', 'shareWith after, ' . $shareWith, \OCP\Util::DEBUG);

			if (!\OCP\User::userExists($shareWith)) {
				return new Result(null, 400, 'User does not exist');
			}

			\OC_Util::setupFS($shareWith);

			$discoveryManager = new DiscoveryManager(
				\OC::$server->getMemCacheFactory(),
				\OC::$server->getHTTPClientService()
			);
			$externalManager = new \OCA\Files_Sharing\External\Manager(
					\OC::$server->getDatabaseConnection(),
					\OC\Files\Filesystem::getMountManager(),
					\OC\Files\Filesystem::getLoader(),
					\OC::$server->getNotificationManager(),
					\OC::$server->getEventDispatcher(),
					$shareWith
				);

			try {
				$externalManager->addShare($remote, $token, '', $name, $owner, false, $shareWith, $remoteId);
				$shareId = \OC::$server->getDatabaseConnection()->lastInsertId('*PREFIX*share_external');

				if ($ownerFederatedId === null) {
					$ownerFederatedId = $owner . '@' . $this->cleanupRemote($remote);
				}
				// if the owner of the share and the initiator are the same user
				// we also complete the federated share ID for the initiator
				if ($sharedByFederatedId === null && $owner === $sharedBy) {
					$sharedByFederatedId = $ownerFederatedId;
				}

				$event = new GenericEvent(null, ['name' => $name, 'targetuser' => $sharedByFederatedId,
								'owner' => $owner, 'sharewith' => $shareWith,
								'sharedby' => $sharedBy, 'remoteid' => $remoteId]);
				$this->eventDispatcher->dispatch('\OCA\FederatedFileSharing::remote_shareReceived', $event);
				\OC::$server->getActivityManager()->publishActivity(
					Activity::FILES_SHARING_APP, Activity::SUBJECT_REMOTE_SHARE_RECEIVED, [$ownerFederatedId, \trim($name, '/')], '', [],
					'', '', $shareWith, Activity::TYPE_REMOTE_SHARE, Activity::PRIORITY_LOW);

				$urlGenerator = \OC::$server->getURLGenerator();

				$notificationManager = \OC::$server->getNotificationManager();
				$notification = $notificationManager->createNotification();
				$notification->setApp('files_sharing')
					->setUser($shareWith)
					->setDateTime(new \DateTime())
					->setObject('remote_share', $shareId)
					->setSubject('remote_share', [$ownerFederatedId, $sharedByFederatedId, \trim($name, '/')])
					->setMessage('remote_share', [$ownerFederatedId, $sharedByFederatedId, \trim($name, '/')]);

				$declineAction = $notification->createAction();
				$declineAction->setLabel('decline')
					->setLink($urlGenerator->getAbsoluteURL($urlGenerator->linkTo('', 'ocs/v1.php/apps/files_sharing/api/v1/remote_shares/pending/' . $shareId)), 'DELETE');
				$notification->addAction($declineAction);

				$acceptAction = $notification->createAction();
				$acceptAction->setLabel('accept')
					->setLink($urlGenerator->getAbsoluteURL($urlGenerator->linkTo('', 'ocs/v1.php/apps/files_sharing/api/v1/remote_shares/pending/' . $shareId)), 'POST');
				$notification->addAction($acceptAction);

				$notificationManager->notify($notification);

				return new Result();
			} catch (\Exception $e) {
				\OCP\Util::writeLog('files_sharing', 'server can not add remote share, ' . $e->getMessage(), \OCP\Util::ERROR);
				return new Result(null, 500, 'internal server error, was not able to add share from ' . $remote);
			}
		}

		return new Result(null, 400, 'server can not add remote share, missing parameter');
	}

	/**
	 * create re-share on behalf of another user
	 *
	 * @param $params
	 * @return Result
	 */
	public function reShare($params) {
		$id = isset($params['id']) ? (int)$params['id'] : null;
		$token = $this->request->getParam('token', null);
		$shareWith = $this->request->getParam('shareWith', null);
		$permission = (int)$this->request->getParam('permission', null);
		$remoteId = (int)$this->request->getParam('remoteId', null);

		if ($id === null ||
			$token === null ||
			$shareWith === null ||
			$permission === null ||
			$remoteId === null
		) {
			return new Result(null, Http::STATUS_BAD_REQUEST);
		}

		try {
			$share = $this->federatedShareProvider->getShareById($id);
		} catch (Share\Exceptions\ShareNotFound $e) {
			return new Result(null, Http::STATUS_NOT_FOUND);
		}

		// don't allow to share a file back to the owner
		list($user, $remote) = $this->addressHandler->splitUserRemote($shareWith);
		$owner = $share->getShareOwner();
		$currentServer = $this->addressHandler->generateRemoteURL();
		if ($this->addressHandler->compareAddresses($user, $remote, $owner, $currentServer)) {
			return new Result(null, Http::STATUS_FORBIDDEN);
		}

		if ($this->verifyShare($share, $token)) {

			// check if re-sharing is allowed
			if ($share->getPermissions() | ~Constants::PERMISSION_SHARE) {
				$share->setPermissions($share->getPermissions() & $permission);
				// the recipient of the initial share is now the initiator for the re-share
				$share->setSharedBy($share->getSharedWith());
				$share->setSharedWith($shareWith);
				try {
					$result = $this->federatedShareProvider->create($share);
					$this->federatedShareProvider->storeRemoteId((int)$result->getId(), $remoteId);
					return new Result(['token' => $result->getToken(), 'remoteId' => $result->getId()]);
				} catch (\Exception $e) {
					return new Result(null, Http::STATUS_BAD_REQUEST);
				}
			} else {
				return new Result(null, Http::STATUS_FORBIDDEN);
			}
		}
		return new Result(null, Http::STATUS_BAD_REQUEST);
	}

	/**
	 * accept server-to-server share
	 *
	 * @param array $params
	 * @return Result
	 */
	public function acceptShare($params) {
		if (!$this->isS2SEnabled()) {
			return new Result(null, 503, 'Server does not support federated cloud sharing');
		}

		$id = $params['id'];
		$token = isset($_POST['token']) ? $_POST['token'] : null;

		try {
			$share = $this->federatedShareProvider->getShareById($id);
		} catch (Share\Exceptions\ShareNotFound $e) {
			return new Result();
		}

		if ($this->verifyShare($share, $token)) {
			$this->fedShareManager->acceptShare($share);
			if ($share->getShareOwner() !== $share->getSharedBy()) {
				list(, $remote) = $this->addressHandler->splitUserRemote($share->getSharedBy());
				$remoteId = $this->federatedShareProvider->getRemoteId($share);
				$this->notifications->sendAcceptShare($remote, $remoteId, $share->getToken());
			}
		}

		return new Result();
	}

	/**
	 * decline server-to-server share
	 *
	 * @param array $params
	 * @return Result
	 */
	public function declineShare($params) {
		if (!$this->isS2SEnabled()) {
			return new Result(null, 503, 'Server does not support federated cloud sharing');
		}

		$id = (int)$params['id'];
		$token = isset($_POST['token']) ? $_POST['token'] : null;

		try {
			$share = $this->federatedShareProvider->getShareById($id);
		} catch (Share\Exceptions\ShareNotFound $e) {
			return new Result();
		}

		if ($this->verifyShare($share, $token)) {
			if ($share->getShareOwner() !== $share->getSharedBy()) {
				list(, $remote) = $this->addressHandler->splitUserRemote($share->getSharedBy());
				$remoteId = $this->federatedShareProvider->getRemoteId($share);
				$this->notifications->sendDeclineShare($remote, $remoteId, $share->getToken());
			}
			$this->fedShareManager->declineShare($share);
		}

		return new Result();
	}

	/**
	 * remove server-to-server share if it was unshared by the owner
	 *
	 * @param array $params
	 * @return Result
	 */
	public function unshare($params) {
		if (!$this->isS2SEnabled()) {
			return new Result(null, 503, 'Server does not support federated cloud sharing');
		}

		$id = $params['id'];
		$token = isset($_POST['token']) ? $_POST['token'] : null;

		$query = \OCP\DB::prepare('SELECT * FROM `*PREFIX*share_external` WHERE `remote_id` = ? AND `share_token` = ?');
		$query->execute([$id, $token]);
		$share = $query->fetchRow();

		if ($token && $id && !empty($share)) {
			$remote = $this->cleanupRemote($share['remote']);

			$owner = $share['owner'] . '@' . $remote;
			$mountpoint = $share['mountpoint'];
			$user = $share['user'];

			$query = \OCP\DB::prepare('DELETE FROM `*PREFIX*share_external` WHERE `remote_id` = ? AND `share_token` = ?');
			$query->execute([$id, $token]);

			if ($share['accepted']) {
				$path = \trim($mountpoint, '/');
			} else {
				$path = \trim($share['name'], '/');
			}

			$notificationManager = \OC::$server->getNotificationManager();
			$notification = $notificationManager->createNotification();
			$notification->setApp('files_sharing')
				->setUser($share['user'])
				->setObject('remote_share', (int) $share['id']);
			$notificationManager->markProcessed($notification);

			\OC::$server->getActivityManager()->publishActivity(
				Activity::FILES_SHARING_APP, Activity::SUBJECT_REMOTE_SHARE_UNSHARED, [$owner, $path], '', [],
				'', '', $user, Activity::TYPE_REMOTE_SHARE, Activity::PRIORITY_MEDIUM);
		}

		return new Result();
	}

	private function cleanupRemote($remote) {
		$remote = \substr($remote, \strpos($remote, '://') + 3);

		return \rtrim($remote, '/');
	}

	/**
	 * federated share was revoked, either by the owner or the re-sharer
	 *
	 * @param $params
	 * @return Result
	 */
	public function revoke($params) {
		$id = (int)$params['id'];
		$token = $this->request->getParam('token');
		
		$share = $this->federatedShareProvider->getShareById($id);
		
		if ($this->verifyShare($share, $token)) {
			$this->federatedShareProvider->removeShareFromTable($share);
			return new Result();
		}

		return new Result(null, Http::STATUS_BAD_REQUEST);
	}
	
	/**
	 * get share
	 *
	 * @param int $id
	 * @param string $token
	 * @return array|bool
	 */
	protected function getShare($id, $token) {
		$query = $this->connection->getQueryBuilder();
		$query->select('*')->from($this->shareTable)
			->where($query->expr()->eq('token', $query->createNamedParameter($token)))
			->andWhere($query->expr()->eq('share_type', $query->createNamedParameter(FederatedShareProvider::SHARE_TYPE_REMOTE)))
			->andWhere($query->expr()->eq('id', $query->createNamedParameter($id)));

		$result = $query->execute()->fetchAll();

		if (!empty($result) && isset($result[0])) {
			return $result[0];
		}

		return false;
	}

	/**
	 * check if server-to-server sharing is enabled
	 *
	 * @param bool $incoming
	 * @return bool
	 */
	private function isS2SEnabled($incoming = false) {
		$result = \OCP\App::isEnabled('files_sharing');

		if ($incoming) {
			$result = $result && $this->federatedShareProvider->isIncomingServer2serverShareEnabled();
		} else {
			$result = $result && $this->federatedShareProvider->isOutgoingServer2serverShareEnabled();
		}

		return $result;
	}

	/**
	 * check if we got the right share
	 *
	 * @param Share\IShare $share
	 * @param string $token
	 * @return bool
	 */
	protected function verifyShare(Share\IShare $share, $token) {
		if (
			$share->getShareType() === FederatedShareProvider::SHARE_TYPE_REMOTE &&
			$share->getToken() === $token
		) {
			return true;
		}

		return false;
	}

	/**
	 * update share information to keep federated re-shares in sync
	 *
	 * @param array $params
	 * @return Result
	 */
	public function updatePermissions($params) {
		$id = (int)$params['id'];
		$token = $this->request->getParam('token', null);
		$permissions = $this->request->getParam('permissions', null);

		try {
			$share = $this->federatedShareProvider->getShareById($id);
		} catch (Share\Exceptions\ShareNotFound $e) {
			return new Result(null, Http::STATUS_BAD_REQUEST);
		}

		$validPermission = \ctype_digit($permissions);
		$validToken = $this->verifyShare($share, $token);
		if ($validPermission && $validToken) {
			$this->updatePermissionsInDatabase($share, (int)$permissions);
		} else {
			return new Result(null, Http::STATUS_BAD_REQUEST);
		}

		return new Result();
	}

	/**
	 * update permissions in database
	 *
	 * @param IShare $share
	 * @param int $permissions
	 */
	protected function updatePermissionsInDatabase(IShare $share, $permissions) {
		$query = $this->connection->getQueryBuilder();
		$query->update('share')
			->where($query->expr()->eq('id', $query->createNamedParameter($share->getId())))
			->set('permissions', $query->createNamedParameter($permissions))
			->execute();
	}
}
